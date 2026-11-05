<?php
/** @var string $title */
/** @var string $active */
/** @var string $content */
$user = Auth::user();
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'روندینو') ?></title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="/?r=market">روند<span>ینو</span></a>
    <?php if ($user): ?>
    <nav class="nav" aria-label="بخش‌های اصلی">
      <a class="<?= ($active ?? '') === 'market' ? 'active' : '' ?>" href="/?r=market">بازار خطوط</a>
      <a class="<?= ($active ?? '') === 'estimate' ? 'active' : '' ?>" href="/?r=estimate">تخمین قیمت</a>
      <a class="<?= ($active ?? '') === 'deals' ? 'active' : '' ?>" href="/?r=deals">معاملات تیم</a>
      <a class="<?= ($active ?? '') === 'sources' ? 'active' : '' ?>" href="/?r=sources">وضعیت منابع</a>
    </nav>
    <div class="userbox"><?= e($user['name']) ?> · <a href="/?r=logout">خروج</a></div>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
  <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
  <?php endif; ?>
  <?= $content ?>
</main>
<script src="/js/app.js"></script>
</body>
</html>
