<?php

declare(strict_types=1);

final class Estimator
{
    /**
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   low?: int,
     *   high?: int,
     *   mid?: int,
     *   distinct_sims?: int,
     *   offers?: list<array<string,mixed>>,
     *   sim?: string,
     *   code?: ?string,
     *   pattern?: string
     * }
     */
    public function estimate(string $sim, ?string $condition = null): array
    {
        if (!Digits::isValid0912($sim)) {
            return ['ok' => false, 'message' => 'شماره باید ۱۱ رقم و با ۰۹۱۲ شروع شود.'];
        }

        $code = Digits::codeDigit($sim);
        $pattern = Digits::patternHint($sim);
        $pdo = Database::pdo();

        $sql = 'SELECT o.*, s.number AS sim_number, src.name AS source_name
                FROM offers o
                JOIN sim_numbers s ON s.id = o.sim_number_id
                JOIN sources src ON src.id = o.source_id
                WHERE o.availability = \'available\'
                  AND o.price_type = \'fixed\'
                  AND o.price_amount IS NOT NULL
                  AND s.code_digit = ?';
        $params = [$code];

        if ($condition && $condition !== '' && $condition !== 'unknown') {
            $sql .= ' AND o.condition_normalized = ?';
            $params[] = $condition;
        }

        $sql .= ' ORDER BY o.last_seen_at DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Prefer same digit pattern among recent rows
        $filtered = [];
        foreach ($rows as $row) {
            if (Digits::patternHint($row['sim_number']) === $pattern) {
                $filtered[] = $row;
            }
        }
        if (count($filtered) >= 3) {
            $rows = $filtered;
        }

        $bySim = [];
        foreach ($rows as $row) {
            $n = $row['sim_number'];
            if (!isset($bySim[$n])) {
                $bySim[$n] = $row; // latest per SIM due to order
            }
        }

        $distinct = count($bySim);
        if ($distinct < 3) {
            return [
                'ok' => false,
                'message' => 'داده کافی نیست',
                'distinct_sims' => $distinct,
                'sim' => $sim,
                'code' => $code,
                'pattern' => $pattern,
                'offers' => array_values($bySim),
            ];
        }

        $amounts = array_map(fn ($r) => (int) $r['price_amount'], array_values($bySim));
        sort($amounts);
        $low = $amounts[(int) floor((count($amounts) - 1) * 0.25)];
        $high = $amounts[(int) floor((count($amounts) - 1) * 0.75)];
        $mid = (int) round(array_sum($amounts) / count($amounts));

        return [
            'ok' => true,
            'low' => $low,
            'high' => $high,
            'mid' => $mid,
            'distinct_sims' => $distinct,
            'offers' => array_values($bySim),
            'sim' => $sim,
            'code' => $code,
            'pattern' => $pattern,
        ];
    }
}
