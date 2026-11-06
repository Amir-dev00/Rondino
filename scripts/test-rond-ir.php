<?php

declare(strict_types=1);

/**
 * Focused RondIrAdapter regression tests (no live network).
 * Exit 0 on success, 1 on failure.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;

function assert_true(string $label, bool $cond): void
{
    global $failed;
    if (!$cond) {
        echo "FAIL $label\n";
        $failed++;
    } else {
        echo "OK   $label\n";
    }
}

function assert_eq(string $label, mixed $got, mixed $want): void
{
    global $failed;
    if ($got !== $want) {
        echo "FAIL $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
        $failed++;
    } else {
        echo "OK   $label\n";
    }
}

$adapter = Registry::get('rond-ir');
assert_true('adapter loaded', $adapter instanceof RondIrAdapter);

$seeds = $adapter->seedUrls();
assert_true('seed is listing API not homepage', isset($seeds[0]) && str_contains($seeds[0], '/api/ns/sim-card/web/sim-card-advert/page'));
assert_true('seed targets 0912', str_contains($seeds[0], 'precode=0912'));
assert_true('notes distinguish homepage sample', str_contains($adapter->notes(), 'صفحه اصلی'));

$valid = <<<HTML
<table class="table table-desktop">
<thead><tr><th>شماره</th><th>وضعیت</th><th>قیمت (تومان)</th><th>شرایط فروش</th><th>تماس</th></tr></thead>
<tbody>
<tr>
  <td>0912 111 11 11</td><td>کارکرده</td><td>12,000,000</td><td>نقد</td>
  <td><a href="/Sims/09121111111">تماس</a></td>
</tr>
<tr>
  <td>0912 111 11 11</td><td>کارکرده</td><td>12,000,000</td><td>نقد</td>
  <td><a href="/Sims/09121111111">تماس</a></td>
</tr>
<tr>
  <td>0912 222 22 22</td><td>صفر پک</td><td>15,000,000</td><td>نقد و اقساط</td>
  <td><a href="/Sims/09122222222">تماس</a></td>
</tr>
</tbody>
</table>
HTML;

$list = $adapter->parseListPage($valid, 'https://rond.ir/');
assert_eq('dedupe duplicate canonical/sim rows', count($list), 2);
assert_eq('first sim', $list[0]['sim_number'], '09121111111');
assert_eq('first url', $list[0]['canonical_url'], 'https://rond.ir/Sims/09121111111');
assert_eq('second sim', $list[1]['sim_number'], '09122222222');

$htmlPayload = $adapter->parseListPayload($valid, 'https://rond.ir/');
assert_eq('html coverage is sample', $htmlPayload['coverage']['scope'] ?? '', 'homepage_or_html_sample');
assert_eq('html coverage not complete inventory', $htmlPayload['coverage']['is_complete_inventory'] ?? true, false);
assert_eq('html next page is null (no invented pagination)', $adapter->nextPageUrl($valid, 'https://rond.ir/', 1), null);

$challenge = '<html><body>Transferring to the website<script>window.__arcsjs=1</script></body></html>';
try {
    $adapter->parseListPage($challenge, 'https://rond.ir/');
    assert_true('challenge should throw', false);
} catch (Throwable $e) {
    assert_true('challenge message mentions challenge', str_contains($e->getMessage(), 'challenge'));
    assert_true('challenge message does not claim network timeout', !str_contains(strtolower($e->getMessage()), 'timed_out'));
}

$headerOnly = '<html><body><table><tr><th>شماره</th><th>قیمت (تومان)</th></tr></table></body></html>';
try {
    $adapter->parseListPage($headerOnly, 'https://rond.ir/');
    assert_true('header-only should throw', false);
} catch (Throwable $e) {
    assert_true(
        'header-only is unrecognized listings, not challenge',
        str_contains($e->getMessage(), 'not recognized') || str_contains($e->getMessage(), 'zero verified')
    );
}

$apiPage0 = json_encode([
    'content' => [
        [
            'code' => '1001',
            'customerId' => 9,
            'advertSellType' => 'CASH_INSTALLMENT',
            'advertPriceType' => 'PRICE',
            'price' => 225000000,
            'simCardNumber' => '09124063909',
            'simCardStatus' => 'LIKE_NEW',
        ],
        [
            'code' => '1002',
            'customerId' => 9,
            'advertSellType' => 'CASH',
            'advertPriceType' => 'AGREEMENT',
            'price' => null,
            'simCardNumber' => '09121111111',
            'simCardStatus' => 'USED',
        ],
        [
            'code' => '1003',
            'customerId' => 3,
            'advertSellType' => 'CASH',
            'advertPriceType' => 'PRICE',
            'price' => 1000,
            'simCardNumber' => '09119999999', // non-0912 excluded from offers but counted as source
            'simCardStatus' => 'USED',
        ],
    ],
    'pageable' => ['pageNumber' => 0, 'pageSize' => 40],
    'totalPages' => 3,
    'totalElements' => 100,
    'number' => 0,
    'size' => 40,
], JSON_UNESCAPED_UNICODE);

$apiPayload = $adapter->parseListPayload($apiPage0, $seeds[0]);
assert_eq('api listings keep only 0912', count($apiPayload['listings']), 2);
assert_eq('api source records include non-0912 row', $apiPayload['source_record_count'], 3);
assert_eq('api coverage scope', $apiPayload['coverage']['scope'] ?? '', 'listing_api_0912');
assert_eq('api coverage complete inventory flag', $apiPayload['coverage']['is_complete_inventory'] ?? false, true);
assert_eq('api first sim', $apiPayload['listings'][0]['sim_number'], '09124063909');
assert_eq('api condition mapped', $apiPayload['listings'][0]['condition_raw'], 'در حد صفر');
assert_eq('api price amount', $apiPayload['listings'][0]['price_amount'], 225000000);
assert_true('api price raw includes sell terms', str_contains((string) $apiPayload['listings'][0]['price_raw'], 'نقد و اقساط'));
assert_eq('api agreement price type', $apiPayload['listings'][1]['price_type'], 'call');
assert_eq('api contacts empty from list endpoint', $apiPayload['listings'][0]['contacts'], []);

$next1 = $adapter->nextPageUrl($apiPage0, $seeds[0], 1);
assert_true('next page from page 0', is_string($next1) && str_contains($next1, 'page=1'));

$apiPageLast = json_encode([
    'content' => [
        [
            'code' => '2001',
            'advertSellType' => 'CASH',
            'advertPriceType' => 'PRICE',
            'price' => 1,
            'simCardNumber' => '09121234567',
            'simCardStatus' => 'USED',
        ],
    ],
    'pageable' => ['pageNumber' => 2, 'pageSize' => 40],
    'totalPages' => 3,
    'totalElements' => 100,
    'number' => 2,
    'size' => 40,
], JSON_UNESCAPED_UNICODE);
assert_eq('no next after last page', $adapter->nextPageUrl($apiPageLast, $seeds[0], 3), null);

// mapBrowserFailure via reflection
$ref = new ReflectionClass($adapter);
$map = $ref->getMethod('mapBrowserFailure');
$net = $map->invoke(
    $adapter,
    10,
    'connection_failure',
    'page.goto: net::ERR_CONNECTION_TIMED_OUT at https://rond.ir/',
    'page.goto: net::ERR_CONNECTION_TIMED_OUT at https://rond.ir/',
    ''
);
assert_true('connection failure error mentions network', str_contains($net, 'network failure'));
assert_true('connection failure does not say challenge page', !str_contains(strtolower($net), 'challenge page observed'));

$chal = $map->invoke($adapter, 13, 'challenge_observed', 'challenge content observed', '', $challenge);
assert_true('observed challenge mapped', str_contains($chal, 'challenge page observed'));

$nolist = $map->invoke($adapter, 14, 'no_listings', 'no anchors', '', $headerOnly);
assert_true('no listings mapped', str_contains($nolist, 'without recognizable listings'));

echo $failed === 0 ? "\nall passed\n" : "\n$failed failed\n";
exit($failed === 0 ? 0 : 1);
