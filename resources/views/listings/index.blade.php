@extends('layouts.app')

@section('title', 'آگهی‌های بازار')

@section('content')
<div class="panel">
    <h1>آگهی‌های بازار ۰۹۱۲</h1>
    <p class="muted">جستجو، مقایسه قیمت‌ها (تومان)، تازگی مشاهده و تماس فروشنده</p>

    <form method="get" class="filters" action="{{ route('listings.index') }}">
        <label>شماره
            <input class="ltr" type="search" name="q" value="{{ request('q') }}" placeholder="0912...">
        </label>
        <label>کد بعد از ۰۹۱۲
            <select name="code">
                <option value="">همه</option>
                @foreach(range(0,9) as $d)
                    <option value="{{ $d }}" @selected(request('code') === (string)$d)>{{ $d }}</option>
                @endforeach
            </select>
        </label>
        <label>حداقل قیمت (تومان)
            <input class="ltr" type="number" name="price_min" value="{{ request('price_min') }}">
        </label>
        <label>حداکثر قیمت (تومان)
            <input class="ltr" type="number" name="price_max" value="{{ request('price_max') }}">
        </label>
        <label>وضعیت خط
            <select name="condition">
                <option value="">همه</option>
                <option value="factory_sealed" @selected(request('condition')==='factory_sealed')>صفر پک</option>
                <option value="like_new" @selected(request('condition')==='like_new')>در حد نو / در حد صفر</option>
                <option value="new_unspecified" @selected(request('condition')==='new_unspecified')>صفر</option>
                <option value="used" @selected(request('condition')==='used')>کارکرده</option>
                <option value="unknown" @selected(request('condition')==='unknown')>نامشخص</option>
            </select>
        </label>
        <label>شهر
            <input type="text" name="city" value="{{ request('city') }}" placeholder="خالی = همه">
        </label>
        <label>منبع
            <select name="source_id">
                <option value="">همه</option>
                @foreach($sources as $source)
                    <option value="{{ $source->id }}" @selected((string)request('source_id') === (string)$source->id)>{{ $source->name }}</option>
                @endforeach
            </select>
        </label>
        <label>تازگی (ساعت)
            <select name="fresh_hours">
                <option value="">همه</option>
                <option value="6" @selected(request('fresh_hours')==='6')>۶</option>
                <option value="24" @selected(request('fresh_hours')==='24')>۲۴</option>
                <option value="72" @selected(request('fresh_hours')==='72')>۷۲</option>
            </select>
        </label>
        <label>&nbsp;<button type="submit">اعمال فیلتر</button></label>
    </form>

    @if($offers->isEmpty())
        <div class="empty">آگهی‌ای با این فیلتر پیدا نشد. ابتدا خزش منابع را اجرا کنید.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>شماره</th>
                    <th>قیمت (تومان)</th>
                    <th>وضعیت</th>
                    <th>شهر</th>
                    <th>منبع</th>
                    <th>آخرین مشاهده</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($offers as $offer)
                    @php $stale = $offer->last_seen_at && $offer->last_seen_at->lt(now()->subDay()); @endphp
                    <tr class="{{ $stale ? 'stale' : '' }}">
                        <td><span class="phone ltr">{{ $offer->simNumber->number }}</span></td>
                        <td class="price">
                            @if($offer->price_amount)
                                <span class="ltr">{{ number_format($offer->price_amount) }}</span>
                                <div class="tiny muted">{{ $offer->price_raw }}</div>
                            @else
                                <span class="badge warn">
                                    @switch($offer->price_type)
                                        @case('call') تماس بگیرید @break
                                        @case('negotiable') توافقی @break
                                        @case('installment') اقساطی (مبلغ کامل نامشخص) @break
                                        @default نامشخص
                                    @endswitch
                                </span>
                            @endif
                        </td>
                        <td>
                            {{ \App\Scraping\Support\ConditionNormalizer::labelFa($offer->condition_normalized) }}
                            @if($offer->condition_raw)
                                <div class="tiny muted">اصل: {{ $offer->condition_raw }}</div>
                            @endif
                        </td>
                        <td>{{ $offer->city ?: 'اعلام نشده' }}</td>
                        <td>{{ $offer->source->name }}</td>
                        <td>
                            <span class="ltr">{{ optional($offer->last_seen_at)->format('Y-m-d H:i') }}</span>
                            @if($offer->availability === 'not_observed')
                                <span class="badge warn">دیگر دیده نشد</span>
                            @endif
                        </td>
                        <td><a href="{{ route('sims.show', $offer->simNumber->number) }}">مقایسه</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="cards">
            @foreach($offers as $offer)
                <article class="card-item">
                    <div><strong class="phone">{{ $offer->simNumber->number }}</strong></div>
                    <div class="price">
                        @if($offer->price_amount)
                            <span class="ltr">{{ number_format($offer->price_amount) }}</span> تومان
                        @else
                            قیمت نامشخص
                        @endif
                    </div>
                    <div class="meta">{{ $offer->source->name }} · {{ $offer->city ?: 'اعلام نشده' }}</div>
                    <a href="{{ route('sims.show', $offer->simNumber->number) }}">جزئیات و تماس</a>
                </article>
            @endforeach
        </div>

        <div style="margin-top:1rem">{{ $offers->links() }}</div>
    @endif
</div>
@endsection
