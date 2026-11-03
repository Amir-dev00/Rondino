<?php

namespace App\Services;

final class NumberPatternAnalyzer
{
    /**
     * @return array<string, bool|string>
     */
    public static function analyze(string $number): array
    {
        $rest = substr($number, 4); // 7 digits after 0912
        $flags = [
            'code_digit' => $number[4] ?? '',
            'has_triple_repeat' => (bool) preg_match('/([0-9])\1\1/', $rest),
            'has_pair_repeat' => (bool) preg_match('/([0-9]{2})\1/', $rest),
            'is_sequential_asc' => self::isSequential($rest, 1),
            'is_sequential_desc' => self::isSequential($rest, -1),
            'all_same' => (bool) preg_match('/^([0-9])\1{6}$/', $rest),
        ];

        return $flags;
    }

    private static function isSequential(string $digits, int $step): bool
    {
        if (strlen($digits) < 4) {
            return false;
        }
        $count = 1;
        for ($i = 1; $i < strlen($digits); $i++) {
            $prev = (int) $digits[$i - 1];
            $cur = (int) $digits[$i];
            $expected = ($prev + $step + 10) % 10;
            if ($cur === $expected) {
                $count++;
                if ($count >= 4) {
                    return true;
                }
            } else {
                $count = 1;
            }
        }

        return false;
    }
}
