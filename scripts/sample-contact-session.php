<?php
declare(strict_types=1);
/**
 * Controlled benchmark harness for scripts/browser-contact-session.mjs.
 *
 * IMPORTANT: Do not run against the live site while another contact worker
 * (e.g. crawl-rond.php --contacts) is using the proxy. This script refuses
 * to start if PID file / named lock indicates an active contact worker.
 *
 * Usage:
 *   php scripts/sample-contact-session.php [--limit=8] [--force-live]
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$limit = 8;
$force = false;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = max(3, min(20, (int) $m[1]));
    }
    if ($a === '--force-live') {
        $force = true;
    }
}

$pdo = Database::pdo();
$lock = $pdo->query("SELECT IS_USED_LOCK('rondino-contacts')")->fetchColumn();
$pidFile = dirname(__DIR__) . '/storage/logs/crawl-rond.pid';
$pidAlive = false;
if (is_file($pidFile)) {
    $pid = (int) trim((string) file_get_contents($pidFile));
    if ($pid > 0) {
        exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
        $pidAlive = str_contains(implode("\n", $out), '"' . $pid . '"');
    }
}

if (!$force && ((int) $lock === 1 || $pidAlive)) {
    fwrite(STDERR, json_encode([
        'refused' => true,
        'reason' => 'Active contact worker detected; refuse competing live benchmark',
        'contacts_lock_used' => (int) $lock === 1,
        'pid_file_alive' => $pidAlive,
        'hint' => 'Gracefully stop the live worker first, or pass --force-live only if you accept proxy contention',
    ], JSON_PRETTY_PRINT) . "\n");
    exit(3);
}

$proxy = trim((string) (getenv('RONDINO_BROWSER_PROXY') ?: config('rondino_browser_proxy', '')));
if ($proxy !== '') {
    putenv('RONDINO_BROWSER_PROXY=' . $proxy);
}

$rows = $pdo->query(
    "SELECT o.id, o.external_id, o.canonical_url, n.number AS sim
     FROM offers o
     JOIN sources s ON s.id=o.source_id
     JOIN sim_numbers n ON n.id=o.sim_number_id
     LEFT JOIN offer_contacts oc ON oc.offer_id=o.id
     WHERE s.slug='rond-ir' AND oc.offer_id IS NULL
     ORDER BY o.id DESC
     LIMIT {$limit}"
)->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    fwrite(STDERR, "No eligible offers for sample\n");
    exit(2);
}

$urlsFile = dirname(__DIR__) . '/storage/logs/contact-session-urls.txt';
$outFile = dirname(__DIR__) . '/storage/logs/contact-session-bench.json';
file_put_contents($urlsFile, implode("\n", array_column($rows, 'canonical_url')) . "\n");

$node = 'node';
$script = dirname(__DIR__) . '/scripts/browser-contact-session.mjs';
$cmd = escapeshellarg($node) . ' ' . escapeshellarg($script)
    . ' --urls-file ' . escapeshellarg($urlsFile)
    . ' --out ' . escapeshellarg($outFile);
$t0 = microtime(true);
passthru($cmd, $code);
$wall = microtime(true) - $t0;

$payload = json_decode((string) @file_get_contents($outFile), true) ?: [];
$results = $payload['results'] ?? [];
$byUrl = [];
foreach ($results as $r) {
    $byUrl[(string) ($r['url'] ?? '')] = $r;
}

$verified = 0;
$unresolved = 0;
$failed = 0;
$phonesSaved = 0;
// Parse-only persistence for benchmark (does not change retry cooldowns on production queue
// unless --persist is added later). Default: measure parse success only.
$parseReport = [];
foreach ($rows as $row) {
    $r = $byUrl[$row['canonical_url']] ?? null;
    if (!$r || empty($r['ok'])) {
        $failed++;
        $parseReport[] = ['offer_id' => $row['id'], 'status' => 'failed', 'phones' => []];
        continue;
    }
    $phones = RondContacts::parse((string) $r['html'], (string) $row['external_id'], (string) $row['sim']);
    if ($phones) {
        $verified++;
        $phonesSaved += count($phones);
        $parseReport[] = ['offer_id' => $row['id'], 'sim' => $row['sim'], 'status' => 'verified', 'phones' => $phones];
    } else {
        $unresolved++;
        $parseReport[] = ['offer_id' => $row['id'], 'sim' => $row['sim'], 'status' => 'unresolved', 'phones' => []];
    }
}

$report = [
    'wall_s' => round($wall, 2),
    'limit' => $limit,
    'node_exit' => $code,
    'session_summary' => $payload['summary'] ?? null,
    'verified_parse' => $verified,
    'unresolved_parse' => $unresolved,
    'failed_fetch' => $failed,
    'phones_found' => $phonesSaved,
    'per_min' => $wall > 0 ? round($limit / ($wall / 60), 2) : null,
    'items' => $parseReport,
    'persisted_to_db' => false,
    'note' => 'Benchmark does not write offer_contacts (read/parse only) to protect live worker data.',
];
file_put_contents(
    dirname(__DIR__) . '/storage/logs/contact-session-bench-report.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($code === 0 && $verified > 0 ? 0 : 2);
