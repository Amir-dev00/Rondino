<?php

namespace App\Http\Controllers;

use App\Models\SimNumber;
use App\Models\TeamDeal;
use App\Scraping\Support\DigitNormalizer;
use Illuminate\Http\Request;

class DealController extends Controller
{
    public function index()
    {
        $deals = TeamDeal::query()->with(['simNumber', 'user'])->orderByDesc('deal_date')->paginate(40);

        return view('deals.index', compact('deals'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'number' => ['required', 'string'],
            'deal_type' => ['required', 'in:buy,sell'],
            'amount_toman' => ['required', 'integer', 'min:1'],
            'deal_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $normalized = DigitNormalizer::extractSim0912($data['number']);
        if (! $normalized) {
            return back()->withErrors(['number' => 'شماره باید کامل و مطابق ۰۹۱۲xxxxxxx باشد.'])->withInput();
        }

        $sim = SimNumber::findOrCreateFromNumber($normalized);

        TeamDeal::query()->create([
            'user_id' => $request->user()->id,
            'sim_number_id' => $sim->id,
            'deal_type' => $data['deal_type'],
            'amount_toman' => $data['amount_toman'],
            'deal_date' => $data['deal_date'],
            'note' => $data['note'] ?? null,
        ]);

        return redirect()->route('deals.index')->with('status', 'معامله ثبت شد.');
    }
}
