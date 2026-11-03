@extends('layouts.app')

@section('title', 'برآورد قیمت')

@section('content')
<div class="panel">
    <h1>برآورد بازهٔ قیمت درخواستی</h1>
    <p class="muted">بر اساس آگهی‌های اخیر قابل‌مقایسه. این یک برآورد است، نه پیش‌بینی آینده.</p>

    <form method="get" class="filters" action="{{ route('pricing.show') }}">
        <label>شماره
            <input class="ltr" name="number" value="{{ request('number') }}" required placeholder="0912xxxxxxx">
        </label>
        <label>وضعیت خط
            <select name="condition">
                <option value="">بدون فیلتر وضعیت</option>
                <option value="factory_sealed" @selected(request('condition')==='factory_sealed')>صفر پک</option>
                <option value="like_new" @selected(request('condition')==='like_new')>در حد نو</option>
                <option value="new_unspecified" @selected(request('condition')==='new_unspecified')>صفر</option>
                <option value="used" @selected(request('condition')==='used')>کارکرده</option>
            </select>
        </label>
        <label>&nbsp;<button type="submit">محاسبه</button></label>
    </form>

    @if($estimate)
        @if(! $estimate['sufficient'])
            <div class="empty">{{ $estimate['message'] }}</div>
        @else
            <div class="card-item">
                <div class="badge ok">{{ $estimate['label'] }}</div>
                <h2>
                    <span class="ltr">{{ number_format($estimate['low']) }}</span>
                    تا
                    <span class="ltr">{{ number_format($estimate['high']) }}</span>
                    تومان
                </h2>
                <p class="muted">
                    میانه: <span class="ltr">{{ number_format($estimate['median']) }}</span> ·
                    تعداد سیم‌کارت مستقل: {{ $estimate['sample_size'] }} ·
                    پنجره: {{ $estimate['window_days'] }} روز
                </p>
            </div>
        @endif
    @endif
</div>

@if($evidence->isNotEmpty())
<div class="panel">
    <h2>شواهد قابل‌مقایسه</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>شماره</th><th>قیمت</th><th>وضعیت</th><th>منبع</th><th></th></tr></thead>
            <tbody>
            @foreach($evidence as $offer)
                <tr>
                    <td><span class="phone">{{ $offer->simNumber->number }}</span></td>
                    <td><span class="ltr">{{ number_format($offer->price_amount) }}</span></td>
                    <td>{{ \App\Scraping\Support\ConditionNormalizer::labelFa($offer->condition_normalized) }}</td>
                    <td>{{ $offer->source->name }}</td>
                    <td><a href="{{ route('sims.show', $offer->simNumber->number) }}">مشاهده</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
