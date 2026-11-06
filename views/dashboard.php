<?php
$stats = Inventory::dashboardStats();
$bySource = Inventory::chartBySource();
$priceDist = Inventory::chartPriceDistribution();
$growth = Inventory::chartInventoryGrowth(30);
$contactProg = Inventory::chartContactProgress();
$sources = Inventory::allSources();

ob_start();
?>
<div class="page-head">
  <div>
    <h1>داشبورد بازار</h1>
    <p class="page-meta">آمار واقعی از پایگاه داده — بدون دادهٔ نمایشی</p>
  </div>
  <div class="page-meta">
    آخرین موجودی: <?= $stats['latest_inventory_at'] ? fa_datetime($stats['latest_inventory_at']) : '—' ?><br>
    آخرین غنی‌سازی تماس: <?= $stats['latest_contact_at'] ? fa_datetime($stats['latest_contact_at']) : '—' ?>
  </div>
</div>

<div class="stat-grid">
  <article class="stat-card">
    <div class="stat-label">شماره یکتا</div>
    <div class="stat-value ltr-num"><?= number_format($stats['unique_sims']) ?></div>
  </article>
  <article class="stat-card">
    <div class="stat-label">کل آگهی‌ها</div>
    <div class="stat-value ltr-num"><?= number_format($stats['total_listings']) ?></div>
  </article>
  <article class="stat-card">
    <div class="stat-label">رکورد تماس فروشنده</div>
    <div class="stat-value ltr-num"><?= number_format($stats['total_contacts']) ?></div>
  </article>
  <article class="stat-card">
    <div class="stat-label">منابع فعال</div>
    <div class="stat-value ltr-num"><?= number_format($stats['active_sources']) ?></div>
  </article>
  <article class="stat-card accent">
    <div class="stat-label">آگهی با تماس تأییدشده</div>
    <div class="stat-value ltr-num"><?= number_format($stats['listings_with_contact']) ?></div>
  </article>
  <article class="stat-card muted-card">
    <div class="stat-label">آگهی بدون تماس</div>
    <div class="stat-value ltr-num"><?= number_format($stats['listings_without_contact']) ?></div>
  </article>
</div>

<div class="chart-grid">
  <section class="panel chart-panel">
    <h2>آگهی‌ها به تفکیک منبع</h2>
    <canvas id="chart-listings" height="220"></canvas>
  </section>
  <section class="panel chart-panel">
    <h2>تماس تأییدشده در برابر بدون تماس</h2>
    <canvas id="chart-contacts" height="220"></canvas>
  </section>
  <section class="panel chart-panel">
    <h2>توزیع قیمت (تومان)</h2>
    <canvas id="chart-price" height="220"></canvas>
  </section>
  <section class="panel chart-panel">
    <h2>رشد موجودی — ۳۰ روز اخیر</h2>
    <canvas id="chart-growth" height="220"></canvas>
  </section>
</div>

<section class="panel">
  <div class="panel-head">
    <h2>میان‌بر موجودی منابع</h2>
    <a href="/?r=sources">سایت‌ها</a>
  </div>
  <div class="source-tiles">
    <?php foreach ($sources as $s):
      $st = Inventory::sourceStats((int) $s['id']);
    ?>
      <a class="source-tile" href="/?r=source&amp;slug=<?= e(urlencode((string) $s['slug'])) ?>">
        <strong><?= e($s['name']) ?></strong>
        <div class="support">
          <?= number_format($st['listings']) ?> آگهی ·
          <?= number_format($st['unique_sims']) ?> شماره ·
          <?= number_format($st['with_contact']) ?> با تماس
        </div>
        <div class="support"><?= $s['last_success_at'] ? fa_datetime($s['last_success_at']) : 'هنوز به‌روزرسانی نشده' ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php
$content = ob_get_clean();
$title = 'داشبورد — روندینو';
$active = 'dashboard';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
$chartPayload = [
    'bySource' => $bySource,
    'priceDist' => $priceDist,
    'growth' => $growth,
    'contactProg' => $contactProg,
];
$extraScripts = '<script>window.RONDINO_CHARTS = ' . json_encode($chartPayload, JSON_UNESCAPED_UNICODE) . ';</script>'
    . '<script src="/js/dashboard.js"></script>';
require __DIR__ . '/layout.php';
