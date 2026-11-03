@extends('layouts.app')

@section('title', $sim->number)

@section('content')
<div class="panel">
    <h1>سیم‌کارت <span class="phone">{{ $sim->number }}</span></h1>
    <p class="muted">کد {{ $sim->code_digit }} · همه پیشنهادها از منابع مختلف</p>
    <p><a href="{{ route('pricing.show', ['number' => $sim->number]) }}">برآورد قیمت این شماره</a></p>
</div>

<div class="panel">
    <h2>پیشنهادها</h2>
    @forelse($sim->offers as $offer)
        <article class="card-item" style="margin-bottom:.75rem">
            <div><strong>{{ $offer->source->name }}</strong>
                <span class="badge">{{ $offer->availability }}</span>
            </div>
            <div class="price">
                @if($offer->price_amount)
                    <span class="ltr">{{ number_format($offer->price_amount) }}</span> تومان
                @else
                    نامشخص ({{ $offer->price_type }})
                @endif
                <div class="tiny muted">متن خام قیمت: {{ $offer->price_raw ?: '—' }}</div>
            </div>
            <div>وضعیت نرمال: {{ \App\Scraping\Support\ConditionNormalizer::labelFa($offer->condition_normalized) }}
                · اصل: {{ $offer->condition_raw ?: '—' }}</div>
            <div>شهر: {{ $offer->city ?: 'اعلام نشده' }}
                @if($offer->city_raw)<span class="tiny muted">({{ $offer->city_raw }})</span>@endif
            </div>
            <div class="meta">
                اولین مشاهده: <span class="ltr">{{ $offer->first_seen_at }}</span> ·
                آخرین مشاهده: <span class="ltr">{{ $offer->last_seen_at }}</span> ·
                آخرین بررسی: <span class="ltr">{{ $offer->last_checked_at }}</span>
            </div>
            <div>
                <a href="{{ $offer->canonical_url }}" target="_blank" rel="noopener noreferrer">لینک منبع</a>
            </div>
            <div style="margin-top:.5rem">
                <strong>تماس‌ها</strong>
                @forelse($offer->contacts as $c)
                    <div>
                        <span class="phone">{{ $c->phone }}</span>
                        <span class="badge">{{ $c->scope }}</span>
                        {{ $c->label }}
                    </div>
                @empty
                    <div class="muted">تماس قابل انتساب ثبت نشده</div>
                @endforelse
                @if($offer->seller_key)
                    <div class="tiny muted">کلید فروشنده/ویترین: {{ $offer->seller_key }}</div>
                @endif
            </div>
            @if($offer->priceObservations->isNotEmpty())
                <details style="margin-top:.5rem">
                    <summary>تاریخچه قیمت</summary>
                    <ul>
                        @foreach($offer->priceObservations as $obs)
                            <li>
                                <span class="ltr">{{ optional($obs->observed_at)->format('Y-m-d H:i') }}</span>
                                —
                                @if($obs->price_amount)
                                    <span class="ltr">{{ number_format($obs->price_amount) }}</span> تومان
                                @else
                                    {{ $obs->price_type }}
                                @endif
                                <span class="tiny muted">{{ $obs->price_raw }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </article>
    @empty
        <div class="empty">پیشنهادی ثبت نشده است.</div>
    @endforelse
</div>

@if($sim->deals->isNotEmpty())
<div class="panel">
    <h2>معاملات تأییدشده تیم (جدا از قیمت درخواستی)</h2>
    <ul>
        @foreach($sim->deals as $deal)
            <li>
                {{ $deal->deal_type === 'buy' ? 'خرید' : 'فروش' }}
                · <span class="ltr">{{ number_format($deal->amount_toman) }}</span> تومان
                · <span class="ltr">{{ $deal->deal_date->format('Y-m-d') }}</span>
                · {{ $deal->note }}
            </li>
        @endforeach
    </ul>
</div>
@endif
@endsection
