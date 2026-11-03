<?php

namespace App\Scraping\Support;

final class DigitNormalizer
{
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const LATIN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function normalizeDigits(string $input): string
    {
        $out = str_replace(self::PERSIAN, self::LATIN, $input);
        $out = str_replace(self::ARABIC, self::LATIN, $out);

        return $out;
    }

    /**
     * Extract a complete 0912 number. Never reconstruct masked digits.
     */
    public static function extractSim0912(string $input): ?string
    {
        if (preg_match('/[xX*ـ_…]|\[\.+\]|\.{2,}/u', $input)) {
            // Masked / incomplete markers — refuse reconstruction
            if (! preg_match('/0912[0-9]{7}/', self::digitsOnly(self::normalizeDigits($input)))) {
                return null;
            }
        }

        $normalized = self::normalizeDigits($input);
        $normalized = str_replace(['+', ' '], '', $normalized);

        // Explicit Iranian international forms
        $normalized = preg_replace('/^(?:0098|098|98)/', '0', $normalized) ?? $normalized;

        $digits = self::digitsOnly($normalized);

        if (preg_match('/(^|[^0-9])(0912[0-9]{7})([^0-9]|$)/', $digits, $m)) {
            return $m[2];
        }

        // Digits may already be contiguous after stripping separators
        if (preg_match('/0912[0-9]{7}/', $digits, $m)) {
            // Prefer leftmost complete match; ensure not longer ambiguous prefix
            return substr($m[0], 0, 11);
        }

        // Spaced digit groups like "0 9 1 2 1 2 7 5 0 0 0"
        $spaced = preg_replace('/[^0-9]/', '', self::normalizeDigits($input)) ?? '';
        if (preg_match('/0912[0-9]{7}/', $spaced, $m)) {
            return substr($m[0], 0, 11);
        }

        return null;
    }

    public static function codeDigit(string $sim0912): ?string
    {
        if (! preg_match('/^0912([0-9])[0-9]{6}$/', $sim0912, $m)) {
            return null;
        }

        return $m[1];
    }

    public static function digitsOnly(string $input): string
    {
        return preg_replace('/\D+/', '', $input) ?? '';
    }

    public static function isValid0912(string $number): bool
    {
        return (bool) preg_match('/^0912[0-9]{7}$/', $number);
    }
}
