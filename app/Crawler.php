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
        $sources = $pdo->query(
            'SELECT id, slug FROM sources s WHERE s.is_enabled = 1 AND ' . Inventory::panelVisibleSlugSql('s') . ' ORDER BY id'
        )->fetchAll();
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

    /**
     * Stored MySQL inventory for a source (shared grouped query — not per-row N+1).
     * @return array{listings:int,unique_sims:int,with_contact:int,phone_records:int}
     */
    private function storedInventoryForSource(int $sourceId): array
    {
        $st = Inventory::sourceStats($sourceId);
        return [
            'listings' => (int) ($st['listings'] ?? 0),
            'unique_sims' => (int) ($st['unique_sims'] ?? 0),
            'with_contact' => (int) ($st['with_contact'] ?? 0),
            'phone_records' => (int) ($st['phone_records'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function progressSnapshot(string $slug): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, slug, name, is_enabled, last_success_at, last_error, last_offers_found FROM sources WHERE slug = ?');
        $stmt->execute([$slug]);
        $src = $stmt->fetch() ?: ['slug' => $slug, 'name' => $slug];
        $pstmt = $pdo->prepare('SELECT * FROM crawl_passes WHERE source_id = (SELECT id FROM sources WHERE slug = ? LIMIT 1)');
        $pstmt->execute([$slug]);
        $pass = $pstmt->fetch();
        $adapter = Registry::get($slug);
        $seedCount = $adapter ? count($adapter->seedUrls()) : 0;
        $sourceId = (int) ($src['id'] ?? 0);
        $stored = $sourceId > 0
            ? $this->storedInventoryForSource($sourceId)
            : ['listings' => 0, 'unique_sims' => 0, 'with_contact' => 0, 'phone_records' => 0];

        $inventoryFields = [
            // Clear names for UI
            'db_stored_offers' => $stored['listings'],
            'db_unique_sims' => $stored['unique_sims'],
            'db_offers_with_contact' => $stored['with_contact'],
            'db_phone_records' => $stored['phone_records'],
            // Backward-compatible aliases (db_unique_offers was always DISTINCT sim_number_id)
            'db_unique_offers' => $stored['unique_sims'],
            'contact_offers' => $stored['with_contact'],
        ];

        if (empty($src['is_enabled']) || Inventory::isHiddenFromPanel($slug)) {
            return $this->withUserCopy(array_merge([
                'slug' => $slug,
                'name' => $src['name'] ?? $slug,
                'status' => 'disabled',
                'offers_found' => 0,
                'unique_offers_pass' => 0,
                'inserted_pass' => 0,
                'updated_pass' => 0,
                'distinct_sims_pass' => 0,
                'pages_fetched' => 0,
                'last_batch_offers' => 0,
                'last_batch_at_fa' => '—',
                'progress_label' => 'منبع غیرفعال است',
                'last_error' => $src['last_error'] ?? null,
                'last_success_at' => $src['last_success_at'] ?? null,
                'last_success_at_fa' => Jalali::formatDateTime($src['last_success_at'] ?? null),
                'next_retry_at_fa' => '—',
                'coverage_scope' => '',
                'inventory_path' => false,
                'stop_reason' => null,
                'source_total_elements' => null,
                'source_total_pages' => null,
            ], $inventoryFields));
        }
        if (!$pass) {
            return $this->withUserCopy(array_merge([
                'slug' => $slug,
                'name' => $src['name'] ?? $slug,
                'status' => 'idle',
                'seed_index' => 0,
                'page_index' => 0,
                'seed_total' => $seedCount,
                'offers_found' => 0,
                'unique_offers_pass' => 0,
                'inserted_pass' => 0,
                'updated_pass' => 0,
                'distinct_sims_pass' => 0,
                'pages_fetched' => 0,
                'coverage_scope' => '',
                'coverage_complete' => false,
                'coverage_label' => '',
                'stop_reason' => null,
                'inventory_path' => false,
                'source_total_elements' => null,
                'source_total_pages' => null,
                'last_batch_offers' => 0,
                'last_batch_at' => null,
                'last_batch_at_fa' => '—',
                'progress_label' => 'هنوز شروع نشده',
                'last_error' => $src['last_error'] ?? null,
                'next_retry_at_fa' => '—',
                'updated_at' => null,
                'updated_at_fa' => '—',
                'last_success_at' => $src['last_success_at'] ?? null,
                'last_success_at_fa' => Jalali::formatDateTime($src['last_success_at'] ?? null),
            ], $inventoryFields));
        }

        $status = (string) $pass['status'];

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

        $seenSims = is_array($state['seen_sim_numbers'] ?? null) ? $state['seen_sim_numbers'] : [];
        if (isset($seenSims['_sidecar'], $seenSims['count'])) {
            $distinctSimsPass = (int) $seenSims['count'];
        } else {
            $distinctSimsPass = count($seenSims);
        }

        return $this->withUserCopy(array_merge([
            'slug' => $slug,
            'name' => $src['name'] ?? $slug,
            'status' => $status,
            'seed_index' => (int) $pass['seed_index'],
            'page_index' => (int) $pass['page_index'],
            'seed_total' => $seedCount,
            'offers_found' => $uniqueOffers,
            'unique_offers_pass' => $uniqueOffers,
            'inserted_pass' => (int) ($state['incremental_inserted'] ?? 0),
            'updated_pass' => (int) ($state['incremental_updated'] ?? 0),
            'pages_fetched' => (int) $pass['pages_fetched'],
            'source_total_elements' => isset($state['source_total_elements']) ? (int) $state['source_total_elements'] : null,
            'source_total_pages' => isset($state['source_total_pages']) ? (int) $state['source_total_pages'] : null,
            'coverage_scope' => (string) ($coverage['scope'] ?? ''),
            'coverage_complete' => $status === 'completed' && !empty($coverage['is_complete_inventory']) && empty($state['inventory_changed_during_crawl']) && $uniqueOffers >= (int) ($state['source_total_elements'] ?? 0),
            'inventory_path' => !empty($coverage['is_complete_inventory']),
            'distinct_sims_pass' => $distinctSimsPass,
            'source_rows' => (int) ($state['source_records'] ?? 0),
            'excluded_rows' => (int) ($state['excluded_rows'] ?? 0),
            'duplicate_rows' => (int) ($state['duplicate_rows'] ?? 0),
            'total_observed_at_fa' => Jalali::formatDateTime($state['total_observed_at'] ?? null),
            'inventory_changed' => !empty($state['inventory_changed_during_crawl']),
            'next_retry_at_fa' => Jalali::formatDateTime(($retryAt = max(array_column($state['branches'] ?? [], 'retry_at') ?: [0])) ? gmdate('Y-m-d H:i:s', (int) $retryAt) : null),
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
            'last_success_at' => $src['last_success_at'] ?? null,
            'last_success_at_fa' => Jalali::formatDateTime($src['last_success_at'] ?? null),
        ], $inventoryFields));
    }

    /**
     * Attach plain-language Persian status fields for the Sources panel / JSON pollers.
     * @param array<string,mixed> $snap
     * @return array<string,mixed>
     */
    private function withUserCopy(array $snap): array
    {
        $copy = CrawlStatusCopy::enrich($snap);
        // Keep raw stop_reason for technical details; expose stop_reason_raw explicitly too.
        $snap['stop_reason_raw'] = $copy['stop_reason_raw'];
        return array_merge($snap, $copy);
    }
}

