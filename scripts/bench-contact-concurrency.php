<?php
declare(strict_types=1);
/**
 * Benchmark concurrent SSR contact fetch (does NOT write contacts).
 *
 *   php scripts/bench-contact-concurrency.php [--per-level=120] [--levels=1,2,4,6,8]
 *
 * Refuses if contact lock / live contact worker is active.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$perLevel = 120;
$levels = [1, 2, 4, 6, 8];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--per-level=(\d+)$/', $a, $m)) {
        $perLevel = max(20, min(300, (int) $m[1]));
    }
    if (preg_match('/^--levels=([\d,]+)$/', $a, $m)) {
        $levels = array_values(array_filter(array_map('intval', explode(',', $m[1])), static fn ($n) => $n >= 1 && $n <= 8));
    }
}

$root = dirname(__DIR__);
$pdo = Database::pdo();
$used = $pdo->query("SELECT IS_USED_LOCK('rondino-contacts')")->fetchColumn();
if ($used) {
    fwrite(STDERR, "Refusing: rondino-contacts lock in use\n");
    exit(3);
}
foreach (['crawl-rond-contacts-fast.pid', 'crawl-rond.pid'] as $pf) {
    $path = $root . '/storage/logs/' . $pf;
    if (!is_file($path)) {
        continue;
    }
    $pid = (int) trim((string) file_get_contents($path));
    if ($pid <= 0) {
        continue;
    }
    exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
    if (str_contains(implode("\n", $out), '"' . $pid . '"')) {
        fwrite(STDERR, "Refusing: live PID {$pid} from {$pf}\n");
        exit(3);
    }
}

$proxy = trim((string) (getenv('RONDINO_BROWSER_PROXY') ?: config('rondino_browser_proxy', '')));
if ($proxy !== '') {
    putenv('RONDINO_BROWSER_PROXY=' . $proxy);
}
putenv('RONDINO_BROWSER_DEADLINE_MS=0');

$rows = $pdo->query(
    "SELECT o.canonical_url, o.external_id, n.number AS sim
     FROM offers o
     JOIN sources s ON s.id=o.source_id
     JOIN sim_numbers n ON n.id=o.sim_number_id
     LEFT JOIN offer_contacts oc ON oc.offer_id=o.id
     LEFT JOIN rond_contact_checks c ON c.offer_id=o.id
     WHERE s.slug='rond-ir' AND oc.offer_id IS NULL
       AND (c.offer_id IS NULL OR (c.status='failed' AND c.retry_at <= UTC_TIMESTAMP()))
     ORDER BY o.id
     LIMIT " . (int) ($perLevel * max(1, count($levels)))
)->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) < $perLevel) {
    fwrite(STDERR, 'Not enough eligible rows: ' . count($rows) . "\n");
    exit(1);
}

$reports = [];
$offset = 0;
foreach ($levels as $conc) {
    $slice = array_slice($rows, $offset, $perLevel);
    $offset += $perLevel;
    $urlsFile = $root . '/storage/cache/bench-urls-' . $conc . '.txt';
    $outFile = $root . '/storage/cache/bench-out-' . $conc . '.json';
    file_put_contents($urlsFile, implode("\n", array_column($slice, 'canonical_url')) . "\n");

    putenv('RONDINO_CONTACT_CONCURRENCY=' . (string) $conc);
    putenv('RONDINO_CONTACT_DELAY_MS=40');
    $cmd = 'node ' . escapeshellarg($root . '/scripts/browser-ngstate-pool.mjs')
        . ' --urls-file ' . escapeshellarg($urlsFile)
        . ' --out ' . escapeshellarg($outFile)
        . ' --concurrency ' . (int) $conc;
    $t0 = microtime(true);
    exec($cmd . ' 2>&1', $outLines, $code);
    $elapsed = microtime(true) - $t0;
    $payload = json_decode((string) @file_get_contents($outFile), true) ?: [];
    $results = $payload['results'] ?? [];
    $byUrl = [];
    foreach ($results as $r) {
        $byUrl[(string) ($r['url'] ?? '')] = $r;
    }
    $verified = 0;
    $unresolved = 0;
    $failed = 0;
    $phones = 0;
    foreach ($slice as $row) {
        $r = $byUrl[$row['canonical_url']] ?? null;
        if (!$r || empty($r['ok'])) {
            $failed++;
            continue;
        }
        $got = RondContacts::parse((string) ($r['html'] ?? ''), (string) $row['external_id'], (string) $row['sim']);
        if ($got) {
            $verified++;
            $phones += count($got);
        } else {
            $unresolved++;
        }
    }
    $summary = $payload['summary'] ?? [];
    $rep = [
        'concurrency' => $conc,
        'sample' => count($slice),
        'elapsed_s' => round($elapsed, 2),
        'node_exit' => $code,
        'verified' => $verified,
        'unresolved' => $unresolved,
        'failed' => $failed,
        'phones' => $phones,
        'verified_per_min' => $elapsed > 0 ? round($verified / ($elapsed / 60), 2) : null,
        'processed_per_min' => $elapsed > 0 ? round(count($slice) / ($elapsed / 60), 2) : null,
        'avg_ms' => $summary['avg_ms'] ?? null,
        'p50_ms' => $summary['p50_ms'] ?? null,
        'p90_ms' => $summary['p90_ms'] ?? null,
        'http_fail_rate' => $summary['http_fail_rate'] ?? null,
        'unique_urls' => $summary['unique_urls'] ?? null,
        'saved_duplicate_fetches' => $summary['saved_duplicate_fetches'] ?? 0,
        'requests_per_listing' => count($slice) > 0
            ? round(($summary['unique_urls'] ?? count($slice)) / count($slice), 4)
            : null,
        'baseline_verified_per_min' => 33.9,
        'speedup_vs_baseline' => $elapsed > 0 && $verified > 0
            ? round(($verified / ($elapsed / 60)) / 33.9, 2)
            : null,
    ];
    $reports[] = $rep;
    echo json_encode($rep, JSON_UNESCAPED_UNICODE) . "\n";
    @unlink($urlsFile);
    // Keep out file for inspection but strip html to save disk in a slim copy
    foreach ($results as &$r) {
        unset($r['html']);
    }
    unset($r);
    file_put_contents(
        $root . '/storage/logs/bench-conc-' . $conc . '.json',
        json_encode(['summary' => $summary, 'results_meta' => $results], JSON_PRETTY_PRINT)
    );
}

usort($reports, static fn ($a, $b) => ($b['verified_per_min'] <=> $a['verified_per_min']));
$best = $reports[0] ?? null;
$final = [
    'utc' => gmdate('c'),
    'baseline_verified_per_min' => 33.9,
    'per_level' => $perLevel,
    'levels' => $reports,
    'best' => $best,
];
file_put_contents($root . '/storage/logs/bench-contact-concurrency.json', json_encode($final, JSON_PRETTY_PRINT));
echo "BEST=" . json_encode($best, JSON_UNESCAPED_UNICODE) . "\n";
