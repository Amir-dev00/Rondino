@extends('layouts.app')

@section('title', 'سلامت منابع')

@section('content')
<div class="panel">
    <h1>سلامت ۱۳ منبع</h1>
    <p class="muted">به‌روزرسانی دستی فقط یک کار محدود را زمان‌بندی می‌کند و خزش کامل را در درخواست وب اجرا نمی‌کند.</p>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>منبع</th>
                <th>وضعیت</th>
                <th>آخرین تلاش</th>
                <th>آخرین موفقیت HTTP</th>
                <th>آخرین parse موفق</th>
                <th>آگهی‌ها</th>
                <th>فیلدها</th>
                <th>خطا</th>
                <th>اقدام</th>
            </tr>
            </thead>
            <tbody>
            @foreach($sources as $source)
                <tr>
                    <td>
                        <strong>{{ $source->name }}</strong>
                        <div class="tiny muted">{{ $source->slug }}</div>
                        @if(! $source->shared_hosting_ok)
                            <span class="badge danger">محدودیت هاست</span>
                        @endif
                    </td>
                    <td>
                        @php
                            $cls = match($source->health_status) {
                                'ok' => 'ok',
                                'degraded' => 'warn',
                                'blocked', 'error' => 'danger',
                                default => ''
                            };
                        @endphp
                        <span class="badge {{ $cls }}">{{ $source->health_status }}</span>
                    </td>
                    <td><span class="ltr">{{ optional($source->last_attempt_at)->format('Y-m-d H:i') ?: '—' }}</span></td>
                    <td><span class="ltr">{{ optional($source->last_success_at)->format('Y-m-d H:i') ?: '—' }}</span></td>
                    <td><span class="ltr">{{ optional($source->last_parse_success_at)->format('Y-m-d H:i') ?: '—' }}</span></td>
                    <td>{{ $source->listings_found_last_run }}</td>
                    <td class="tiny">{{ implode('، ', $source->fields_available ?? []) ?: '—' }}</td>
                    <td class="tiny">
                        @if($source->last_error_type)<span class="badge warn">{{ $source->last_error_type }}</span>@endif
                        {{ \Illuminate\Support\Str::limit($source->last_error, 80) }}
                        <div class="muted">{{ \Illuminate\Support\Str::limit($source->capability_notes, 120) }}</div>
                    </td>
                    <td>
                        <form method="post" action="{{ route('admin.sources.refresh', $source) }}">
                            @csrf
                            <button type="submit">زمان‌بندی به‌روزرسانی</button>
                        </form>
                        <div class="tiny muted">بازتلاش: <span class="ltr">{{ optional($source->next_retry_at)->format('Y-m-d H:i') ?: '—' }}</span></div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
