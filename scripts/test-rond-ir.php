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

$valid = <<<HTML
<table class="table table-desktop">
<thead><tr><th>شماره</th><th>وضعیت</th><th>قیمت (تومان)</th><th>تماس</th></tr></thead>
<tbody>
<tr>
  <td>0912 111 11 11</td><td>کارکرده</td><td>12,000,000</td>
  <td><a href="/Sims/09121111111">تماس</a></td>
</tr>
<tr>
  <td>0912 111 11 11</td><td>کارکرده</td><td>12,000,000</td>
  <td><a href="/Sims/09121111111">تماس</a></td>
</tr>
<tr>
  <td>0912 222 22 22</td><td>صفر پک</td><td>15,000,000</td>
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
