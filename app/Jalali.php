<?php

declare(strict_types=1);

/**
 * Solar Hijri (Jalali) conversion — single shared implementation for Rondino.
 * Algorithm adapted from the well-tested jalaali-js / Jalali calendar math
 * (same approach used across PHP ports; no external dependency).
 */
final class Jalali
{
    public const TZ_TEHRAN = 'Asia/Tehran';

    /**
     * @return array{0:int,1:int,2:int} [jy, jm, jd]
     */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    /**
     * @return array{0:int,1:int,2:int} [gy, gm, gd]
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30 + 186));
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0, 31, ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) {
            $gd -= $sal_a[$gm];
        }
        return [$gy, $gm, $gd];
    }

    public static function isLeap(int $jy): bool
    {
        $breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
        $bl = count($breaks);
        $jp = $breaks[0];
        $jump = 0;
        for ($i = 1; $i < $bl; $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $jp = $jm;
        }
        $n = $jy - $jp;
        if ($jump - $n < 6) {
            $n = $n - $jump + intdiv($jump + 4, 33) * 33;
        }
        $leap = ((($n + 1) % 33) - 1) % 4;
        if ($leap === -1) {
            $leap = 4;
        }
        return $leap === 0;
    }

    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        return self::isLeap($jy) ? 30 : 29;
    }

    public static function isValid(int $jy, int $jm, int $jd): bool
    {
        if ($jy < 1 || $jm < 1 || $jm > 12 || $jd < 1) {
            return false;
        }
        return $jd <= self::monthLength($jy, $jm);
    }

    /** Format UTC/MySQL datetime for display in Asia/Tehran as Jalali with Persian digits. */
    public static function formatDateTime(?string $utcMysql, bool $withSeconds = false): string
    {
        if ($utcMysql === null || trim($utcMysql) === '') {
            return '—';
        }
        $dt = self::parseStoredUtc($utcMysql);
        if ($dt === null) {
            return '—';
        }
        $dt = $dt->setTimezone(new DateTimeZone(self::TZ_TEHRAN));
        [$jy, $jm, $jd] = self::fromGregorian((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
        $date = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        $time = $withSeconds ? $dt->format('H:i:s') : $dt->format('H:i');
        return Digits::toPersian($date . '، ' . $time);
    }

    /** Format date-only (Y-m-d) without timezone shift — calendar day preserved. */
    public static function formatDateOnly(?string $ymd): string
    {
        if ($ymd === null || trim($ymd) === '') {
            return '—';
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', Digits::normalize($ymd), $m)) {
            return '—';
        }
        [$jy, $jm, $jd] = self::fromGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        return Digits::toPersian(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }

    /** Today's Jalali date in Tehran as Persian digits YYYY/MM/DD. */
    public static function todayTehranFa(): string
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone(self::TZ_TEHRAN));
        [$jy, $jm, $jd] = self::fromGregorian((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
        return Digits::toPersian(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }

    /**
     * Parse user Jalali date (Persian or Latin digits) to Gregorian Y-m-d.
     * @return array{ok:bool, ymd:?string, error:?string}
     */
    public static function parseDateInput(string $input): array
    {
        $raw = trim(Digits::normalize($input));
        $raw = str_replace(['-', '.', ' ', '‌'], '/', $raw);
        $raw = preg_replace('#/+#', '/', $raw) ?? $raw;
        if ($raw === '') {
            return ['ok' => false, 'ymd' => null, 'error' => 'تاریخ را وارد کنید.'];
        }
        if (!preg_match('#^(\d{3,4})/(\d{1,2})/(\d{1,2})$#', $raw, $m)) {
            return ['ok' => false, 'ymd' => null, 'error' => 'قالب تاریخ نامعتبر است. نمونه: ۱۴۰۵/۰۸/۱۴'];
        }
        $jy = (int) $m[1];
        $jm = (int) $m[2];
        $jd = (int) $m[3];
        if ($jy < 1300 || $jy > 1500) {
            return ['ok' => false, 'ymd' => null, 'error' => 'سال شمسی نامعتبر است.'];
        }
        if (!self::isValid($jy, $jm, $jd)) {
            $len = self::monthLength($jy, $jm);
            return ['ok' => false, 'ymd' => null, 'error' => "روز نامعتبر است؛ این ماه {$len} روز دارد."];
        }
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);
        return ['ok' => true, 'ymd' => sprintf('%04d-%02d-%02d', $gy, $gm, $gd), 'error' => null];
    }

    /**
     * Inclusive end of a Jalali calendar day in Tehran → UTC MySQL datetime (end of day).
     * @return array{ok:bool, utc:?string, error:?string}
     */
    public static function rangeEndUtc(string $jalaliInput): array
    {
        $parsed = self::parseDateInput($jalaliInput);
        if (!$parsed['ok']) {
            return ['ok' => false, 'utc' => null, 'error' => $parsed['error']];
        }
        // Local Tehran end of that Gregorian calendar day
        $ymd = $parsed['ymd'];
        $tehran = new DateTimeImmutable($ymd . ' 23:59:59', new DateTimeZone(self::TZ_TEHRAN));
        $utc = $tehran->setTimezone(new DateTimeZone('UTC'));
        return ['ok' => true, 'utc' => $utc->format('Y-m-d H:i:s'), 'error' => null];
    }

    /**
     * Start of a Jalali calendar day in Tehran → UTC MySQL datetime.
     * @return array{ok:bool, utc:?string, error:?string}
     */
    public static function rangeStartUtc(string $jalaliInput): array
    {
        $parsed = self::parseDateInput($jalaliInput);
        if (!$parsed['ok']) {
            return ['ok' => false, 'utc' => null, 'error' => $parsed['error']];
        }
        $tehran = new DateTimeImmutable($parsed['ymd'] . ' 00:00:00', new DateTimeZone(self::TZ_TEHRAN));
        $utc = $tehran->setTimezone(new DateTimeZone('UTC'));
        return ['ok' => true, 'utc' => $utc->format('Y-m-d H:i:s'), 'error' => null];
    }

    private static function parseStoredUtc(string $utcMysql): ?DateTimeImmutable
    {
        $s = trim(Digits::normalize($utcMysql));
        $s = preg_replace('/\s+UTC$/i', '', $s) ?? $s;
        // Accept "Y-m-d H:i:s" or ISO
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
                return new DateTimeImmutable($s, new DateTimeZone('UTC'));
            }
            $ts = strtotime($s . (str_contains(strtoupper($s), 'UTC') || str_contains($s, '+') || str_contains($s, 'Z') ? '' : ' UTC'));
            if ($ts === false) {
                return null;
            }
            return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return null;
        }
    }
}
