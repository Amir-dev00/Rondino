<?php
declare(strict_types=1);
/**
 * Fast contact enrichment: persistent Playwright session + bounded concurrent SSR fetches.
 *
 *   php scripts/crawl-rond-contacts-fast.php [--limit=200] [--batch=60] [--concurrency=4] [--dry-run]
 *
 * - One Chromium warm-up for the whole process (stdio Node pool).
 * - Deduplicates identical detail URLs within each batch; associates phones per offer code+SIM.
 * - Adaptive concurrency inside Node (cap via --concurrency).
 * - Refuses if another contact worker / crawl-rond pid is alive or MySQL lock held.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$limit = 0; // 0 = until queue empty / stop file
$batch = 60;
$concurrency = 6;
$dry = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = max(0, min(50000, (int) $m[1]));
    }
    if (preg_match('/^--batch=(\d+)$/', $a, $m)) {
        $batch = max(5, min(200, (int) $m[1]));
    }
    if (preg_match('/^--concurrency=(\d+)$/', $a, $m)) {
        $concurrency = max(1, min(8, (int) $m[1]));
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
$fastPidFile = $root . '/storage/logs/crawl-rond-contacts-fast.pid';
if (is_file($fastPidFile)) {
    $pid = (int) trim((string) file_get_contents($fastPidFile));
    if ($pid > 0 && $pid !== getmypid()) {
        exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out2);
        if (str_contains(implode("\n", $out2), '"' . $pid . '"')) {
            fwrite(STDERR, "Refusing: contacts-fast PID {$pid} still alive\n");
            exit(3);
        }
    }
}

$proxy = trim((string) (getenv('RONDINO_BROWSER_PROXY') ?: config('rondino_browser_proxy', '')));
if ($proxy !== '') {
    putenv('RONDINO_BROWSER_PROXY=' . $proxy);
}
putenv('RONDINO_BROWSER_DEADLINE_MS=0');
putenv('RONDINO_CONTACT_CONCURRENCY=' . (string) $concurrency);
putenv('RONDINO_CONTACT_DELAY_MS=' . (string) ((int) getenv('RONDINO_CONTACT_DELAY_MS') ?: 40));

$pdo = Database::pdo();
RondContacts::ensureSchema($pdo);
$lock = $pdo->query("SELECT GET_LOCK('rondino-contacts',0)")->fetchColumn();
if ((int) $lock !== 1) {
    fwrite(STDERR, "Refusing: rondino-contacts lock held\n");
    exit(3);
}

file_put_contents($fastPidFile, (string) getmypid());
$stopFile = $root . '/storage/logs/crawl-worker.stop';
if (is_file($stopFile)) {
    @unlink($stopFile);
}

$stats = [
    'verified' => 0,
    'unresolved' => 0,
    'failed' => 0,
    'phones' => 0,
    'batches' => 0,
    'unique_fetches' => 0,
    'dup_url_saves' => 0,
];
$t0 = microtime(true);

$cmd = 'node ' . escapeshellarg($root . '/scripts/browser-ngstate-pool.mjs')
    . ' --stdio --concurrency ' . (int) $concurrency;
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
// Windows: putenv alone is unreliable for proc_open children — pass env explicitly.
$childEnv = [
    'RONDINO_BROWSER_PROXY' => $proxy,
    'RONDINO_BROWSER_DEADLINE_MS' => '0',
    'RONDINO_CONTACT_CONCURRENCY' => (string) $concurrency,
    'RONDINO_CONTACT_DELAY_MS' => (string) ((int) (getenv('RONDINO_CONTACT_DELAY_MS') ?: 40)),
];
foreach (['PATH', 'SystemRoot', 'USERPROFILE', 'TEMP', 'TMP', 'APPDATA', 'LOCALAPPDATA', 'ComSpec', 'PATHEXT', 'HOME'] as $k) {
    $v = getenv($k);
    if ($v !== false && $v !== '') {
        $childEnv[$k] = $v;
    }
}

$proc = proc_open($cmd, $descriptors, $pipes, $root . '/scripts', $childEnv, ['bypass_shell' => false]);
if (!is_resource($proc)) {
    $pdo->query("SELECT RELEASE_LOCK('rondino-contacts')");
    @unlink($fastPidFile);
    throw new RuntimeException('failed to start node pool');
}

stream_set_blocking($pipes[1], true);
stream_set_blocking($pipes[2], false);

function pool_read_line($stdout): ?array
{
    $line = fgets($stdout);
    if ($line === false) {
        return null;
    }
    $j = json_decode(trim($line), true);
    return is_array($j) ? $j : null;
}

function pool_fetch($stdin, $stdout, array $urls, int $id, int $concurrency): array
{
    $payload = json_encode([
        'op' => 'fetch',
        'id' => $id,
        'urls' => array_values($urls),
        'concurrency' => $concurrency,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fwrite($stdin, $payload . "\n");
    fflush($stdin);
    while (true) {
        $msg = pool_read_line($stdout);
        if ($msg === null) {
            throw new RuntimeException('node pool stdout closed');
        }
        if (($msg['op'] ?? '') === 'result' && (int) ($msg['id'] ?? -1) === $id) {
            return $msg;
        }
        if (($msg['op'] ?? '') === 'error') {
            throw new RuntimeException('node pool error: ' . json_encode($msg));
        }
        // ignore ready/pong
    }
}

try {
    $ready = pool_read_line($pipes[1]);
    if (!$ready || ($ready['op'] ?? '') !== 'ready') {
        $err = stream_get_contents($pipes[2]);
        throw new RuntimeException('node pool not ready: ' . json_encode($ready) . ' stderr=' . substr((string) $err, 0, 400));
    }
    echo '[' . gmdate('H:i:s') . "] pool ready warm_ms=" . ($ready['warm_ms'] ?? '?')
        . " concurrency={$concurrency}\n";

    $reqId = 0;
    while (!is_file($stopFile)) {
        $done = $stats['verified'] + $stats['unresolved'] + $stats['failed'];
        if ($limit > 0 && $done >= $limit) {
            break;
        }
        $takeN = $batch;
        if ($limit > 0) {
            $takeN = min($batch, $limit - $done);
        }
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
             LIMIT {$takeN}"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }

        // Group by detail URL — one network fetch per unique document.
        $byUrl = [];
        foreach ($rows as $row) {
            $u = (string) $row['canonical_url'];
            $byUrl[$u][] = $row;
        }
        $urls = array_keys($byUrl);
        $stats['dup_url_saves'] += max(0, count($rows) - count($urls));

        $reqId++;
        $msg = pool_fetch($pipes[0], $pipes[1], $urls, $reqId, $concurrency);
        $stats['unique_fetches'] += (int) ($msg['metrics']['unique_urls'] ?? count($urls));
        $byResult = [];
        foreach ($msg['results'] ?? [] as $r) {
            $byResult[(string) ($r['url'] ?? '')] = $r;
        }

        foreach ($byUrl as $url => $offers) {
            $r = $byResult[$url] ?? null;
            $html = is_array($r) ? (string) ($r['html'] ?? '') : '';
            $fetchOk = is_array($r) && !empty($r['ok']) && $html !== '';

            foreach ($offers as $row) {
                if (!$fetchOk) {
                    $stats['failed']++;
                    $err = is_array($r) ? (string) ($r['error'] ?? 'fetch_failed') : 'missing_result';
                    if (!$dry) {
                        RondContacts::persistResult($pdo, (int) $row['id'], 'failed', [], (int) $row['attempts'], $err);
                    }
                    echo '[' . gmdate('H:i:s') . "] failed {$row['sim']} {$err}\n";
                    continue;
                }
                // Each offer must match its own advert code + SIM against the SAME SSR document.
                $phones = RondContacts::parse($html, (string) $row['external_id'], (string) $row['sim']);
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
        }
        $stats['batches']++;
        $metrics = $msg['metrics'] ?? [];
        echo '[' . gmdate('H:i:s') . '] batch#' . $stats['batches']
            . ' conc=' . ($metrics['concurrency_end'] ?? $concurrency)
            . ' p50=' . ($metrics['p50_ms'] ?? '?')
            . ' p90=' . ($metrics['p90_ms'] ?? '?')
            . ' fail%=' . ($metrics['http_fail_rate'] ?? '?')
            . "\n";

        // Sample mode: single batch when limit <= batch historically; keep streaming unless stop.
        if ($limit > 0 && ($stats['verified'] + $stats['unresolved'] + $stats['failed']) >= $limit) {
            break;
        }
    }

    fwrite($pipes[0], json_encode(['op' => 'quit']) . "\n");
    fflush($pipes[0]);
} finally {
    foreach ([0, 1, 2] as $i) {
        if (isset($pipes[$i]) && is_resource($pipes[$i])) {
            fclose($pipes[$i]);
        }
    }
    if (is_resource($proc)) {
        proc_close($proc);
    }
    $pdo->query("SELECT RELEASE_LOCK('rondino-contacts')");
    @unlink($fastPidFile);
}

$elapsed = microtime(true) - $t0;
$done = $stats['verified'] + $stats['unresolved'] + $stats['failed'];
$report = [
    'elapsed_s' => round($elapsed, 2),
    'dry_run' => $dry,
    'concurrency_cap' => $concurrency,
    'processed' => $done,
    'stats' => $stats,
    'per_min' => $elapsed > 0 ? round($done / ($elapsed / 60), 2) : null,
    'verified_per_min' => $elapsed > 0 ? round($stats['verified'] / ($elapsed / 60), 2) : null,
    'requests_per_listing' => $done > 0 ? round($stats['unique_fetches'] / $done, 4) : null,
];
file_put_contents($root . '/storage/logs/contacts-fast-last.json', json_encode($report, JSON_PRETTY_PRINT));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
