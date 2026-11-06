<?php

declare(strict_types=1);

/**
 * Shared crawl logic for HTTP-batch (cPanel) and local CLI worker.
 * Independent seeds/domains are fetched concurrently; pagination stays ordered per seed.
 */
final class CrawlEngine
{
    /**
     * Advance one or more ready branches for a source (up to $maxFetches concurrent).
     *
     * @return array<string,mixed>
     */
    public function advanceSource(string $slug, bool $restart = false, int $maxFetches = 0): array
    {
        $adapter = Registry::get($slug);
        if (!$adapter) {
            return ['ok' => false, 'message' => 'منبع یافت نشد.', 'slug' => $slug];
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM sources WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $source = $stmt->fetch();
        if (!$source) {
            return ['ok' => false, 'message' => 'منبع در پایگاه نیست.', 'slug' => $slug];
        }
        $sourceId = (int) $source['id'];
        $now = now_dt();
        $pdo->prepare('UPDATE sources SET last_attempt_at = ? WHERE id = ?')->execute([$now, $sourceId]);

        $crawler = new Crawler(); // reuse lock/pass helpers via public methods below
        $pass = $this->acquire($pdo, $sourceId, $restart, $adapter, $now);
        if ($pass === null) {
            return [
                'ok' => true,
                'busy' => true,
                'slug' => $slug,
                'pass_status' => 'running',
                'offers_found' => 0,
                'upserted' => 0,
                'message' => 'این منبع هم‌اکنون قفل است.',
                'progress' => $crawler->progressSnapshot($slug),
            ];
        }

        if (in_array($pass['status'], ['completed', 'blocked'], true) && !$restart) {
            $this->release($pdo, (int) $pass['id']);
            return [
                'ok' => $pass['status'] === 'completed',
                'busy' => false,
                'slug' => $slug,
                'pass_status' => $pass['status'],
                'offers_found' => (int) $pass['offers_found'],
                'upserted' => 0,
                'message' => 'گذر قبلاً تمام شده.',
                'progress' => $crawler->progressSnapshot($slug),
            ];
        }

        $seeds = array_values($adapter->seedUrls());
        if ($seeds === []) {
            $this->markFinished($pdo, $pass, 'blocked', 'بدون seed', $now);
            return ['ok' => false, 'slug' => $slug, 'pass_status' => 'blocked', 'message' => 'بدون seed', 'progress' => $crawler->progressSnapshot($slug)];
        }

        $state = $this->decodeState($pass['branch_state'] ?? null, $seeds);
        if ($restart || empty($pass['branch_state'])) {
            $state = $this->freshState($seeds);
        }

        $maxFetches = $maxFetches > 0 ? $maxFetches : max(1, (int) config('crawl_page_budget', 4));
        $timeout = (int) config('http_timeout', 25);
        $ready = [];
        foreach ($state['branches'] as $i => $b) {
            if (($b['status'] ?? '') === 'pending' || ($b['status'] ?? '') === 'active') {
                $url = (string) ($b['url'] ?: $seeds[$i]);
                // Deduplicate same URL across seeds in this source
                if (isset($state['seen_urls'][$url]) && ($b['page'] ?? 1) === 1 && ($b['status'] ?? '') === 'pending') {
                    $state['branches'][$i]['status'] = 'done';
                    $state['branches'][$i]['note'] = 'duplicate seed url skipped';
                    CrawlMetrics::$detailAvoided++;
                    continue;
                }
                $ready[] = ['i' => $i, 'url' => $url];
                if (count($ready) >= $maxFetches) {
                    break;
                }
            }
        }

        if ($ready === []) {
            $allDone = true;
            $anyOk = false;
            $errors = [];
            foreach ($state['branches'] as $b) {
                if (($b['status'] ?? '') === 'failed') {
                    $errors[] = (string) ($b['error'] ?? 'failed');
                } elseif (($b['status'] ?? '') === 'done') {
                    $anyOk = true;
                } else {
                    $allDone = false;
                }
            }
            if ($allDone) {
                $offersFound = (int) ($state['offers_found'] ?? $pass['offers_found'] ?? 0);
                $pagesFetched = (int) ($state['pages_fetched'] ?? $pass['pages_fetched'] ?? 0);
                // Verified inventory (done branch or stored offers) must not be wiped by a later page failure.
                $status = ($anyOk || $offersFound > 0) ? 'completed' : (($errors !== [] || $pagesFetched === 0) ? 'blocked' : 'completed');
                if ($offersFound === 0 && $pagesFetched === 0 && $errors !== []) {
                    $status = 'blocked';
                }
                $msg = $status === 'blocked' ? 'همه شاخه‌ها ناموفق بودند.' : 'پایان تأییدشدهٔ همه شاخه‌ها.';
                $pass['offers_found'] = $offersFound;
                $pass['pages_fetched'] = $pagesFetched;
                $pass['branch_errors'] = $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE);
                $this->persistState($pdo, $pass, $state, $status === 'completed' || $status === 'blocked' ? $status : 'paused', $now, true);
                $this->touchSummary($pdo, $sourceId, $pass, $errors, $now, $status === 'completed');
                return [
                    'ok' => $status === 'completed',
                    'slug' => $slug,
                    'pass_status' => $status,
                    'offers_found' => (int) $pass['offers_found'],
                    'upserted' => 0,
                    'message' => $msg,
                    'progress' => $crawler->progressSnapshot($slug),
                ];
            }
        }

        $fetched = [];
        $getUrls = [];
        foreach ($ready as $item) {
            $custom = $adapter->fetchPage($item['url'], $timeout);
            if ($custom !== null) {
                $fetched[$item['url']] = $custom;
            } else {
                $getUrls[] = $item['url'];
            }
        }
        if ($getUrls !== []) {
            $fetched += Http::getMany($getUrls, $timeout, true);
        }

        $store = new OfferStore();
        $batchOffers = 0;
        $batchUpserted = 0;
        $errors = $this->collectErrors($state);

        foreach ($ready as $item) {
            $i = $item['i'];
            $url = $item['url'];
            $b = $state['branches'][$i];
            $page = (int) ($b['page'] ?? 1);
            $res = $fetched[$url] ?? ['ok' => false, 'error' => 'missing', 'body' => '', 'final_url' => $url, 'status' => 0];

            // Transient retry once for timeout/5xx
            if (!$res['ok']) {
                $err = (string) ($res['error'] ?? '');
                $transient = str_contains($err, 'timeout') || str_contains($err, 'زمان') || str_contains($err, 'HTTP 5');
                if ($transient && empty($b['retried'])) {
                    CrawlMetrics::$retries++;
                    usleep(300000);
                    $retry = $adapter->fetchPage($url, $timeout);
                    $res = $retry ?? Http::get($url, $timeout, false);
                    $state['branches'][$i]['retried'] = true;
                }
            }

            if (!$res['ok']) {
                $state['branches'][$i]['status'] = 'failed';
                $state['branches'][$i]['error'] = $url . ' => ' . ($res['error'] ?? 'خطا');
                $errors[] = $state['branches'][$i]['error'];
                continue;
            }

            $tParse = microtime(true);
            try {
                $parsed = $adapter->parseListPage($res['body'], $res['final_url'] ?: $url);
            } catch (Throwable $e) {
                $state['branches'][$i]['status'] = 'failed';
                $state['branches'][$i]['error'] = $url . ' => parse: ' . $e->getMessage();
                $errors[] = $state['branches'][$i]['error'];
                CrawlMetrics::$parseMs += (microtime(true) - $tParse) * 1000;
                continue;
            }
            CrawlMetrics::$parseMs += (microtime(true) - $tParse) * 1000;

            // No detail pages: all fields come from list HTML
            CrawlMetrics::$detailAvoided += count($parsed);

            $unique = [];
            foreach ($parsed as $row) {
                $key = (string) ($row['external_id'] ?? '');
                if ($key !== '') {
                    $unique[$key] = $row;
                }
            }
            $list = array_values($unique);
            $ids = array_map(static fn ($r) => (string) $r['external_id'], $list);
            sort($ids);
            $fp = sha1(implode('|', $ids));
            $found = count($list);

            $repeat = $page > 1 && (
                (!empty($b['last_fp']) && $fp === $b['last_fp'])
                || (!empty($b['seed_fp']) && $fp === $b['seed_fp'])
            );

            if ($repeat) {
                $state['branches'][$i]['status'] = 'done';
                $state['pages_fetched'] = (int) ($state['pages_fetched'] ?? 0) + 1;
                continue;
            }

            try {
                $result = $store->upsertMany($sourceId, $list);
            } catch (Throwable $e) {
                // Do not advance cursor past unsaved listings
                $state['branches'][$i]['status'] = 'active';
                $state['branches'][$i]['url'] = $url;
                $pass['last_error'] = mb_substr('ذخیره ناموفق: ' . $e->getMessage(), 0, 900);
                $this->persistState($pdo, $pass, $state, 'paused', $now, false);
                $this->release($pdo, (int) $pass['id']);
                return [
                    'ok' => false,
                    'slug' => $slug,
                    'pass_status' => 'paused',
                    'offers_found' => $batchOffers,
                    'upserted' => 0,
                    'message' => $pass['last_error'],
                    'progress' => $crawler->progressSnapshot($slug),
                ];
            }

            $batchOffers += $found;
            $batchUpserted += $result['upserted'];
            $state['offers_found'] = (int) ($state['offers_found'] ?? 0) + $found;
            $state['pages_fetched'] = (int) ($state['pages_fetched'] ?? 0) + 1;
            $state['seen_urls'][$url] = true;
            if ($page === 1) {
                $state['branches'][$i]['seed_fp'] = $fp;
            }
            $state['branches'][$i]['last_fp'] = $fp;
            $state['branches'][$i]['retried'] = false;

            $next = null;
            if ($found > 0) {
                $next = $adapter->nextPageUrl($res['body'], $url, $page);
            }
            if ($found === 0 || $next === null || $next === '' || $next === $url || isset($state['seen_urls'][$next])) {
                $state['branches'][$i]['status'] = 'done';
                $state['branches'][$i]['url'] = null;
            } else {
                $state['branches'][$i]['status'] = 'active';
                $state['branches'][$i]['page'] = $page + 1;
                $state['branches'][$i]['url'] = $next;
            }
        }

        // Summary fields for UI
        $activeLabel = '';
        $seedIndex = 0;
        $pageIndex = 1;
        $pendingLeft = 0;
        foreach ($state['branches'] as $idx => $b) {
            $st = $b['status'] ?? '';
            if ($st === 'active' || $st === 'pending') {
                $pendingLeft++;
                if ($activeLabel === '') {
                    $seedIndex = $idx;
                    $pageIndex = (int) ($b['page'] ?? 1);
                    $activeLabel = sprintf('شاخه %d/%d · صفحه %d', $idx + 1, count($seeds), $pageIndex);
                }
            }
        }

        $pass['offers_found'] = (int) ($state['offers_found'] ?? 0);
        $pass['pages_fetched'] = (int) ($state['pages_fetched'] ?? 0);
        $pass['last_batch_offers'] = $batchOffers;
        $pass['last_batch_at'] = $now;
        $pass['seed_index'] = $seedIndex;
        $pass['page_index'] = $pageIndex;
        $pass['branch_errors'] = $errors === [] ? null : json_encode(array_values(array_unique($errors)), JSON_UNESCAPED_UNICODE);
        $pass['last_error'] = $errors === [] ? null : mb_substr((string) end($errors), 0, 900);
        $pass['progress_label'] = $activeLabel !== '' ? ($activeLabel . ' · موج موازی') : 'در حال جمع‌بندی شاخه‌ها';
        $pass['current_url'] = $state['branches'][$seedIndex]['url'] ?? null;

        $finished = $pendingLeft === 0;
        $anyOk = false;
        foreach ($state['branches'] as $b) {
            if (($b['status'] ?? '') === 'done') {
                $anyOk = true;
                break;
            }
        }
        if ($finished) {
            $offersFound = (int) $pass['offers_found'];
            $pagesFetched = (int) $pass['pages_fetched'];
            $status = ($anyOk || $offersFound > 0) ? 'completed' : 'blocked';
            if ($offersFound === 0 && $pagesFetched === 0 && $errors !== []) {
                $status = 'blocked';
            }
            $pass['progress_label'] = $status === 'blocked' ? 'همه شاخه‌ها ناموفق بودند.' : 'پایان تأییدشدهٔ همه شاخه‌ها.';
            $this->persistState($pdo, $pass, $state, $status, $now, true);
            $this->touchSummary($pdo, $sourceId, $pass, $errors, $now, $status === 'completed');
            $passStatus = $status;
        } else {
            $this->persistState($pdo, $pass, $state, 'paused', $now, false);
            $this->release($pdo, (int) $pass['id']);
            $this->touchSummary($pdo, $sourceId, $pass, $errors, $now, true);
            $passStatus = 'paused';
        }

        return [
            'ok' => true,
            'busy' => false,
            'slug' => $slug,
            'pass_status' => $passStatus,
            'offers_found' => $batchOffers,
            'upserted' => $batchUpserted,
            'total_pass_offers' => (int) $pass['offers_found'],
            'message' => sprintf(
                'موج: %d آگهی · مجموع گذر %d · %s',
                $batchOffers,
                (int) $pass['offers_found'],
                (string) $pass['progress_label']
            ),
            'progress' => $crawler->progressSnapshot($slug),
            'metrics' => CrawlMetrics::snapshot(),
        ];
    }

