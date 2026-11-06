<?php

declare(strict_types=1);

final class Digits
{
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    private const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    private const LATIN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function normalize(string $input): string
    {
        $out = str_replace(self::PERSIAN, self::LATIN, $input);
        return str_replace(self::ARABIC, self::LATIN, $out);
    }

    /** Convert Latin digits to Persian digits (for display). */
    public static function toPersian(string $input): string
    {
        return str_replace(self::LATIN, self::PERSIAN, $input);
    }

    public static function only(string $input): string
    {
        return preg_replace('/\D+/', '', $input) ?? '';
    }

    public static function extractSim0912(string $input): ?string
    {
        $normalized = self::normalize($input);

        // Never scan URL hosts (e.g. 0912sim.com + /product/0910… fabricates 09120910…).
        if (preg_match('#https?://#i', $normalized)) {
            $parts = parse_url($normalized);
            $normalized = ((string) ($parts['path'] ?? ''))
                . (isset($parts['query']) ? ('?' . $parts['query']) : '')
                . ' '
                . ((string) ($parts['fragment'] ?? ''));
        }

        if (preg_match('/[xX*ـ_…]|\[\.+\]|\.{2,}/u', $normalized)) {
            if (!preg_match('/(?<!\d)0[\s\-._]*9[\s\-._]*1[\s\-._]*2(?:[\s\-._]*\d){7}(?!\d)/u', $normalized)) {
                return null;
            }
        }

        // Require a contiguous 0912 + 7 digits (separators allowed between digits only).
        if (preg_match('/(?<!\d)0[\s\-._]*9[\s\-._]*1[\s\-._]*2(?:[\s\-._]*\d){7}(?!\d)/u', $normalized, $m)) {
            $digits = self::only($m[0]);
            if (preg_match('/^0912[0-9]{7}$/', $digits)) {
                return $digits;
            }
        }

        return null;
    }

    public static function codeDigit(string $sim0912): ?string
    {
        if (!preg_match('/^0912([0-9])[0-9]{6}$/', $sim0912, $m)) {
            return null;
        }
        return $m[1];
    }

    public static function isValid0912(string $number): bool
    {
        return (bool) preg_match('/^0912[0-9]{7}$/', $number);
    }

    /** Simple digit pattern hint for estimation. */
    public static function patternHint(string $sim): string
    {
        $tail = substr($sim, 4); // 7 digits after 0912
        if (preg_match('/^(\d)\1{6}$/', $tail)) {
            return 'all_same';
        }
        if (preg_match('/(\d)\1{3,}/', $tail)) {
            return 'repeat';
        }
        if (preg_match('/0123|1234|2345|3456|4567|5678|6789|9876|8765|7654/', $tail)) {
            return 'sequence';
        }
        return 'plain';
    }
}
