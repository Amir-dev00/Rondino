<?php
/** @var string $title */
/** @var string $active */
/** @var string $content */
$user = Auth::user();
$flash = flash_get();
$navSources = [];
if ($user) {
    try {
        $navSources = Inventory::allSources();
    } catch (Throwable) {
        $navSources = [];
    }
}
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'روندینو') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css">
  <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body class="<?= $user ? 'app-shell' : 'auth-shell' ?>">
<?php if ($user): ?>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <a href="/?r=dashboard">روند<span>ینو</span></a>
      <p class="sidebar-tag">هوش بازار سیم‌کارت ۰۹۱۲</p>
    </div>
    <nav class="sidebar-nav" aria-label="منوی اصلی">
      <a class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>" href="/?r=dashboard">داشبورد</a>
      <a class="<?= ($active ?? '') === 'market' ? 'active' : '' ?>" href="/?r=market">بازار سراسری</a>
      <a class="<?= ($active ?? '') === 'search' ? 'active' : '' ?>" href="/?r=search">جست‌وجوی پیشرفته</a>
      <a class="<?= ($active ?? '') === 'contacts' ? 'active' : '' ?>" href="/?r=contacts">دفترچه تماس</a>
      <a class="<?= ($active ?? '') === 'sources' ? 'active' : '' ?>" href="/?r=sources">وضعیت خزش</a>
      <a class="<?= ($active ?? '') === 'estimate' ? 'active' : '' ?>" href="/?r=estimate">تخمین قیمت</a>
      <a class="<?= ($active ?? '') === 'deals' ? 'active' : '' ?>" href="/?r=deals">معاملات تیم</a>
    </nav>
    <div class="sidebar-section">
      <div class="sidebar-section-title">موجودی منابع</div>
      <nav class="sidebar-sources" aria-label="منابع">
        <?php foreach ($navSources as $ns): ?>
          <a class="<?= (($active ?? '') === 'source' && ($activeSlug ?? '') === $ns['slug']) ? 'active' : '' ?>"
             href="/?r=source&amp;slug=<?= e(urlencode((string) $ns['slug'])) ?>">
            <?= e($ns['name']) ?>
            <?php if (!(int) $ns['is_enabled']): ?><span class="nav-badge">خاموش</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
    <div class="sidebar-foot">
      <div class="userbox"><?= e($user['name']) ?></div>
      <a href="/?r=logout">خروج</a>
    </div>
  </aside>
  <div class="shell-main">
    <header class="mobile-bar">
      <button type="button" class="nav-toggle" id="nav-toggle" aria-label="منو">☰</button>
      <a class="brand" href="/?r=dashboard">روند<span>ینو</span></a>
      <a href="/?r=logout" class="mobile-logout">خروج</a>
    </header>
    <main class="content-wrap">
      <?php if ($flash): ?>
        <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
  <div class="sidebar-backdrop" id="sidebar-backdrop" hidden></div>
<?php else: ?>
  <main class="wrap auth-main">
    <?php if ($flash): ?>
      <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?= $content ?>
  </main>
<?php endif; ?>
<script src="/js/app.js"></script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