    /**
     * One concurrent wave across many sources (local worker).
     *
     * @param list<string> $slugs
     * @return array{results:list<array>, metrics:array}
     */
    public function advanceMany(array $slugs, int $fetchesPerSource = 2): array
    {
        $results = [];
        // Round-robin one wave each so a slow domain cannot starve others across waves
        foreach ($slugs as $slug) {
            $snap = (new Crawler())->progressSnapshot($slug);
            if (in_array($snap['status'], ['completed', 'blocked'], true)) {
                continue;
            }
            $results[] = $this->advanceSource($slug, $snap['status'] === 'idle', $fetchesPerSource);
        }
        return ['results' => $results, 'metrics' => CrawlMetrics::snapshot()];
    }

    /** @param list<string> $seeds */
    private function freshState(array $seeds): array
    {
        $branches = [];
        foreach ($seeds as $i => $url) {
            $branches[] = [
                'status' => 'pending',
                'page' => 1,
                'url' => $url,
                'last_fp' => null,
                'seed_fp' => null,
                'error' => null,
            ];
        }
        return ['branches' => $branches, 'seen_urls' => [], 'offers_found' => 0, 'pages_fetched' => 0];
    }

    /** @param list<string> $seeds */
    private function decodeState(mixed $raw, array $seeds): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['branches']) && is_array($decoded['branches'])) {
                return $decoded;
            }
        }
        return $this->freshState($seeds);
    }

    /** @param array<string,mixed> $state */
    private function collectErrors(array $state): array
    {
        $errors = [];
        foreach ($state['branches'] as $b) {
            if (($b['status'] ?? '') === 'failed' && !empty($b['error'])) {
                $errors[] = (string) $b['error'];
            }
        }
        return $errors;
    }

    /**
     * @param array<string,mixed> $pass
     * @param array<string,mixed> $state
     */
    private function persistState(PDO $pdo, array &$pass, array $state, string $status, string $now, bool $finished): void
    {
        $pass['status'] = $status;
        $pass['branch_state'] = json_encode($state, JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            'UPDATE crawl_passes SET status=?, seed_index=?, page_index=?, current_url=?, offers_found=?, pages_fetched=?,
             last_batch_offers=?, last_batch_at=?, progress_label=?, branch_errors=?, last_error=?, branch_state=?,
             updated_at=?, finished_at=IF(?, ?, finished_at), lock_token=IF(?, NULL, lock_token), locked_until=IF(?, NULL, locked_until)
             WHERE id=?'
        )->execute([
            $status,
            (int) $pass['seed_index'],
            (int) $pass['page_index'],
            $pass['current_url'],
            (int) $pass['offers_found'],
            (int) $pass['pages_fetched'],
            (int) $pass['last_batch_offers'],
            $pass['last_batch_at'],
            $pass['progress_label'],
            is_string($pass['branch_errors'] ?? null) ? $pass['branch_errors'] : null,
            $pass['last_error'] ?? null,
            $pass['branch_state'],
            $now,
            $finished ? 1 : 0,
            $finished ? $now : null,
            $finished ? 1 : 0,
            $finished ? 1 : 0,
            (int) $pass['id'],
        ]);
    }

    /** @return array<string,mixed>|null */
    private function acquire(PDO $pdo, int $sourceId, bool $restart, AdapterInterface $adapter, string $now): ?array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM crawl_passes WHERE source_id = ? FOR UPDATE');
            $stmt->execute([$sourceId]);
            $pass = $stmt->fetch();
            if ($pass && $pass['locked_until'] && $pass['locked_until'] > $now && $pass['lock_token']) {
                $pdo->commit();
                return null;
            }
            $token = bin2hex(random_bytes(8));
            $lockUntil = gmdate('Y-m-d H:i:s', time() + 180);
            $seeds = $adapter->seedUrls();
            $first = $seeds[0] ?? null;
            $fresh = json_encode($this->freshState($seeds), JSON_UNESCAPED_UNICODE);

            if (!$pass) {
                $pdo->prepare(
                    'INSERT INTO crawl_passes (source_id, status, seed_index, page_index, current_url, offers_found, pages_fetched,
                     last_batch_offers, progress_label, branch_state, lock_token, locked_until, started_at, updated_at)
                     VALUES (?,?,0,1,?,0,0,0,?,?,?,?,?,?)'
                )->execute([$sourceId, 'running', $first, 'شروع گذر جدید', $fresh, $token, $lockUntil, $now, $now]);
            } elseif ($restart || in_array($pass['status'], ['completed', 'blocked'], true)) {
                if (!$restart && in_array($pass['status'], ['completed', 'blocked'], true)) {
                    $pdo->prepare('UPDATE crawl_passes SET lock_token=?, locked_until=?, updated_at=? WHERE id=?')
                        ->execute([$token, $lockUntil, $now, (int) $pass['id']]);
                } else {
                    $pdo->prepare(
                        'UPDATE crawl_passes SET status=?, seed_index=0, page_index=1, current_url=?, offers_found=0, pages_fetched=0,
                         last_batch_offers=0, last_batch_at=NULL, progress_label=?, branch_errors=NULL, last_error=NULL,
                         last_fingerprint=NULL, seed_fingerprint=NULL, branch_state=?,
                         lock_token=?, locked_until=?, started_at=?, updated_at=?, finished_at=NULL WHERE id=?'
                    )->execute(['running', $first, 'شروع گذر جدید', $fresh, $token, $lockUntil, $now, $now, (int) $pass['id']]);
                }
            } else {
                $pdo->prepare(
                    'UPDATE crawl_passes SET lock_token=?, locked_until=?, status=IF(status=\'paused\',\'running\',status), updated_at=? WHERE id=?'
                )->execute([$token, $lockUntil, $now, (int) $pass['id']]);
            }
            $stmt->execute([$sourceId]);
            $pass = $stmt->fetch();
            $pdo->commit();
            return $pass ?: null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function release(PDO $pdo, int $passId): void
    {
        $pdo->prepare('UPDATE crawl_passes SET lock_token=NULL, locked_until=NULL WHERE id=?')->execute([$passId]);
    }

    /** @param array<string,mixed> $pass */
    private function markFinished(PDO $pdo, array &$pass, string $status, string $msg, string $now): void
    {
        $pass['status'] = $status;
        $pdo->prepare(
            'UPDATE crawl_passes SET status=?, progress_label=?, finished_at=?, updated_at=?, lock_token=NULL, locked_until=NULL WHERE id=?'
        )->execute([$status, $msg, $now, $now, (int) $pass['id']]);
    }

    /**
     * @param array<string,mixed> $pass
     * @param list<string> $errors
     */
    private function touchSummary(PDO $pdo, int $sourceId, array $pass, array $errors, string $now, bool $hadSuccess): void
    {
        $err = $errors === [] ? null : mb_substr(implode(' | ', $errors), 0, 900);
        $pdo->prepare(
            'UPDATE sources SET last_success_at = IF(?, ?, last_success_at), last_error = ?, last_offers_found = ? WHERE id = ?'
        )->execute([$hadSuccess ? 1 : 0, $now, $err, (int) $pass['offers_found'], $sourceId]);
        $pdo->prepare(
            'INSERT INTO crawl_runs (source_id, started_at, finished_at, status, offers_found, error_message) VALUES (?,?,?,?,?,?)'
        )->execute([
            $sourceId,
            $pass['started_at'] ?? $now,
            $now,
            (string) ($pass['status'] ?? 'running'),
            (int) $pass['offers_found'],
            $err,
        ]);
    }
}
