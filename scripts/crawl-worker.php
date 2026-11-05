<?php

declare(strict_types=1);

/**
 * Local CLI crawl worker. Started by start.bat — no Redis/Docker/Node.
 * Reuses CrawlEngine (same logic as HTTP batch mode for cPanel).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

if (!is_file(config_path())) {
    fwrite(STDERR, "config/local.php missing\n");
    exit(1);
}

$stopFile = dirname(__DIR__) . '/storage/logs/crawl-worker.stop';
$pidFile = dirname(__DIR__) . '/storage/logs/crawl-worker.pid';
@file_put_contents($pidFile, (string) getmypid());

CrawlMetrics::reset();
Http::clearMemoryCache();

$engine = new CrawlEngine();
$idleLoops = 0;

echo '[' . gmdate('H:i:s') . "] crawl-worker started pid=" . getmypid() . "\n";

while (true) {
    if (is_file($stopFile)) {
        @unlink($stopFile);
        echo "stop file seen; exiting\n";
        break;
    }

    try {
        Database::pdo();
    } catch (Throwable $e) {
        echo "db wait: " . $e->getMessage() . "\n";
        sleep(2);
        continue;
    }

    CrawlJob::ensureSchema(Database::pdo());
    $job = CrawlJob::claimNext();
    if (!$job) {
        $idleLoops++;
        // Also auto-drain paused passes without a job (resume after browser closed)
        $paused = Database::pdo()->query(
            "SELECT s.slug FROM crawl_passes p JOIN sources s ON s.id=p.source_id WHERE p.status IN ('paused','running') LIMIT 8"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($paused) {
            foreach ($paused as $slug) {
                $engine->advanceSource((string) $slug, false, max(1, (int) config('crawl_page_budget', 4)));
            }
            $idleLoops = 0;
            usleep(50000);
            continue;
        }
        usleep(400000);
        if ($idleLoops > 3 && (int) config('crawl_worker_exit_when_idle', 0) === 1) {
            break;
        }
        continue;
    }

    $jobId = (int) $job['id'];
    $mode = (string) ($job['mode'] ?? 'full');
    CrawlJob::touch($jobId, 'running', 'کارگر در حال خزش است');
    echo '[' . gmdate('H:i:s') . "] job #{$jobId} mode={$mode}\n";

    CrawlMetrics::reset();
    Http::clearMemoryCache();

    $pdo = Database::pdo();
    $slugs = $pdo->query('SELECT slug FROM sources WHERE is_enabled = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $forceRestart = $mode === 'full' || $mode === 'restart';

    // Start/resume all sources
    foreach ($slugs as $slug) {
        $snap = (new Crawler())->progressSnapshot((string) $slug);
        $restart = $forceRestart || $snap['status'] === 'idle';
        if ($forceRestart) {
            $engine->advanceSource((string) $slug, true, max(1, (int) config('crawl_page_budget', 4)));
        } elseif (in_array($snap['status'], ['idle', 'paused', 'running'], true)) {
            $engine->advanceSource((string) $slug, $restart, max(1, (int) config('crawl_page_budget', 4)));
        }
    }

    // Drain until all completed/blocked
    $guard = 0;
    while ($guard++ < 5000) {
        if (is_file($stopFile)) {
            CrawlJob::touch($jobId, 'cancelled', 'توقف توسط کاربر', CrawlMetrics::snapshot());
            break 2;
        }
        $progress = (new Crawler())->allProgress();
        $left = [];
        foreach ($progress as $p) {
            if (in_array($p['status'], ['idle', 'paused', 'running'], true)) {
                $left[] = $p['slug'];
            }
        }
        if ($left === []) {
            CrawlJob::touch($jobId, 'completed', 'گذر کامل شد', CrawlMetrics::snapshot());
            echo '[' . gmdate('H:i:s') . "] job #{$jobId} completed\n";
            break;
        }

        // Concurrent wave: advance several sources; each advances multiple seeds
        $waveSize = max(1, (int) config('crawl_rotate_wave', 4));
        $chunk = array_slice($left, 0, $waveSize);
        foreach ($chunk as $slug) {
            $engine->advanceSource($slug, false, max(1, (int) config('crawl_page_budget', 4)));
        }
        CrawlJob::touch($jobId, 'running', 'باقی‌مانده: ' . count($left), CrawlMetrics::snapshot());
        usleep(20000);
    }
}

@unlink($pidFile);
echo "worker exit\n";
