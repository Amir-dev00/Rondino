<?php

declare(strict_types=1);

final class Crawler
{
    /**
     * Refresh one source with a bounded batch of seed URLs / pages.
     *
     * @return array{ok:bool, offers_found:int, upserted:int, error:?string, message:string}
     */
    public function refreshSource(string $slug, int $maxPages = 2): array
    {
        $adapter = Registry::get($slug);
        if (!$adapter) {
            return ['ok' => false, 'offers_found' => 0, 'upserted' => 0, 'error' => 'منبع ناشناخته', 'message' => 'منبع یافت نشد.'];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM sources WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $source = $stmt->fetch();
        if (!$source) {
            return ['ok' => false, 'offers_found' => 0, 'upserted' => 0, 'error' => 'منبع در پایگاه نیست', 'message' => 'منبع در پایگاه داده نیست.'];
        }

        $sourceId = (int) $source['id'];
        $now = now_dt();
        $pdo->prepare('UPDATE sources SET last_attempt_at = ? WHERE id = ?')->execute([$now, $sourceId]);
        $pdo->prepare(
            'INSERT INTO crawl_runs (source_id, started_at, status) VALUES (?,?,?)'
        )->execute([$sourceId, $now, 'running']);
        $runId = (int) $pdo->lastInsertId();

        $timeout = (int) config('http_timeout', 25);
        $store = new OfferStore();
        $allListings = [];
        $errors = [];

        try {
            $seeds = $adapter->seedUrls();
            // Bound batch: first N seed URLs only per request so UI stays responsive
            $seeds = array_slice($seeds, 0, max(1, min(count($seeds), 3)));

            foreach ($seeds as $seed) {
                $pageUrl = $seed;
                for ($page = 1; $page <= $maxPages; $page++) {
                    $res = Http::get($pageUrl, $timeout);
                    if (!$res['ok']) {
                        $errors[] = $pageUrl . ': ' . ($res['error'] ?? 'خطا');
                        break;
                    }
                    $parsed = $adapter->parseListPage($res['body'], $res['final_url'] ?: $pageUrl);
                    foreach ($parsed as $row) {
                        $allListings[] = $row;
                    }
                    $next = $adapter->nextPageUrl($res['body'], $pageUrl, $page);
                    if ($next === null || $next === $pageUrl) {
                        break;
                    }
                    $pageUrl = $next;
                    usleep(400000); // polite pause ~0.4s
                }
                usleep(500000);
            }

            // Deduplicate by external_id within this run
            $unique = [];
            foreach ($allListings as $row) {
                $key = (string) ($row['external_id'] ?? '');
                if ($key === '') {
                    continue;
                }
                $unique[$key] = $row;
            }
            $list = array_values($unique);
            $result = $store->upsertMany($sourceId, $list);
            $found = count($list);
            $finished = now_dt();
            $errText = $errors === [] ? null : mb_substr(implode(' | ', $errors), 0, 900);

            // Success if we got HTML parse path without total failure, even with 0 offers
            $ok = $errors === [] || $found > 0;
            if ($errors !== [] && $found === 0) {
                $ok = false;
            }

            $pdo->prepare(
                'UPDATE sources SET last_success_at = IF(?, ?, last_success_at), last_error = ?, last_offers_found = ? WHERE id = ?'
            )->execute([$ok ? 1 : 0, $finished, $errText, $found, $sourceId]);

            $pdo->prepare(
                'UPDATE crawl_runs SET finished_at=?, status=?, offers_found=?, error_message=? WHERE id=?'
            )->execute([$finished, $ok ? 'ok' : 'error', $found, $errText, $runId]);

            $msg = $ok
                ? "{$found} آگهی خوانده شد؛ {$result['upserted']} ذخیره/به‌روز شد."
                : ('بروزرسانی ناموفق: ' . ($errText ?: 'خطای ناشناخته'));

            return [
                'ok' => $ok,
                'offers_found' => $found,
                'upserted' => $result['upserted'],
                'error' => $errText,
                'message' => $msg,
            ];
        } catch (Throwable $e) {
            $finished = now_dt();
            $msg = mb_substr($e->getMessage(), 0, 900);
            $pdo->prepare('UPDATE sources SET last_error = ? WHERE id = ?')->execute([$msg, $sourceId]);
            $pdo->prepare(
                'UPDATE crawl_runs SET finished_at=?, status=?, error_message=? WHERE id=?'
            )->execute([$finished, 'error', $msg, $runId]);
            app_log('crawl ' . $slug . ': ' . $e->getMessage());
            return [
                'ok' => false,
                'offers_found' => 0,
                'upserted' => 0,
                'error' => $msg,
                'message' => 'خطا: ' . $msg,
            ];
        }
    }
}
