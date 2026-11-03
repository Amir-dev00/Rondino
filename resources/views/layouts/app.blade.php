<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'روندینو') — بازار ۰۹۱۲</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="shell">
    @auth
        <header class="topbar">
            <div class="brand">روندینو</div>
            <nav class="nav" aria-label="منوی اصلی">
                <a href="{{ route('listings.index') }}" class="{{ request()->routeIs('listings.*') ? 'active' : '' }}">آگهی‌ها</a>
                <a href="{{ route('pricing.show') }}" class="{{ request()->routeIs('pricing.*') ? 'active' : '' }}">برآورد قیمت</a>
                <a href="{{ route('deals.index') }}" class="{{ request()->routeIs('deals.*') ? 'active' : '' }}">معاملات تیم</a>
                @if(auth()->user()->is_admin)
                    <a href="{{ route('admin.sources.index') }}" class="{{ request()->routeIs('admin.sources.*') ? 'active' : '' }}">سلامت منابع</a>
                    <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">کاربران</a>
                @endif
                <form method="post" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button type="submit" class="secondary">خروج</button>
                </form>
            </nav>
        </header>
    @endauth

    @if(session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="errors" role="alert">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>
