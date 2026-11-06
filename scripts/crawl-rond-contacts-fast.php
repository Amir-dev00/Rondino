<?php
declare(strict_types=1);
/**
 * Fast contact enrichment via SSR ng-state (context.request after one warm-up).
 *
 *   php scripts/crawl-rond-contacts-fast.php [--limit=40] [--batch=40] [--dry-run]
 *
 * Refuses to start if another crawl-rond contact worker pid file is alive.
 * Preserves existing offer_contacts; skips offers that already have phones.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$limit = 40;
$batch = 40;
$dry = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = max(1, min(500, (int) $m[1]));
    }
    if (preg_match('/^--batch=(\d+)$/', $a, $m)) {
        $batch = max(5, min(100, (int) $m[1]));
    }
    if ($a === '--dry-run') {
        $dry = true;
    }
}

$root = dirname(__DIR__);
$pidFile = $root . '/storage/logs/crawl-rond.pid';
if (is_file($pidFile)) {
    $pid = (int) trim((string) file_get_contents($pidFile));
    if ($pid > 0) {
        exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
        if (str_contains(implode("\n", $out), '"' . $pid . '"')) {
            fwrite(STDERR, "Refusing: crawl-rond PID {$pid} still alive\n");
            exit(3);
        }
    }
}

$proxy = trim((string) (getenv('RONDINO_BROWSER_PROXY') ?: config('rondino_browser_proxy', '')));
if ($proxy !== '') {
    putenv('RONDINO_BROWSER_PROXY=' . $proxy);
}
putenv('RONDINO_BROWSER_DEADLINE_MS=' . (string) ((int) config('rondino_browser_deadline_ms', 180000)));

$pdo = Database::pdo();
RondContacts::ensureSchema($pdo);
$lock = $pdo->query("SELECT GET_LOCK('rondino-contacts',0)")->fetchColumn();
if ((int) $lock !== 1) {
    fwrite(STDERR, "Refusing: rondino-contacts lock held\n");
    exit(3);
}

$myPidFile = $root . '/storage/logs/crawl-rond-contacts-fast.pid';
file_put_contents($myPidFile, (string) getmypid());
$stopFile = $root . '/storage/logs/crawl-worker.stop';
if (is_file($stopFile)) {
    @unlink($stopFile);
}

$stats = ['verified' => 0, 'unresolved' => 0, 'failed' => 0, 'phones' => 0, 'batches' => 0];
$t0 = microtime(true);

try {
    while (!is_file($stopFile)) {
        $rows = $pdo->query(
            "SELECT o.id, o.external_id, o.canonical_url, n.number AS sim, COALESCE(c.attempts,0) attempts
             FROM offers o
             JOIN sources s ON s.id=o.source_id
             JOIN sim_numbers n ON n.id=o.sim_number_id
             LEFT JOIN offer_contacts oc ON oc.offer_id=o.id
             LEFT JOIN rond_contact_checks c ON c.offer_id=o.id
             WHERE s.slug='rond-ir' AND s.is_enabled=1 AND oc.offer_id IS NULL
               AND (c.offer_id IS NULL OR (c.retry_at <= UTC_TIMESTAMP() AND c.attempts < 5))
             ORDER BY CASE WHEN c.offer_id IS NULL THEN 0 WHEN c.status='failed' THEN 1 ELSE 2 END, o.id
             LIMIT {$batch}"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }
        if ($stats['verified'] + $stats['unresolved'] + $stats['failed'] >= $limit) {
            break;
        }
        $take = array_slice($rows, 0, min($batch, $limit - ($stats['verified'] + $stats['unresolved'] + $stats['failed'])));
        $urlsFile = $root . '/storage/cache/ngstate-urls-' . getmypid() . '.txt';
        $outFile = $root . '/storage/cache/ngstate-out-' . getmypid() . '.json';
        file_put_contents($urlsFile, implode("\n", array_column($take, 'canonical_url')) . "\n");

        $cmd = 'node ' . escapeshellarg($root . '/scripts/browser-ngstate-session.mjs')
            . ' --urls-file ' . escapeshellarg($urlsFile)
            . ' --out ' . escapeshellarg($outFile);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $root . '/scripts', null, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            throw new RuntimeException('failed to start node session');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $payload = json_decode((string) @file_get_contents($outFile), true) ?: [];
        $byUrl = [];
        foreach ($payload['results'] ?? [] as $r) {
            $byUrl[(string) ($r['url'] ?? '')] = $r;
        }

        foreach ($take as $row) {
            $r = $byUrl[$row['canonical_url']] ?? null;
            if (!$r || empty($r['ok'])) {
                $stats['failed']++;
                $err = is_array($r) ? (string) ($r['error'] ?? 'fetch_failed') : 'missing_result';
                if (!$dry) {
                    RondContacts::persistResult($pdo, (int) $row['id'], 'failed', [], (int) $row['attempts'], $err);
                }
                echo '[' . gmdate('H:i:s') . "] failed {$row['sim']} {$err}\n";
                continue;
            }
            $phones = RondContacts::parse((string) $r['html'], (string) $row['external_id'], (string) $row['sim']);
            if ($phones) {
                $stats['verified']++;
                $stats['phones'] += count($phones);
                if (!$dry) {
                    RondContacts::persistResult($pdo, (int) $row['id'], 'verified', $phones, (int) $row['attempts'], null);
                }
                echo '[' . gmdate('H:i:s') . "] verified {$row['sim']} " . implode(',', $phones) . "\n";
            } else {
                $stats['unresolved']++;
                if (!$dry) {
                    RondContacts::persistResult(
                        $pdo,
                        (int) $row['id'],
                        'unresolved',
                        [],
                        (int) $row['attempts'],
                        'Seller/ad association not verified in detail HTML'
                    );
                }
                echo '[' . gmdate('H:i:s') . "] unresolved {$row['sim']}\n";
            }
        }
        $stats['batches']++;
        @unlink($urlsFile);
        @unlink($outFile);
        if ($code !== 0) {
            fwrite(STDERR, "node session exit={$code} stderr=" . substr((string) $stderr, 0, 300) . "\n");
        }
        // One batch per invocation when --limit matches batch (sample mode).
        if ($limit <= $batch) {
            break;
        }
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('rondino-contacts')");
    @unlink($myPidFile);
}

$elapsed = microtime(true) - $t0;
$done = $stats['verified'] + $stats['unresolved'] + $stats['failed'];
$report = [
    'elapsed_s' => round($elapsed, 2),
    'dry_run' => $dry,
    'processed' => $done,
    'stats' => $stats,
    'per_min' => $elapsed > 0 ? round($done / ($elapsed / 60), 2) : null,
    'verified_per_min' => $elapsed > 0 ? round($stats['verified'] / ($elapsed / 60), 2) : null,
];
file_put_contents($root . '/storage/logs/contacts-fast-last.json', json_encode($report, JSON_PRETTY_PRINT));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
