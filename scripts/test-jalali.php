<?php

declare(strict_types=1);

/**
 * Jalali regression tests for Rondino.
 * Exit 0 on success, 1 on failure.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;

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

// 1) Exact audit case
assert_eq(
    'UTC 2026-11-04 22:02:06 → Tehran Jalali with seconds',
    Jalali::formatDateTime('2026-11-04 22:02:06', true),
    Digits::toPersian('1405/08/14، 01:32:06')
);

// Without seconds (UI default)
assert_eq(
    'UTC datetime without seconds',
    Jalali::formatDateTime('2026-11-04 22:02:06', false),
    Digits::toPersian('1405/08/14، 01:32')
);

// 2) Nowruz 1405 = 2026-03-21
assert_eq(
    'Nowruz 1405 from Gregorian 2026-03-21',
    Jalali::formatDateOnly('2026-03-21'),
    Digits::toPersian('1405/01/01')
);
$ny = Jalali::parseDateInput('1405/01/01');
assert_eq('Nowruz parse to Gregorian', $ny['ymd'] ?? null, '2026-03-21');

$nyFa = Jalali::parseDateInput('۱۴۰۵/۰۱/۰۱');
assert_eq('Nowruz Persian digits parse', $nyFa['ymd'] ?? null, '2026-03-21');

// 3) Leap-year Esfand boundary — 1403 is leap in Jalali (Esfand 30)
assert_true('1403 is leap', Jalali::isLeap(1403));
assert_eq('1403 Esfand length', Jalali::monthLength(1403, 12), 30);
$leapDay = Jalali::parseDateInput('1403/12/30');
assert_true('1403/12/30 valid', (bool) ($leapDay['ok'] ?? false));
assert_eq('1403/12/30 gregorian', $leapDay['ymd'] ?? null, '2025-03-20');

$nonLeap = Jalali::isLeap(1404);
assert_true('1404 not leap', !$nonLeap);
$bad = Jalali::parseDateInput('1404/12/30');
assert_true('1404/12/30 invalid', !($bad['ok'] ?? true));
assert_true('invalid leap error is Persian', is_string($bad['error'] ?? null) && $bad['error'] !== '');

// 4) Tehran midnight crossing: 2026-11-04 20:30:00 UTC = 2026-11-05 00:00 Tehran
assert_eq(
    'Tehran midnight crossing',
    Jalali::formatDateTime('2026-11-04 20:30:00', true),
    Digits::toPersian('1405/08/14، 00:00:00')
);
// Just before midnight Tehran
assert_eq(
    'Just before Tehran midnight',
    Jalali::formatDateTime('2026-11-04 20:29:00', true),
    Digits::toPersian('1405/08/13، 23:59:00')
);

// 5) Deal date entered and reopened unchanged (date-only, no TZ)
$entered = '۱۴۰۵/۰۸/۱۴';
$parsed = Jalali::parseDateInput($entered);
assert_true('deal parse ok', (bool) $parsed['ok']);
$stored = $parsed['ymd'];
$reopened = Jalali::formatDateOnly($stored);
assert_eq('deal date round-trip', $reopened, Digits::toPersian('1405/08/14'));

// Latin digits input also works
$lat = Jalali::parseDateInput('1405/8/14');
assert_eq('latin digits deal parse', $lat['ymd'] ?? null, $stored);

// 6) Missing dates
assert_eq('empty datetime', Jalali::formatDateTime(null), '—');
assert_eq('empty date', Jalali::formatDateOnly(''), '—');
assert_eq('fa_datetime empty', fa_datetime(null), '—');

// 7) Inclusive end range in Tehran for timestamp filters
$end = Jalali::rangeEndUtc('1405/08/14');
assert_true('range end ok', (bool) $end['ok']);
$endDt = new DateTimeImmutable($end['utc'], new DateTimeZone('UTC'));
$endTehran = $endDt->setTimezone(new DateTimeZone('Asia/Tehran'));
assert_eq('range end local time', $endTehran->format('Y-m-d H:i:s'), '2026-11-05 23:59:59');

$start = Jalali::rangeStartUtc('1405/08/14');
$startDt = (new DateTimeImmutable($start['utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tehran'));
assert_eq('range start local time', $startDt->format('Y-m-d H:i:s'), '2026-11-05 00:00:00');

// 8) Same instant derives consistent Y/M/D/H
$fmt = Jalali::formatDateTime('2026-11-04 22:02:06', true);
assert_true('contains slash date and time', str_contains($fmt, Digits::toPersian('1405/08/14')) && str_contains($fmt, Digits::toPersian('01:32:06')));

echo $failed === 0 ? "\nALL PASSED\n" : "\n$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
