@extends('layouts.app')

@section('title', 'کاربران تیم')

@section('content')
<div class="grid-2">
    <div class="panel">
        <h1>ایجاد کاربر تیم</h1>
        <p class="muted">ثبت‌نام عمومی غیرفعال است.</p>
        <form method="post" action="{{ route('admin.users.store') }}" class="stack">
            @csrf
            <label>نام <input name="name" required value="{{ old('name') }}"></label>
            <label>ایمیل <input type="email" name="email" required value="{{ old('email') }}"></label>
            <label>رمز عبور <input type="password" name="password" required minlength="10"></label>
            <label style="display:flex;flex-direction:row;gap:.5rem;align-items:center">
                <input type="checkbox" name="is_admin" value="1"> مدیر
            </label>
            <button type="submit">ایجاد</button>
        </form>
    </div>
    <div class="panel">
        <h2>حساب‌ها</h2>
        @foreach($users as $user)
            <div class="card-item" style="margin-bottom:.5rem;display:flex;justify-content:space-between;gap:1rem;align-items:center">
                <div>
                    <strong>{{ $user->name }}</strong>
                    <div class="meta">{{ $user->email }}
                        @if($user->is_admin)<span class="badge">مدیر</span>@endif
                        @if(! $user->is_active)<span class="badge danger">غیرفعال</span>@endif
                    </div>
                </div>
                <form method="post" action="{{ route('admin.users.toggle', $user) }}">
                    @csrf
                    <button type="submit" class="secondary">{{ $user->is_active ? 'غیرفعال' : 'فعال' }}</button>
                </form>
            </div>
        @endforeach
    </div>
</div>
@endsection
