<?php

namespace App\Scraping\Support;

final class ConditionNormalizer
{
    /**
     * @return array{raw: ?string, normalized: string}
     */
    public static function normalize(?string $raw): array
    {
        $raw = $raw !== null ? trim($raw) : null;
        if ($raw === null || $raw === '') {
            return ['raw' => $raw, 'normalized' => 'unknown'];
        }

        $text = DigitNormalizer::normalizeDigits($raw);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // Factory sealed — only explicit sealed packaging wording
        if (preg_match('/صفر\s*پک|صفرپک|آکبند\s*پک|پک\s*آکبند|پک\s*بسته|کارخانه/u', $text)) {
            return ['raw' => $raw, 'normalized' => 'factory_sealed'];
        }

        // Like new
        if (preg_match('/در\s*حد\s*نو|در\s*حد\s*صفر/u', $text)) {
            return ['raw' => $raw, 'normalized' => 'like_new'];
        }

        // Used
        if (preg_match('/کارکرده|کار کرده/u', $text)) {
            return ['raw' => $raw, 'normalized' => 'used'];
        }

        // New unspecified packaging — "صفر" alone must NOT become factory_sealed
        if (preg_match('/(^|[^\p{L}])صفر([^\p{L}]|$)|آکبند(?!\s*پک)/u', $text)) {
            return ['raw' => $raw, 'normalized' => 'new_unspecified'];
        }

        // URL path segments
        if (preg_match('/\bused\b/i', $text)) {
            return ['raw' => $raw, 'normalized' => 'used'];
        }
        if (preg_match('/\bnew\b/i', $text)) {
            return ['raw' => $raw, 'normalized' => 'new_unspecified'];
        }

        return ['raw' => $raw, 'normalized' => 'unknown'];
    }

    public static function labelFa(string $normalized): string
    {
        return match ($normalized) {
            'factory_sealed' => 'صفر پک',
            'used' => 'کارکرده',
            'like_new' => 'در حد نو',
            'new_unspecified' => 'صفر',
            default => 'نامشخص',
        };
    }
}
