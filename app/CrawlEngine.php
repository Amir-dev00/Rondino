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
     * @param 'full'|'incremental' $mode Ignored when resuming an in-progress pass (state wins).
     * @return array<string,mixed>
     */
    public function advanceSource(string $slug, bool $restart = false, int $maxFetches = 0, string $mode = 'full'): array
    {
        $mode = $mode === 'incremental' ? 'incremental' : 'full';
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
        if (empty($source['is_enabled']) || $slug === 'setaresim') {
            return ['ok' => false, 'slug' => $slug, 'pass_status' => 'disabled', 'message' => 'منبع غیرفعال است.'];
        }
        // MySQL session lock survives long browser calls and is released on process death.
        $lockName = 'rondino-source-' . (int) $source['id'];
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            return ['ok' => true, 'busy' => true, 'slug' => $slug, 'pass_status' => 'running'];
        }
        try {
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

        if (in_array($pass['status'], ['completed', 'blocked', 'partial'], true) && !$restart) {
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

        // Resume must honor stored crawl_mode before seedUrls() (incremental uses
        // one seed URL per advertSpecialType stream).
        $effectiveMode = $mode;
        if (!$restart && !empty($pass['branch_state'])) {
            $peek = json_decode((string) $pass['branch_state'], true);
            if (is_array($peek) && (($peek['crawl_mode'] ?? '') === 'incremental' || ($peek['crawl_mode'] ?? '') === 'full')) {
                $effectiveMode = (string) $peek['crawl_mode'];
            }
        }
        if (method_exists($adapter, 'setCrawlMode')) {
            $adapter->setCrawlMode($effectiveMode);
        }

        $seeds = array_values($adapter->seedUrls());
        if ($seeds === []) {
            $this->markFinished($pdo, $pass, 'blocked', 'بدون seed', $now);
            return ['ok' => false, 'slug' => $slug, 'pass_status' => 'blocked', 'message' => 'بدون seed', 'progress' => $crawler->progressSnapshot($slug)];
        }

        $state = $this->decodeState($pass['branch_state'] ?? null, $seeds, (int) ($pass['id'] ?? 0));
        if ($restart && method_exists($adapter, 'clearPrefetch')) $adapter->clearPrefetch();
        if ($restart || empty($pass['branch_state'])) {
            $state = $this->freshState($seeds);
            $state['crawl_mode'] = $mode;
            $sync = SourceSync::load($pdo, $sourceId);
            $state['incremental_overlap_pages'] = max(1, (int) ($sync['overlap_pages'] ?? config('rondino_incremental_overlap_pages', 3)));
            $state['incremental_max_pages'] = max(
                (int) $state['incremental_overlap_pages'],
                (int) ($sync['incremental_max_pages'] ?? config('rondino_incremental_max_pages', 50))
            );
            $state['consecutive_known_pages'] = 0;
            $state['incremental_inserted'] = 0;
            $state['incremental_updated'] = 0;
            $state['incremental_unchanged'] = 0;
            SourceSync::markStarted($pdo, $sourceId, $mode);
        }
        $crawlMode = (($state['crawl_mode'] ?? $mode) === 'incremental') ? 'incremental' : 'full';
        $state['crawl_mode'] = $crawlMode;

        foreach ($state['branches'] as &$branch) {
            if (($branch['status'] ?? '') === 'retry' && (int) ($branch['retry_at'] ?? 0) <= time()) {
                $branch['status'] = 'active';
            }
        }
        unset($branch);
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
                $status = $errors !== [] ? ($offersFound > 0 ? 'partial' : 'blocked') : ($anyOk ? 'completed' : 'blocked');
                if ($offersFound === 0 && $pagesFetched === 0 && $errors !== []) {
                    $status = 'blocked';
                }
                $totalPagesKnown = (int) ($state['source_total_pages'] ?? 0);
                if (
                    $status === 'completed'
                    && !empty($state['coverage']['is_complete_inventory'])
                    && $totalPagesKnown > 1
                    && $pagesFetched < $totalPagesKnown
                ) {
                    $status = 'partial';
                    $state['stop_reason'] = 'incomplete_inventory_pages';
                }
                $msg = $status === 'completed' ? 'پایان همه شاخه‌ها' : 'گذر ناقص یا ناموفق است';
                $pass['offers_found'] = $offersFound;
                $pass['pages_fetched'] = $pagesFetched;
                $errors = $this->collectErrors($state);
                $pass['branch_errors'] = $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE);
                $this->persistState($pdo, $pass, $state, $status, $now, true);
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
                $attempt = (int) ($b['attempts'] ?? 0) + 1;
                $state['branches'][$i]['attempts'] = $attempt;
                $state['branches'][$i]['status'] = $attempt < 5 ? 'retry' : 'failed';
                $state['branches'][$i]['retry_at'] = time() + min(300, 5 * (2 ** $attempt)) + random_int(0, 3);
                $state['branches'][$i]['error'] = $url . ' => ' . ($res['error'] ?? 'خطا');
                $errors[] = $state['branches'][$i]['error'];
                continue;
            }

            $tParse = microtime(true);
            try {
                $payload = method_exists($adapter, 'parseListPayload')
                    ? $adapter->parseListPayload($res['body'], $res['final_url'] ?: $url)
                    : [
                        'listings' => $adapter->parseListPage($res['body'], $res['final_url'] ?: $url),
                        'source_record_ids' => [],
                        'source_record_count' => 0,
                    ];
            } catch (Throwable $e) {
                $state['branches'][$i]['status'] = 'failed';
                $state['branches'][$i]['error'] = $url . ' => parse: ' . $e->getMessage();
                $errors[] = $state['branches'][$i]['error'];
                CrawlMetrics::$parseMs += (microtime(true) - $tParse) * 1000;
                continue;
            }
            CrawlMetrics::$parseMs += (microtime(true) - $tParse) * 1000;

            $parsed = $payload['listings'] ?? [];
            $sourceIds = array_values(array_filter(array_map('strval', $payload['source_record_ids'] ?? [])));
            $sourceCount = (int) ($payload['source_record_count'] ?? count($sourceIds));
            if ($sourceIds === [] && $parsed !== []) {
                foreach ($parsed as $row) {
                    $key = (string) ($row['external_id'] ?? $row['sim_number'] ?? '');
                    if ($key !== '') {
                        $sourceIds[] = $key;
                    }
                }
                $sourceCount = max($sourceCount, count($sourceIds));
            }

            if (isset($payload['coverage']) && is_array($payload['coverage'])) {
                $previousTotal = $state['source_total_elements'] ?? null;
                if ($previousTotal !== null && isset($payload['coverage']['total_elements']) && $previousTotal !== (int) $payload['coverage']['total_elements']) {
                    $state['inventory_changed_during_crawl'] = true;
                }
                $state['total_observed_at'] = now_dt();
                $state['coverage'] = $payload['coverage'];
                if (isset($payload['coverage']['total_elements'])) {
                    $state['source_total_elements'] = (int) $payload['coverage']['total_elements'];
                }
                if (isset($payload['coverage']['total_pages'])) {
                    $state['source_total_pages'] = (int) $payload['coverage']['total_pages'];
                }
            }

            // No detail pages: all fields come from list HTML/API
            CrawlMetrics::$detailAvoided += count($parsed);

            $unique = [];
            foreach ($parsed as $row) {
                $key = (string) ($row['external_id'] ?? '');
                if ($key !== '') {
                    $unique[$key] = $row;
                }
            }
            $list = array_values($unique);
            $found = count($list);

            // Fingerprint source-page identities (not only matching 0912 offers).
            $fpIds = $sourceIds;
            sort($fpIds);
            $fp = sha1($sourceCount . '|' . implode('|', $fpIds));

            $repeat = $page > 1 && (
                (!empty($b['last_fp']) && $fp === $b['last_fp'])
                || (!empty($b['seed_fp']) && $fp === $b['seed_fp'])
            );

            if ($repeat) {
                $state['branches'][$i]['status'] = 'failed';
                $state['branches'][$i]['error'] = 'Repeated source page before verified end';
                $errors[] = $state['branches'][$i]['error'];
                $state['pages_fetched'] = (int) ($state['pages_fetched'] ?? 0) + 1;
                continue;
            }

            try {
                $next = $adapter->nextPageUrl($res['body'], $url, $page);
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

            if (!isset($state['seen_external_ids']) || !is_array($state['seen_external_ids'])) {
                $state['seen_external_ids'] = [];
            }
            $newUnique = 0;
            foreach ($list as $row) {
                $eid = (string) ($row['external_id'] ?? '');
                if ($eid === '' || isset($state['seen_external_ids'][$eid])) {
                    continue;
                }
                $state['seen_external_ids'][$eid] = 1;
                $newUnique++;
            }
            $state['unique_offers'] = (int) ($state['unique_offers'] ?? 0) + $newUnique;

            foreach ($list as $row) {
                $state['seen_sim_numbers'][(string) $row['sim_number']] = 1;
            }
            $state['excluded_rows'] = (int) ($state['excluded_rows'] ?? 0) + (int) ($payload['excluded'] ?? 0);
            $state['duplicate_rows'] = (int) ($state['duplicate_rows'] ?? 0) + max(0, $sourceCount - (int) ($payload['excluded'] ?? 0) - $newUnique);
            $state['branches'][$i]['error'] = null;
            $state['branches'][$i]['attempts'] = 0;
            $state['branches'][$i]['retry_at'] = null;
            $batchOffers += $found;
            $batchUpserted += $result['upserted'];
            $state['offers_found'] = (int) ($state['offers_found'] ?? 0) + $found;
            $state['pages_fetched'] = (int) ($state['pages_fetched'] ?? 0) + 1;
            $state['source_records'] = (int) ($state['source_records'] ?? 0) + $sourceCount;
            $state['incremental_inserted'] = (int) ($state['incremental_inserted'] ?? 0) + (int) ($result['inserted'] ?? 0);
            $state['incremental_updated'] = (int) ($state['incremental_updated'] ?? 0) + (int) ($result['updated'] ?? 0);
            $state['incremental_unchanged'] = (int) ($state['incremental_unchanged'] ?? 0) + (int) ($result['unchanged'] ?? 0);
            $state['seen_urls'][$url] = true;
            if ($page === 1) {
                $state['branches'][$i]['seed_fp'] = $fp;
            }
            $state['branches'][$i]['last_fp'] = $fp;
            $state['branches'][$i]['retried'] = false;

            // Incremental early-stop is per branch/stream. Global consecutive_known was
            // unsafe: advertSpecialType DESC packs VIP pages first, so 3 known VIP pages
            // could stop before any FREE pages (where brand-new FREE ads still appear).
            // Each seed stream (optionally filtered by advertSpecialTypeIn) tracks its own
            // known-page streak and page budget.
            $newBefore = (int) ($result['new_before'] ?? 0);
            $branchKnown = (int) ($state['branches'][$i]['consecutive_known_pages'] ?? 0);
            $branchPages = (int) ($state['branches'][$i]['pages_fetched'] ?? 0) + 1;
            $state['branches'][$i]['pages_fetched'] = $branchPages;
            if ($crawlMode === 'incremental' && $found > 0 && $newBefore === 0) {
                $branchKnown++;
            } elseif ($crawlMode === 'incremental') {
                $branchKnown = 0;
            }
            $state['branches'][$i]['consecutive_known_pages'] = $branchKnown;
            // Keep legacy aggregate for UI/metrics only (not used for stop decisions).
            $state['consecutive_known_pages'] = $branchKnown;
            $overlapNeed = max(1, (int) ($state['incremental_overlap_pages'] ?? 3));
            $maxIncPages = max($overlapNeed, (int) ($state['incremental_max_pages'] ?? 50));
            $hitOverlap = $crawlMode === 'incremental' && $branchKnown >= $overlapNeed;
            $hitCap = $crawlMode === 'incremental' && $branchPages >= $maxIncPages;
            if ($hitOverlap || $hitCap) {
                $state['branches'][$i]['status'] = 'done';
                $state['branches'][$i]['error'] = null;
                $state['branches'][$i]['url'] = null;
                $state['branches'][$i]['stop_reason'] = $hitOverlap
                    ? 'incremental_overlap_reached'
                    : 'incremental_page_cap';
                // Must not claim full inventory when stopping early.
                $state['coverage'] = is_array($state['coverage'] ?? null) ? $state['coverage'] : [];
                $state['coverage']['is_complete_inventory'] = false;
                $state['coverage']['scope'] = 'incremental_overlap';
                $state['coverage']['label'] = $hitOverlap
                    ? 'Incremental per-stream overlap reached (known external_id pages)'
                    : 'Incremental per-stream page cap reached — run --full to reconcile';
                $next = null; // force finish path below
            }

            // Advance whenever verified pagination says so — even if this page had
            // source rows but zero matching 0912 offers.
            $noProgress = $next === null || $next === '' || $next === $url || isset($state['seen_urls'][$next]);
            if ($noProgress) {
                $loop = $next !== null && $next !== '' && $next !== $url;
                $dup = $next !== null && $next !== '' && isset($state['seen_urls'][$next]);
                $totalPagesKnown = (int) ($state['source_total_pages'] ?? 0);
                // 1-based crawl page maps to 0-based API page (page-1). Premature end if more pages remain.
                $apiPageDone = max(0, $page - 1);
                $premature = !$loop && !$dup
                    && !empty($state['coverage']['is_complete_inventory'])
                    && $totalPagesKnown > 1
                    && ($apiPageDone + 1) < $totalPagesKnown;
                if ($premature) {
                    $attempts = (int) ($state['branches'][$i]['attempts'] ?? 0) + 1;
                    $state['branches'][$i]['attempts'] = $attempts;
                    $state['branches'][$i]['status'] = $attempts >= 5 ? 'failed' : 'retry';
                    $state['branches'][$i]['retry_at'] = time() + min(300, 10 * $attempts);
                    $state['branches'][$i]['error'] = sprintf(
                        'Premature pagination end at API page %d before source totalPages=%d',
                        $apiPageDone,
                        $totalPagesKnown
                    );
                    $state['branches'][$i]['url'] = $url;
                    $state['branches'][$i]['stop_reason'] = 'premature_pagination_end';
                    $errors[] = $state['branches'][$i]['error'];
                } elseif ($loop || $dup) {
                    $state['branches'][$i]['status'] = 'failed';
                    $state['branches'][$i]['error'] = $dup ? 'Pagination loop before verified end' : 'Pagination loop before verified end';
                    $state['branches'][$i]['url'] = $url;
                    $state['branches'][$i]['stop_reason'] = 'duplicate_next_url';
                    $errors[] = $state['branches'][$i]['error'];
                } else {
                    if (($state['branches'][$i]['status'] ?? '') !== 'done') {
                        $state['branches'][$i]['status'] = 'done';
                        $state['branches'][$i]['error'] = null;
                        $state['branches'][$i]['url'] = null;
                        $state['branches'][$i]['stop_reason'] = $state['branches'][$i]['stop_reason']
                            ?? 'pagination_exhausted';
                    }
                }
            } else {
                $state['branches'][$i]['status'] = 'active';
                $state['branches'][$i]['page'] = $page + 1;
                $state['branches'][$i]['url'] = $next;
                $state['branches'][$i]['stop_reason'] = null;
            }
        }

        // Summary fields for UI
        $activeLabel = '';
        $seedIndex = 0;
        $pageIndex = 1;
        $pendingLeft = 0;
        $coverage = is_array($state['coverage'] ?? null) ? $state['coverage'] : [];
        $coverageScope = (string) ($coverage['scope'] ?? '');
        $isCompleteInventory = !empty($coverage['is_complete_inventory']);
        $uniqueOffers = (int) ($state['unique_offers'] ?? 0);
        $totalElements = (int) ($state['source_total_elements'] ?? 0);
        $totalPages = (int) ($state['source_total_pages'] ?? 0);
        foreach ($state['branches'] as $idx => $b) {
            $st = $b['status'] ?? '';
            if ($st === 'active' || $st === 'pending' || $st === 'retry') {
                $pendingLeft++;
                if ($activeLabel === '') {
                    $seedIndex = $idx;
                    $pageIndex = (int) ($b['page'] ?? 1);
                    $covBit = $isCompleteInventory
                        ? 'مسیر: موجودی لیست API'
                        : ($coverageScope === 'homepage_or_html_sample' ? 'مسیر: نمونه صفحه اصلی' : 'مسیر پوشش: نامشخص');
                    $activeLabel = sprintf(
                        '%s · شاخه %d/%d · صفحه %d%s · یکتای گذر %d',
                        $covBit,
                        $idx + 1,
                        count($seeds),
                        $pageIndex,
                        $totalPages > 0 ? ('/' . $totalPages) : '',
                        $uniqueOffers
                    );
                }
            }
        }

        // Prefer unique current-crawl count for pass reporting (historical DB is separate in UI).
        $pass['offers_found'] = $uniqueOffers > 0 ? $uniqueOffers : (int) ($state['offers_found'] ?? 0);
        $pass['pages_fetched'] = (int) ($state['pages_fetched'] ?? 0);
        $pass['last_batch_offers'] = $batchOffers;
        $pass['last_batch_at'] = $now;
        $pass['seed_index'] = $seedIndex;
        $pass['page_index'] = $pageIndex;
        $errors = $this->collectErrors($state);
        $pass['branch_errors'] = $errors === [] ? null : json_encode(array_values(array_unique($errors)), JSON_UNESCAPED_UNICODE);
        $pass['last_error'] = $errors === [] ? null : mb_substr((string) end($errors), 0, 900);
        $pass['progress_label'] = $activeLabel !== '' ? ($activeLabel . ' · موج موازی') : 'در حال جمع‌بندی شاخه‌ها';
        $pass['current_url'] = $state['branches'][$seedIndex]['url'] ?? null;

        $finished = $pendingLeft === 0;
        $anyOk = false;
        $stopReasons = [];
        foreach ($state['branches'] as $b) {
            if (($b['status'] ?? '') === 'done') {
                $anyOk = true;
                if (!empty($b['stop_reason'])) {
                    $stopReasons[] = (string) $b['stop_reason'];
                }
            }
            if (in_array($b['status'] ?? '', ['failed', 'retry'], true) && !empty($b['error'])) {
                $stopReasons[] = 'branch_failed';
            }
        }
        $state['stop_reason'] = $stopReasons === [] ? null : implode(',', array_values(array_unique($stopReasons)));
        if ($finished) {
            $offersFound = (int) $pass['offers_found'];
            $pagesFetched = (int) $pass['pages_fetched'];
            $status = $errors !== [] ? ($offersFound > 0 ? 'partial' : 'blocked') : ($anyOk ? 'completed' : 'blocked');
            if ($offersFound === 0 && $pagesFetched === 0 && $errors !== []) {
                $status = 'blocked';
            }
            $gap = '';
            if ($isCompleteInventory && $totalElements > 0 && $uniqueOffers < $totalElements) {
                $gap = sprintf(' · فاصله پوشش %d/%d', $uniqueOffers, $totalElements);
            }
            $covBit = $isCompleteInventory
                ? 'مسیر موجودی لیست API'
                : ($coverageScope === 'homepage_or_html_sample' ? 'نمونه صفحه اصلی (ناقص)' : 'پوشش نامشخص');
            // Never claim full inventory completion when source still reports many unread pages.
            if (
                $status === 'completed'
                && $isCompleteInventory
                && $totalPages > 1
                && $pagesFetched > 0
                && $pagesFetched < $totalPages
            ) {
                $status = 'partial';
                $state['stop_reason'] = 'incomplete_inventory_pages';
                $gap = sprintf(' · فاصله پوشش صفحات %d/%d', $pagesFetched, $totalPages);
            }
            if ($status === 'blocked') {
                $pass['progress_label'] = 'توقف: همه شاخه‌ها ناموفق · ' . $covBit;
                $state['stop_reason'] = 'all_branches_failed';
            } else {
                $reason = $state['stop_reason'] ?: 'pagination_exhausted';
                $pass['progress_label'] = sprintf(
                    'پایان · %s · صفحات %d · یکتای گذر %d · دلیل توقف: %s%s',
                    $covBit,
                    $pagesFetched,
                    $uniqueOffers,
                    $reason,
                    $gap
                );
            }
            // Never mark older offers sold/removed just because a sample homepage rotated.
            $this->persistState($pdo, $pass, $state, $status, $now, true);
            $this->touchSummary($pdo, $sourceId, $pass, $errors, $now, $status === 'completed');
            if ($status === 'completed') {
                $lastExt = null;
                if (!empty($state['seen_external_ids']) && is_array($state['seen_external_ids'])) {
                    $keys = array_keys($state['seen_external_ids']);
                    $lastExt = $keys !== [] ? (string) end($keys) : null;
                    if ($lastExt === '_sidecar') {
                        $lastExt = null;
                    }
                }
                SourceSync::markCompleted(
                    $pdo,
                    $sourceId,
                    $crawlMode,
                    (int) ($state['pages_fetched'] ?? 0),
                    $lastExt,
                    $totalPages > 0 ? $totalPages : null,
                    $totalElements > 0 ? $totalElements : null,
                    [
                        'inserted' => (int) ($state['incremental_inserted'] ?? 0),
                        'updated' => (int) ($state['incremental_updated'] ?? 0),
                        'unchanged' => (int) ($state['incremental_unchanged'] ?? 0),
                        'unique_offers' => $uniqueOffers,
                        'stop_reason' => $state['stop_reason'] ?? null,
                    ]
                );
            }
            $passStatus = $status;
        } else {
            $this->persistState($pdo, $pass, $state, 'paused', $now, false);
            $this->release($pdo, (int) $pass['id']);
            $this->touchSummary($pdo, $sourceId, $pass, $errors, $now, $batchUpserted > 0);
            $passStatus = 'paused';
        }

        return [
            'ok' => !in_array($passStatus, ['partial','blocked'], true),
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
        } finally {
            $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute([$lockName]);
        }

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
            if (in_array($snap['status'], ['completed', 'blocked', 'partial'], true)) {
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
        return [
            'branches' => $branches,
            'seen_urls' => [],
            'seen_external_ids' => [],
            'offers_found' => 0,
            'unique_offers' => 0,
            'pages_fetched' => 0,
            'coverage' => null,
            'source_total_elements' => null,
            'source_total_pages' => null,
            'stop_reason' => null,
        ];
    }

    /** @param list<string> $seeds */
    private function decodeState(mixed $raw, array $seeds, int $passId = 0): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['branches']) && is_array($decoded['branches'])) {
                return $this->mergeSeenSidecar($decoded, $passId);
            }
        }
        return $this->freshState($seeds);
    }

    /** Absolute path for large seen_* maps spilled out of branch_state JSON. */
    private function seenSidecarPath(int $passId): string
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'crawl-pass-' . max(0, $passId) . '-seen.json';
    }

    /**
     * Keep crawl_passes.branch_state under MySQL max_allowed_packet by spilling
     * growing uniqueness maps to a sidecar file.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function spillSeenSets(array $state, int $passId): array
    {
        if ($passId <= 0) {
            return $state;
        }
        $external = is_array($state['seen_external_ids'] ?? null) ? $state['seen_external_ids'] : [];
        $sims = is_array($state['seen_sim_numbers'] ?? null) ? $state['seen_sim_numbers'] : [];
        // Already compacted marker from a prior persist.
        if (($external['_sidecar'] ?? false) === true || ($sims['_sidecar'] ?? false) === true) {
            return $state;
        }
        if ($external === [] && $sims === []) {
            return $state;
        }
        $path = $this->seenSidecarPath($passId);
        $payload = [
            'seen_external_ids' => $external,
            'seen_sim_numbers' => $sims,
            'unique_offers' => (int) ($state['unique_offers'] ?? count($external)),
            'updated_at' => gmdate('c'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($path, $json) === false) {
            // Fall back to inline maps (may still hit packet limit).
            return $state;
        }
        $state['seen_external_ids'] = ['_sidecar' => true, 'count' => count($external)];
        $state['seen_sim_numbers'] = ['_sidecar' => true, 'count' => count($sims)];
        $state['seen_sets_sidecar'] = basename($path);
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function mergeSeenSidecar(array $state, int $passId): array
    {
        $path = $this->seenSidecarPath($passId);
        if (!is_file($path)) {
            return $state;
        }
        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) {
            return $state;
        }
        $inlineExt = is_array($state['seen_external_ids'] ?? null) ? $state['seen_external_ids'] : [];
        $inlineSim = is_array($state['seen_sim_numbers'] ?? null) ? $state['seen_sim_numbers'] : [];
        $fileExt = is_array($payload['seen_external_ids'] ?? null) ? $payload['seen_external_ids'] : [];
        $fileSim = is_array($payload['seen_sim_numbers'] ?? null) ? $payload['seen_sim_numbers'] : [];
        // Prefer sidecar contents when inline is a compact marker or smaller.
        if (($inlineExt['_sidecar'] ?? false) === true || count($fileExt) >= count($inlineExt)) {
            $state['seen_external_ids'] = $fileExt;
        } else {
            $state['seen_external_ids'] = $inlineExt + $fileExt;
        }
        if (($inlineSim['_sidecar'] ?? false) === true || count($fileSim) >= count($inlineSim)) {
            $state['seen_sim_numbers'] = $fileSim;
        } else {
            $state['seen_sim_numbers'] = $inlineSim + $fileSim;
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function collectErrors(array $state): array
    {
        $errors = [];
        foreach ($state['branches'] as $b) {
            if (in_array($b['status'] ?? '', ['failed', 'retry'], true) && !empty($b['error'])) {
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
        $passId = (int) ($pass['id'] ?? 0);
        // Spill before encode so in-memory $state used later in this request stays full;
        // only the DB payload is compacted.
        $dbState = $this->spillSeenSets($state, $passId);
        $pass['status'] = $status;
        $pass['branch_state'] = json_encode($dbState, JSON_UNESCAPED_UNICODE);
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
            $passId,
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
            // Expired leases must not block resume (browser waves can outlive a crashed holder).
            if ($pass && $pass['locked_until'] && $pass['locked_until'] > $now && $pass['lock_token']) {
                $pdo->commit();
                return null;
            }
            if ($pass && $pass['lock_token'] && (!$pass['locked_until'] || $pass['locked_until'] <= $now)) {
                $pdo->prepare('UPDATE crawl_passes SET lock_token=NULL, locked_until=NULL WHERE id=?')
                    ->execute([(int) $pass['id']]);
            }
            $token = bin2hex(random_bytes(8));
            // Long enough for one Playwright API batch (~2–3 min), short enough to recover.
            $lockUntil = gmdate('Y-m-d H:i:s', time() + 300);
            $seeds = $adapter->seedUrls();
            $first = $seeds[0] ?? null;
            $fresh = json_encode($this->freshState($seeds), JSON_UNESCAPED_UNICODE);

            if (!$pass) {
                $pdo->prepare(
                    'INSERT INTO crawl_passes (source_id, status, seed_index, page_index, current_url, offers_found, pages_fetched,
                     last_batch_offers, progress_label, branch_state, lock_token, locked_until, started_at, updated_at)
                     VALUES (?,?,0,1,?,0,0,0,?,?,?,?,?,?)'
                )->execute([$sourceId, 'running', $first, 'شروع گذر جدید', $fresh, $token, $lockUntil, $now, $now]);
            } elseif ($restart || in_array($pass['status'], ['completed', 'blocked', 'partial'], true)) {
                if (!$restart && in_array($pass['status'], ['completed', 'blocked', 'partial'], true)) {
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

