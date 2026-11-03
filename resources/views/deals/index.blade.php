@extends('layouts.app')

@section('title', 'معاملات تیم')

@section('content')
<div class="grid-2">
    <div class="panel">
        <h1>ثبت معامله تیم</h1>
        <p class="muted">قیمت معاملهٔ واقعی جدا از قیمت درخواستی آگهی‌ها نگه داشته می‌شود.</p>
        <form method="post" action="{{ route('deals.store') }}" class="stack">
            @csrf
            <label>شماره سیم‌کارت
                <input class="ltr" name="number" value="{{ old('number') }}" required placeholder="0912xxxxxxx">
            </label>
            <label>نوع
                <select name="deal_type" required>
                    <option value="buy">خرید</option>
                    <option value="sell">فروش</option>
                </select>
            </label>
            <label>مبلغ (تومان)
                <input class="ltr" type="number" name="amount_toman" value="{{ old('amount_toman') }}" required min="1">
            </label>
            <label>تاریخ
                <input type="date" name="deal_date" value="{{ old('deal_date', now()->toDateString()) }}" required>
            </label>
            <label>یادداشت کوتاه
                <textarea name="note" rows="2" maxlength="500">{{ old('note') }}</textarea>
            </label>
            <button type="submit">ثبت</button>
        </form>
    </div>

    <div class="panel">
        <h2>سوابق</h2>
        @forelse($deals as $deal)
            <div class="card-item" style="margin-bottom:.5rem">
                <div>
                    <a href="{{ route('sims.show', $deal->simNumber->number) }}" class="phone">{{ $deal->simNumber->number }}</a>
                    · {{ $deal->deal_type === 'buy' ? 'خرید' : 'فروش' }}
                </div>
                <div class="price"><span class="ltr">{{ number_format($deal->amount_toman) }}</span> تومان</div>
                <div class="meta">
                    <span class="ltr">{{ $deal->deal_date->format('Y-m-d') }}</span>
                    · {{ $deal->user->name }}
                    · {{ $deal->note }}
                </div>
            </div>
        @empty
            <div class="empty">هنوز معامله‌ای ثبت نشده است.</div>
        @endforelse
        {{ $deals->links() }}
    </div>
</div>
@endsection
