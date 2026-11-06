<?php
/**
 * Full / incremental / resume rond.ir 0912 inventory crawl (local Playwright + proxy).
 *
 * Usage (cmd):
 *   php scripts/crawl-rond.php                  # resume in-progress, else auto incremental/full
 *   php scripts/crawl-rond.php --incremental    # new incremental pass (overlap early-stop)
 *   php scripts/crawl-rond.php --full           # new full inventory reconciliation
 *   php scripts/crawl-rond.php --restart        # alias for new full pass
 *   php scripts/crawl-rond.php --retry-failed
 *   php scripts/crawl-rond.php --contacts
 *
 * Proxy/deadline/batch come from config/local.php (rondino_browser_*) unless env overrides.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$root = dirname(__DIR__);
$logDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'crawl-rond-full.log';
$stopFile = $logDir . DIRECTORY_SEPARATOR . 'crawl-worker.stop';
$pidFile = $logDir . DIRECTORY_SEPARATOR . 'crawl-rond.pid';

// Avoid accidental stop from a previous start.bat shutdown.
if (is_file($stopFile)) {
    @unlink($stopFile);
}

@file_put_contents($pidFile, (string) getmypid());

function rond_log(string $line): void
{
    global $logFile;
    $msg = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $line;
    echo $msg . PHP_EOL;
    @file_put_contents($logFile, $msg . PHP_EOL, FILE_APPEND);
}

// Apply local.php browser settings into process env for Node helpers.
$proxy = trim((string) (getenv('RONDINO_BROWSER_PROXY') ?: config('rondino_browser_proxy', '')));
if ($proxy !== '') {
    putenv('RONDINO_BROWSER_PROXY=' . $proxy);
    $_ENV['RONDINO_BROWSER_PROXY'] = $proxy;
}
$deadline = (int) (getenv('RONDINO_BROWSER_DEADLINE_MS') ?: config('rondino_browser_deadline_ms', 180000));
if ($deadline > 0) {
    putenv('RONDINO_BROWSER_DEADLINE_MS=' . $deadline);
    $_ENV['RONDINO_BROWSER_DEADLINE_MS'] = (string) $deadline;
}
$batch = (int) (getenv('RONDINO_API_BATCH_SIZE') ?: config('rondino_api_batch_size', 3));
if ($batch > 0) {
    putenv('RONDINO_API_BATCH_SIZE=' . $batch);
    $_ENV['RONDINO_API_BATCH_SIZE'] = (string) $batch;
}

$options = getopt('', ['restart', 'contacts', 'retry-failed', 'budget::', 'full', 'incremental']);
$engine = new CrawlEngine();
$pdo = Database::pdo();
$crawler = new Crawler();
SourceSync::ensureSchema($pdo);

// Clear expired lease so a crashed worker cannot block this dedicated run.
$nowUtc = now_dt();
$pdo->prepare(
    "UPDATE crawl_passes
     SET lock_token=NULL, locked_until=NULL,
         status=IF(status='running','paused',status)
     WHERE source_id=(SELECT id FROM sources WHERE slug='rond-ir')
       AND (locked_until IS NULL OR locked_until <= ?)"
)->execute([$nowUtc]);

$sourceId = (int) $pdo->query("SELECT id FROM sources WHERE slug='rond-ir'")->fetchColumn();
$passRow = $pdo->query(
    "SELECT status, pages_fetched, offers_found FROM crawl_passes WHERE source_id={$sourceId}"
)->fetch(PDO::FETCH_ASSOC) ?: [];
$passStatus = (string) ($passRow['status'] ?? '');
// Seed sync metadata from the verified completed inventory when present.
if ($passStatus === 'completed' && (int) ($passRow['pages_fetched'] ?? 0) > 0) {
    SourceSync::seedFromCompletedPass($pdo, $sourceId, (int) $passRow['pages_fetched'], null);
}

if (isset($options['retry-failed'])) {
    $lock = $pdo->query(
        "SELECT GET_LOCK(CONCAT('rondino-source-',(SELECT id FROM sources WHERE slug='rond-ir')),0)"
    )->fetchColumn();
    if ((int) $lock !== 1) {
        rond_log('Source is busy (named lock)');
        @unlink($pidFile);
        exit(1);
    }
    try {
        $row = $pdo->query(
            "SELECT * FROM crawl_passes WHERE source_id=(SELECT id FROM sources WHERE slug='rond-ir')"
        )->fetch();
        if ($row) {
            $state = json_decode((string) $row['branch_state'], true);
            if (!is_array($state)) {
                $state = ['branches' => []];
            }
            foreach ($state['branches'] as &$b) {
                if (in_array($b['status'] ?? '', ['failed', 'retry'], true)) {
                    $b['status'] = 'active';
                    $b['attempts'] = 0;
                    $b['retry_at'] = null;
                    $b['error'] = null;
                    $b['stop_reason'] = null;
                }
            }
            unset($b);
            $pdo->prepare(
                "UPDATE crawl_passes SET status='paused', branch_state=?, lock_token=NULL, locked_until=NULL, finished_at=NULL WHERE id=?"
            )->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $row['id']]);
            rond_log('Reopened failed/retry branches');
        }
    } finally {
        $pdo->query(
            "SELECT RELEASE_LOCK(CONCAT('rondino-source-',(SELECT id FROM sources WHERE slug='rond-ir')))"
        );
    }
}

$inProgress = in_array($passStatus, ['paused', 'running'], true);
$forceNew = isset($options['restart']) || isset($options['full']) || isset($options['incremental']);
$onlyContacts = isset($options['contacts']) && !$forceNew;
// --contacts alone must not wipe/restart a completed inventory pass.
$restart = $forceNew || (!$inProgress && !$onlyContacts);
if (isset($options['full']) || isset($options['restart'])) {
    $mode = 'full';
} elseif (isset($options['incremental'])) {
    $mode = 'incremental';
} elseif ($inProgress) {
    $mode = 'full'; // overwritten by branch_state crawl_mode on resume
} else {
    $mode = SourceSync::needsFullReconcile($pdo, $sourceId) ? 'full' : 'incremental';
}
if ($mode === 'incremental' && SourceSync::needsFullReconcile($pdo, $sourceId) && !isset($options['incremental'])) {
    $mode = 'full';
    rond_log('Auto-selecting full reconcile (no recent full completion watermark)');
}

$budget = isset($options['budget']) ? max(1, (int) $options['budget']) : max(1, min(3, $batch > 0 ? $batch : 3));
$busyStreak = 0;
$wave = 0;
$started = microtime(true);

rond_log(
    'Starting rond-ir crawl mode=' . $mode
    . ' restart=' . ($restart ? '1' : '0')
    . " budget={$budget} proxy=" . ($proxy !== '' ? $proxy : 'none')
);

while (!is_file($stopFile)) {
    $wave++;
    $result = $engine->advanceSource('rond-ir', $restart, $budget, $mode);
    $restart = false;
    $status = (string) ($result['pass_status'] ?? '');
    $busy = !empty($result['busy']);
    $prog = $result['progress'] ?? $crawler->progressSnapshot('rond-ir');
    $pages = (int) ($prog['pages_fetched'] ?? 0);
    $unique = (int) ($prog['unique_offers_pass'] ?? $prog['offers_found'] ?? 0);
    $totalPages = (int) ($prog['source_total_pages'] ?? 0);
    $totalEl = (int) ($prog['source_total_elements'] ?? 0);
    $label = (string) ($prog['progress_label'] ?? '');

    rond_log(sprintf(
        'wave=%d status=%s busy=%d pages=%d%s unique=%d totalElements=%s msg=%s',
        $wave,
        $status,
        $busy ? 1 : 0,
        $pages,
        $totalPages > 0 ? ('/' . $totalPages) : '',
        $unique,
        $totalEl > 0 ? (string) $totalEl : '?',
        (string) ($result['message'] ?? $label)
    ));

    if ($busy) {
        $busyStreak++;
        if ($busyStreak > 30) {
            rond_log('Giving up after prolonged busy/lock contention');
            @unlink($pidFile);
            exit(1);
        }
        sleep(min(15, 2 + $busyStreak));
        continue;
    }
    $busyStreak = 0;

    if (in_array($status, ['completed', 'blocked', 'partial', 'disabled'], true)) {
        if ($status === 'completed') {
            $elapsed = round(microtime(true) - $started, 1);
            rond_log("COMPLETED pages={$pages} unique={$unique} elapsed_s={$elapsed}");
            break;
        }
        if ($status === 'partial') {
            rond_log('PARTIAL inventory stop — retry with --retry-failed or continue after inspecting errors');
            @unlink($pidFile);
            exit(2);
        }
        rond_log('FAILED status=' . $status);
        @unlink($pidFile);
        exit(1);
    }

    // Prefetch reuse is in-process; tiny pause is enough between waves.
    usleep(200000);
}

if (is_file($stopFile)) {
    rond_log('Stop file seen; exiting with checkpoint saved');
}

if (isset($options['contacts'])) {
    rond_log('Contact enrichment pass starting');
    while (!is_file($stopFile) && RondContacts::advance()) {
        rond_log('contact item checked');
        sleep(1);
    }
    rond_log('Contact queue idle (unresolved/retry items may remain)');
}

@unlink($pidFile);
rond_log('crawl-rond exit');
