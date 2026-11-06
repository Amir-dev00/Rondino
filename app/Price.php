<?php

declare(strict_types=1);

final class Price
{
    /**
     * @return array{amount: ?int, type: string, raw: string, unit: ?string}
     */
    public static function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        $normalizedText = Digits::normalize($raw);

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

        if ($isInstallmentContext && !$hasFullPriceCue) {
            return ['amount' => null, 'type' => 'installment', 'raw' => $raw, 'unit' => null];
        }

        $unit = null;
        if (preg_match('/تومان|tooman|toman/ui', $normalizedText)) {
            $unit = 'toman';
        } elseif (preg_match('/ریال|rial/ui', $normalizedText)) {
            $unit = 'rial';
        }

        $amountSource = $normalizedText;
        if (preg_match('/نقدی\s*:?\s*([\d,\.]+)/u', $normalizedText, $m)) {
            $amountSource = $m[1];
            $hasFullPriceCue = true;
        }

        // Accept 130,000,000 and Iranian/EU thousand dots: 130.000.000 (not decimals like 3.5).
        if (!preg_match('/(\d{1,3}(?:[,\s]\d{3})+|\d{1,3}(?:\.\d{3}){1,}|\d{6,})/', $amountSource, $m)) {
            if ($isInstallmentContext) {
                return ['amount' => null, 'type' => 'installment', 'raw' => $raw, 'unit' => $unit];
            }
            return ['amount' => null, 'type' => 'unknown', 'raw' => $raw, 'unit' => $unit];
        }

        $digits = Digits::only($m[1]);
        if ($digits === '' || strlen($digits) > 15) {
            return ['amount' => null, 'type' => 'unknown', 'raw' => $raw, 'unit' => $unit];
        }

        $amount = (int) $digits;
        if ($unit === 'rial') {
            $amount = intdiv($amount, 10);
            $unit = 'toman';
        }
        if ($unit === null) {
            $unit = 'toman';
        }

        return [
            'amount' => $amount > 0 ? $amount : null,
            'type' => $amount > 0 ? 'fixed' : 'unknown',
            'raw' => $raw,
            'unit' => $unit,
        ];
    }
}
