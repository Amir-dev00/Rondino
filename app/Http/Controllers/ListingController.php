<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Source;
use App\Scraping\Support\DigitNormalizer;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    public function index(Request $request)
    {
        $q = Offer::query()
            ->with(['simNumber', 'source', 'contacts'])
            ->whereIn('availability', ['available', 'not_observed']);

        if ($search = $request->string('q')->toString()) {
            $digits = DigitNormalizer::digitsOnly(DigitNormalizer::normalizeDigits($search));
            $q->whereHas('simNumber', fn ($s) => $s->where('number', 'like', '%'.$digits.'%'));
        }

        if ($request->filled('code')) {
            $q->whereHas('simNumber', fn ($s) => $s->where('code_digit', $request->string('code')->toString()));
        }

        if ($request->filled('price_min')) {
            $q->where('price_amount', '>=', (int) $request->input('price_min'));
        }
        if ($request->filled('price_max')) {
            $q->where('price_amount', '<=', (int) $request->input('price_max'));
        }
        if ($request->filled('condition')) {
            $q->where('condition_normalized', $request->string('condition')->toString());
        }
        if ($request->filled('city')) {
            $q->where('city', $request->string('city')->toString());
        }
        if ($request->filled('source_id')) {
            $q->where('source_id', (int) $request->input('source_id'));
        }
        if ($request->filled('fresh_hours')) {
            $q->where('last_seen_at', '>=', now()->subHours((int) $request->input('fresh_hours')));
        }

        $offers = $q->orderByDesc('last_seen_at')->paginate(30)->withQueryString();
        $sources = Source::query()->orderBy('name')->get();

        return view('listings.index', compact('offers', 'sources'));
    }
}
