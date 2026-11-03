<?php

namespace App\Scraping\Support;

final class PriceNormalizer
{
    /**
     * @return array{amount: ?int, type: string, raw: string, unit: ?string}
     */
    public static function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        $normalizedText = DigitNormalizer::normalizeDigits($raw);

        if ($raw === '') {
            return ['amount' => null, 'type' => 'unknown', 'raw' => $raw, 'unit' => null];
        }

        $lower = mb_strtolower($normalizedText);

        if (preg_match('/تماس|کال\s*فر\s*پرایس|call\s*for\s*price|استعلام/u', $lower)) {
            return ['amount' => null, 'type' => 'call', 'raw' => $raw, 'unit' => null];
        }

        if (preg_match('/توافقی|قابل\s*مذاکره|negotiable/u', $lower)) {
            return ['amount' => null, 'type' => 'negotiable', 'raw' => $raw, 'unit' => null];
        }

        $isInstallmentContext = (bool) preg_match('/پیش[\s\-]*پرداخت|قسط|ماهانه|ماهیانه|installment|اقساط/u', $lower);
        $hasFullPriceCue = (bool) preg_match('/نقدی|قیمت\s*کل|مبلغ\s*کل|قیمت\s*:/u', $lower);

        if ($isInstallmentContext && ! $hasFullPriceCue) {
            // Do not treat down payment / monthly installment as asking price
            return ['amount' => null, 'type' => 'installment', 'raw' => $raw, 'unit' => null];
        }

        $unit = null;
        if (preg_match('/تومان|tooman|toman/ui', $normalizedText)) {
            $unit = 'toman';
        } elseif (preg_match('/ریال|rial/ui', $normalizedText)) {
            $unit = 'rial';
        }

        // Prefer explicit cash price segment when present
        $amountSource = $normalizedText;
        if (preg_match('/نقدی\s*:?\s*([\d,\.]+)/u', $normalizedText, $m)) {
            $amountSource = $m[1];
            $hasFullPriceCue = true;
        }

        if (! preg_match('/(\d{1,3}(?:[,\s]\d{3})+|\d{6,})/', $amountSource, $m)) {
            if ($isInstallmentContext) {
                return ['amount' => null, 'type' => 'installment', 'raw' => $raw, 'unit' => $unit];
            }

            return ['amount' => null, 'type' => 'unknown', 'raw' => $raw, 'unit' => $unit];
        }

        $digits = DigitNormalizer::digitsOnly($m[1]);
        if ($digits === '' || strlen($digits) > 15) {
            return ['amount' => null, 'type' => 'unknown', 'raw' => $raw, 'unit' => $unit];
        }

        $amount = (int) $digits;

        if ($unit === 'rial') {
            // Convert only when unit is explicitly rial
            $amount = intdiv($amount, 10);
            $unit = 'toman';
        }

        // If unit missing but magnitude looks like toman listing prices, accept as toman
        if ($unit === null) {
            $unit = 'toman';
        }

        $type = $isInstallmentContext && $hasFullPriceCue ? 'fixed' : 'fixed';

        return [
            'amount' => $amount > 0 ? $amount : null,
            'type' => $amount > 0 ? $type : 'unknown',
            'raw' => $raw,
            'unit' => $unit,
        ];
    }
}
