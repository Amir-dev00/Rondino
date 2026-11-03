<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\SimNumber;
use App\Scraping\Support\DigitNormalizer;
use Illuminate\Support\Collection;

final class PriceEstimateService
{
    /**
     * Explainable baseline estimate from recent comparable asking prices.
     *
     * @return array{
     *   sufficient: bool,
     *   message: string,
     *   low: ?int,
     *   high: ?int,
     *   median: ?int,
     *   sample_size: int,
     *   window_days: int,
     *   comparable_sim_ids: list<int>,
     *   evidence_offer_ids: list<int>,
     *   label: string
     * }
     */
    public function estimate(string $number, ?string $conditionNormalized = null): array
    {
        $cfg = config('rondino.price_estimate');
        $windowDays = (int) $cfg['window_days'];
        $min = (int) $cfg['min_comparables'];
        $number = DigitNormalizer::extractSim0912($number) ?? $number;

        if (! DigitNormalizer::isValid0912($number)) {
            return $this->insufficient('شماره معتبر ۰۹۱۲ نیست.', $windowDays);
        }

        $code = DigitNormalizer::codeDigit($number);
        $patterns = NumberPatternAnalyzer::analyze($number);
        $since = now()->subDays($windowDays);

        $offers = Offer::query()
            ->with('simNumber')
            ->where('availability', 'available')
            ->whereNotNull('price_amount')
            ->where('price_type', 'fixed')
            ->where('last_seen_at', '>=', $since)
            ->whereHas('simNumber', function ($q) use ($code) {
                $q->where('code_digit', $code);
            })
            ->when($conditionNormalized && $conditionNormalized !== 'unknown', function ($q) use ($conditionNormalized) {
                $q->where('condition_normalized', $conditionNormalized);
            })
            ->get();

        // Group by SIM so reposts cannot dominate
        $bySim = $offers->groupBy('sim_number_id')->map(function (Collection $group) use ($patterns) {
            /** @var Offer $best */
            $best = $group->sortByDesc('last_seen_at')->first();
            $simPatterns = $best->simNumber?->pattern_flags ?? [];
            $score = 0;
            foreach (['has_triple_repeat', 'has_pair_repeat', 'is_sequential_asc', 'is_sequential_desc', 'all_same'] as $flag) {
                if (! empty($patterns[$flag]) && ! empty($simPatterns[$flag])) {
                    $score++;
                }
            }

            return [
                'sim_id' => $best->sim_number_id,
                'offer_id' => $best->id,
                'price' => (int) $best->price_amount,
                'score' => $score,
            ];
        });

        // Prefer pattern-similar SIMs when enough exist
        $preferred = $bySim->filter(fn ($r) => $r['score'] > 0);
        $pool = $preferred->count() >= $min ? $preferred : $bySim;

        if ($pool->count() < $min) {
            return $this->insufficient('داده کافی نیست', $windowDays, $pool->count());
        }

        $prices = $pool->pluck('price')->sort()->values();
        $low = $this->percentile($prices, (float) $cfg['percentile_low']);
        $high = $this->percentile($prices, (float) $cfg['percentile_high']);
        $median = $this->percentile($prices, 50.0);

        return [
            'sufficient' => true,
            'message' => 'برآورد بازهٔ قیمت درخواستی فعلی بر اساس آگهی‌های قابل‌مقایسه',
            'low' => $low,
            'high' => $high,
            'median' => $median,
            'sample_size' => $pool->count(),
            'window_days' => $windowDays,
            'comparable_sim_ids' => $pool->pluck('sim_id')->values()->all(),
            'evidence_offer_ids' => $pool->pluck('offer_id')->values()->all(),
            'label' => 'برآورد قیمت درخواستی (نه پیش‌بینی آینده)',
        ];
    }

    /**
     * @param  Collection<int, int>  $sorted
     */
    private function percentile(Collection $sorted, float $p): int
    {
        $values = $sorted->values();
        $n = $values->count();
        if ($n === 1) {
            return (int) $values[0];
        }
        $idx = ($p / 100) * ($n - 1);
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);
        if ($lo === $hi) {
            return (int) $values[$lo];
        }
        $w = $idx - $lo;

        return (int) round($values[$lo] * (1 - $w) + $values[$hi] * $w);
    }

    /**
     * @return array<string, mixed>
     */
    private function insufficient(string $message, int $windowDays, int $sample = 0): array
    {
        return [
            'sufficient' => false,
            'message' => $message === 'داده کافی نیست' ? 'داده کافی نیست' : $message,
            'low' => null,
            'high' => null,
            'median' => null,
            'sample_size' => $sample,
            'window_days' => $windowDays,
            'comparable_sim_ids' => [],
            'evidence_offer_ids' => [],
            'label' => 'برآورد قیمت درخواستی (نه پیش‌بینی آینده)',
        ];
    }
}
