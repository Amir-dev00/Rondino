<?php

declare(strict_types=1);

/**
 * Facade used by HTTP refresh API and CLI worker.
 * Local/default: concurrent seeds via CrawlEngine.
 * cPanel: same engine with smaller page budget from config.
 */
final class Crawler
{
    private int $pageBudget;
    private CrawlEngine $engine;

    public function __construct(?int $pageBudget = null)
    {
        $cfg = (int) config('crawl_page_budget', 4);
        $this->pageBudget = max(1, $pageBudget ?? ($cfg > 0 ? $cfg : 4));
        $this->engine = new CrawlEngine();
    }

    /**
     * @return array<string,mixed>
     */
    public function runBatch(string $slug, bool $restart = false): array
    {
        return $this->engine->advanceSource($slug, $restart, $this->pageBudget);
    }

    /**
     * @return array<string,mixed>
     */
    public function runRotatedBatch(bool $startFresh = false): array
    {
        $pdo = Database::pdo();
        $sources = $pdo->query('SELECT id, slug FROM sources WHERE is_enabled = 1 ORDER BY id')->fetchAll();
        if ($sources === []) {
            return ['done' => true, 'message' => 'منبعی نیست.', 'all_progress' => []];
        }

        // Prefer incomplete; optionally restart completed when startFresh
        $candidates = [];
        foreach ($sources as $s) {
            $slug = $s['slug'];
            $snap = $this->progressSnapshot($slug);
            $st = $snap['status'] ?? 'idle';
            if (in_array($st, ['paused', 'running', 'idle'], true)) {
                $candidates[] = ['slug' => $slug, 'restart' => $st === 'idle', 'priority' => 0, 'updated' => (string) ($snap['updated_at'] ?? '')];
            } elseif ($startFresh && in_array($st, ['completed', 'blocked'], true)) {
                $candidates[] = ['slug' => $slug, 'restart' => true, 'priority' => 1, 'updated' => (string) ($snap['updated_at'] ?? '')];
            }
        }
        if ($candidates === []) {
            return ['done' => true, 'message' => 'همه گذرهای فعال تمام یا مسدود شده‌اند.', 'all_progress' => $this->allProgress()];
        }
        usort($candidates, static function ($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            return strcmp($a['updated'], $b['updated']);
        });

        // Local HTTP path: advance several sources in one request when concurrency allows
        $wave = max(1, min(4, (int) config('crawl_rotate_wave', 2)));
        $picked = array_slice($candidates, 0, $wave);
        $last = null;
        foreach ($picked as $pick) {
            $last = $this->engine->advanceSource($pick['slug'], (bool) $pick['restart'], $this->pageBudget);
        }
        $still = 0;
        foreach ($this->allProgress() as $p) {
            if (in_array($p['status'], ['idle', 'paused', 'running'], true)) {
                $still++;
            }
        }
        $result = $last ?? ['ok' => true];
        $result['rotate'] = true;
        $result['done'] = $still === 0;
        $result['all_progress'] = $this->allProgress();
        if ($result['done']) {
            $result['message'] = 'همه منابع این دور بررسی شدند.';
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function allProgress(): array
    {
        $out = [];
        foreach (Registry::all() as $slug => $_adapter) {
            $out[] = $this->progressSnapshot($slug);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function progressSnapshot(string $slug): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, slug, name, last_success_at, last_error, last_offers_found FROM sources WHERE slug = ?');
        $stmt->execute([$slug]);
        $src = $stmt->fetch() ?: ['slug' => $slug, 'name' => $slug];
        $pstmt = $pdo->prepare('SELECT * FROM crawl_passes WHERE source_id = (SELECT id FROM sources WHERE slug = ? LIMIT 1)');
        $pstmt->execute([$slug]);
        $pass = $pstmt->fetch();
        $adapter = Registry::get($slug);
        $seedCount = $adapter ? count($adapter->seedUrls()) : 0;

        if (!$pass) {
            return [
                'slug' => $slug,
                'name' => $src['name'] ?? $slug,
                'status' => 'idle',
                'status_fa' => 'آماده',
                'seed_index' => 0,
                'page_index' => 0,
                'seed_total' => $seedCount,
                'offers_found' => 0,
                'unique_offers_pass' => 0,
                'db_unique_offers' => 0,
                'pages_fetched' => 0,
                'coverage_scope' => '',
                'coverage_complete' => false,
                'coverage_label' => '',
                'stop_reason' => null,
                'last_batch_offers' => 0,
                'last_batch_at' => null,
                'last_batch_at_fa' => '—',
                'progress_label' => 'هنوز شروع نشده',
                'last_error' => $src['last_error'] ?? null,
                'updated_at' => null,
                'updated_at_fa' => '—',
            ];
        }

        $status = (string) $pass['status'];
        $statusFa = match ($status) {
            'running' => 'در حال اجرا',
            'paused' => 'متوقف موقت',
            'completed' => 'کامل',
            'blocked' => 'مسدود',
            default => $status,
        };

        $branchErrors = [];
        if (!empty($pass['branch_errors'])) {
            $decoded = json_decode((string) $pass['branch_errors'], true);
            $branchErrors = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [(string) $pass['branch_errors']];
        }

        $state = [];
        if (!empty($pass['branch_state'])) {
            $decoded = json_decode((string) $pass['branch_state'], true);
            $state = is_array($decoded) ? $decoded : [];
        }
        $coverage = is_array($state['coverage'] ?? null) ? $state['coverage'] : [];
        $uniqueOffers = (int) ($state['unique_offers'] ?? $pass['offers_found'] ?? 0);
        $dbUnique = 0;
        $sourceId = (int) ($src['id'] ?? 0);
        if ($sourceId > 0) {
            $cstmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT sim_number_id) FROM offers WHERE source_id = ?'
            );
            $cstmt->execute([$sourceId]);
            $dbUnique = (int) $cstmt->fetchColumn();
        }

        return [
            'slug' => $slug,
            'name' => $src['name'] ?? $slug,
            'status' => $status,
            'status_fa' => $statusFa,
            'seed_index' => (int) $pass['seed_index'],
            'page_index' => (int) $pass['page_index'],
            'seed_total' => $seedCount,
            'offers_found' => $uniqueOffers,
            'unique_offers_pass' => $uniqueOffers,
            'db_unique_offers' => $dbUnique,
            'pages_fetched' => (int) $pass['pages_fetched'],
            'source_total_elements' => isset($state['source_total_elements']) ? (int) $state['source_total_elements'] : null,
            'source_total_pages' => isset($state['source_total_pages']) ? (int) $state['source_total_pages'] : null,
            'coverage_scope' => (string) ($coverage['scope'] ?? ''),
            'coverage_complete' => !empty($coverage['is_complete_inventory']),
            'coverage_label' => (string) ($coverage['label'] ?? ''),
            'stop_reason' => $state['stop_reason'] ?? null,
            'last_batch_offers' => (int) $pass['last_batch_offers'],
            'last_batch_at' => $pass['last_batch_at'],
            'last_batch_at_fa' => Jalali::formatDateTime($pass['last_batch_at'] ?? null),
            'progress_label' => $pass['progress_label'],
            'last_error' => $pass['last_error'] ?: ($src['last_error'] ?? null),
            'branch_errors' => $branchErrors,
            'updated_at' => $pass['updated_at'],
            'updated_at_fa' => Jalali::formatDateTime($pass['updated_at'] ?? null),
            'current_url' => $pass['current_url'],
        ];
    }
}
