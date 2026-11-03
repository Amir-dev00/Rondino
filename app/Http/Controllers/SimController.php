<?php

namespace App\Http\Controllers;

use App\Models\SimNumber;
use App\Scraping\Support\DigitNormalizer;

class SimController extends Controller
{
    public function show(string $number)
    {
        $normalized = DigitNormalizer::extractSim0912($number);
        abort_unless($normalized, 404);

        $sim = SimNumber::query()
            ->where('number', $normalized)
            ->with([
                'offers' => fn ($q) => $q->with(['source', 'contacts', 'priceObservations'])->orderByDesc('last_seen_at'),
                'deals' => fn ($q) => $q->with('user')->orderByDesc('deal_date'),
            ])
            ->firstOrFail();

        return view('sims.show', compact('sim'));
    }
}
