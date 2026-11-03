<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Services\PriceEstimateService;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function show(Request $request, PriceEstimateService $estimator)
    {
        $estimate = null;
        $evidence = collect();

        if ($request->filled('number')) {
            $estimate = $estimator->estimate(
                $request->string('number')->toString(),
                $request->input('condition') ?: null
            );
            if (! empty($estimate['evidence_offer_ids'])) {
                $evidence = Offer::query()
                    ->with(['simNumber', 'source'])
                    ->whereIn('id', $estimate['evidence_offer_ids'])
                    ->get();
            }
        }

        return view('pricing.show', compact('estimate', 'evidence'));
    }
}
