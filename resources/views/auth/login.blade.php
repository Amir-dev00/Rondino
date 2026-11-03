@extends('layouts.app')

@section('title', 'ورود')

@section('content')
<div class="login-wrap panel">
    <h1>روندینو</h1>
    <p class="muted">ورود خصوصی تیم خرید و فروش سیم‌کارت ۰۹۱۲</p>
    <form method="post" action="{{ route('login') }}" class="stack" style="margin-top:1rem">
        @csrf
        <label>ایمیل
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>
        <label>رمز عبور
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <label style="display:flex;align-items:center;gap:.5rem;flex-direction:row">
            <input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار
        </label>
        <button type="submit">ورود</button>
    </form>
</div>
@endsection
