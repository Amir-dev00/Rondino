<?php
declare(strict_types=1);
/**
 * Live check using RondIrAdapter incremental seeds + real search body.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$proxy = trim((string) (getenv('RONDINO_BROWSER_PROXY') ?: config('rondino_browser_proxy', '')));
if ($proxy !== '') {
    putenv('RONDINO_BROWSER_PROXY=' . $proxy);
    $_ENV['RONDINO_BROWSER_PROXY'] = $proxy;
}

$adapter = Registry::get('rond-ir');
$adapter->setCrawlMode('incremental');
$seeds = $adapter->seedUrls();
$out = ['seeds' => $seeds, 'streams' => []];

foreach ($seeds as $url) {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $special = (string) ($q['rondinoSpecial'] ?? '');
    $res = $adapter->fetchPage($url, 60);
    $json = json_decode((string) ($res['body'] ?? ''), true);
    $types = [];
    $prefixOk = 0;
    $prefixBad = 0;
    foreach (($json['content'] ?? []) as $row) {
        $t = (string) ($row['advertSpecialType'] ?? '');
        $types[$t] = ($types[$t] ?? 0) + 1;
        $sim = Digits::only(Digits::normalize((string) ($row['simCardNumber'] ?? '')));
        if (str_starts_with($sim, '0912')) {
            $prefixOk++;
        } else {
            $prefixBad++;
        }
    }
    $page1Url = $adapter->nextPageUrl((string) $res['body'], $url, 1);
    $res1 = $page1Url ? $adapter->fetchPage($page1Url, 60) : null;
    $json1 = $res1 ? json_decode((string) ($res1['body'] ?? ''), true) : null;
    $types1 = [];
    foreach (($json1['content'] ?? []) as $row) {
        $t = (string) ($row['advertSpecialType'] ?? '');
        $types1[$t] = ($types1[$t] ?? 0) + 1;
    }
    $pure0 = $types === [] || (count($types) === 1 && isset($types[$special]));
    $pure1 = $types1 === [] || (count($types1) === 1 && isset($types1[$special]));
    $out['streams'][] = [
        'special' => $special,
        'fetch_ok' => !empty($res['ok']),
        'totalElements' => $json['totalElements'] ?? null,
        'totalPages' => $json['totalPages'] ?? null,
        'page0_typeCounts' => $types,
        'page1_typeCounts' => $types1,
        'page0_pure' => $pure0,
        'page1_pure' => $pure1,
        'prefix_0912_ok' => $prefixOk,
        'prefix_0912_bad' => $prefixBad,
    ];
}

$out['ok'] = true;
foreach ($out['streams'] as $s) {
    if (!$s['fetch_ok'] || !$s['page0_pure'] || !$s['page1_pure'] || $s['prefix_0912_bad'] > 0) {
        $out['ok'] = false;
    }
}
$path = dirname(__DIR__) . '/storage/logs/verify-adapter-special-streams.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($out['ok'] ? 0 : 2);
