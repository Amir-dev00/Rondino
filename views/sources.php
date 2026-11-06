<?php
$pdo = Database::pdo();
$sources = $pdo->query('SELECT * FROM sources ORDER BY id')->fetchAll();
$csrf = Csrf::token();
$autoMin = (int) config('auto_refresh_minutes', 30);
$crawler = new Crawler();
$progressBySlug = [];
foreach ($crawler->allProgress() as $p) {
    $progressBySlug[$p['slug']] = $p;
}

$latestSuccess = null;
foreach ($sources as $s) {
    if (!empty($s['last_success_at']) && ($latestSuccess === null || $s['last_success_at'] > $latestSuccess)) {
        $latestSuccess = $s['last_success_at'];
    }
}

ob_start();
?>
<div class="page-head">
  <div>
    <h1>وضعیت منابع</h1>
    <p class="page-meta">آخرین بروزرسانی موفق کلی: <?= $latestSuccess ? fa_datetime($latestSuccess) : '—' ?></p>
  </div>
</div>

<div class="panel">
  <input type="hidden" id="page-csrf" name="_csrf" value="<?= e($csrf) ?>">
  <div class="sources-toolbar">
    <div class="actions">
      <button type="button" id="btn-refresh-all" data-csrf="<?= e($csrf) ?>">به‌روزرسانی همه</button>
      <button type="button" id="btn-pause-all" class="secondary" hidden>توقف دسته‌ها</button>
    </div>
    <span class="muted" id="refresh-status">دسته‌های کوتاه؛ بستن تب کار را متوقف می‌کند و با بازگشت از همان نقطه ادامه می‌دهد.</span>
  </div>
  <div class="progress" id="refresh-progress" hidden><span></span></div>
  <div id="refresh-detail" class="muted" style="margin-top:.6rem;white-space:pre-line"></div>
  <p class="muted" style="margin:0">با باز بودن این صفحه، منابع سررسید‌شده هر <?= (int) $autoMin ?> دقیقه یک‌بار به‌روز می‌شوند.</p>
</div>

<div class="panel">
  <table class="table" id="sources-table">
    <thead>
      <tr>
        <th>منبع</th>
        <th>پیشرفت گذر</th>
        <th>آخرین موفقیت</th>
        <th>آگهی‌ها</th>
        <th>وضعیت / خطا</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sources as $s):
        $hasError = !empty($s['last_error']);
        $okRecent = !empty($s['last_success_at']) && !$hasError;
        $prog = $progressBySlug[$s['slug']] ?? null;
    ?>
      <tr data-slug="<?= e($s['slug']) ?>" data-interval="<?= (int) $s['refresh_interval_minutes'] ?>" data-success="<?= e($s['last_success_at'] ?? '') ?>">
        <td>
          <strong><?= e($s['name']) ?></strong>
          <div class="muted" style="overflow-wrap:anywhere"><?= e($s['base_url']) ?></div>
        </td>
        <td class="js-pass-cell">
          <?php if ($prog): ?>
            <div><strong class="js-pass-status"><?= e($prog['status_fa']) ?></strong></div>
            <div class="muted js-pass-label"><?= e((string) ($prog['progress_label'] ?? '—')) ?></div>
            <div class="muted js-pass-stats">
              یکتای گذر: <?= (int) ($prog['unique_offers_pass'] ?? $prog['offers_found'] ?? 0) ?>
              · صفحات: <?= (int) ($prog['pages_fetched'] ?? 0) ?>
              · آخرین دسته: <?= (int) $prog['last_batch_offers'] ?>
              <?php if (!empty($prog['last_batch_at'])): ?>
                · <?= fa_datetime($prog['last_batch_at']) ?>
              <?php endif; ?>
            </div>
            <div class="muted js-pass-coverage">
              <?php
                $covComplete = !empty($prog['inventory_path']);
                $covScope = (string) ($prog['coverage_scope'] ?? '');
                if ($covComplete) {
                    echo 'مسیر پوشش: موجودی لیست API (نه نمونه صفحه اصلی)';
                    if (!empty($prog['source_total_elements'])) {
                        echo ' · کل منبع: ' . (int) $prog['source_total_elements'];
                    }
                } elseif ($covScope === 'homepage_or_html_sample') {
                    echo 'مسیر پوشش: نمونه صفحه اصلی (موجودی کامل نیست)';
                } elseif ($covScope !== '') {
                    echo 'مسیر پوشش: ' . e($covScope);
                } else {
                    echo 'مسیر پوشش: هنوز مشخص نشده';
                }
                if (!empty($prog['stop_reason'])) {
                    echo ' · توقف: ' . e((string) $prog['stop_reason']);
                }
              ?>
            </div>
            <div class="muted js-pass-contact">آگهی دارای تماس (کل سابقه): <?= (int) ($prog['contact_offers'] ?? 0) ?> · شماره یکتای این گذر: <?= (int) ($prog['distinct_sims_pass'] ?? 0) ?></div>
            <div class="muted js-pass-retry">تلاش بعدی: <?= e((string) ($prog['next_retry_at_fa'] ?? '—')) ?></div>
            <div class="muted js-pass-db">DB تاریخی یکتا: <?= (int) ($prog['db_unique_offers'] ?? 0) ?></div>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="last-success <?= $okRecent ? 'status-ok-text' : '' ?>"><?= fa_datetime($s['last_success_at']) ?></td>
        <td class="js-offers-found" title="آگهی‌های یکتای آخرین گذر (جدا از DB تاریخی)">
          <?= (int) ($prog['unique_offers_pass'] ?? $s['last_offers_found']) ?>
          <div class="muted js-db-unique">DB: <?= (int) ($prog['db_unique_offers'] ?? 0) ?></div>
        </td>
        <td class="last-error">
          <?php if ($hasError): ?>
            <span class="status-fail js-err"><?= e($s['last_error']) ?></span>
          <?php else: ?>
            <span class="status-ok-text js-err">بدون خطای اخیر</span>
          <?php endif; ?>
        </td>
        <td class="actions-cell">
          <a class="secondary btn-like" href="/?r=source&amp;slug=<?= e(urlencode((string) $s['slug'])) ?>">موجودی</a>
          <button type="button" class="secondary btn-refresh-one" data-slug="<?= e($s['slug']) ?>" data-csrf="<?= e($csrf) ?>">به‌روزرسانی</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
window.RONDINO_AUTO_REFRESH_MINUTES = <?= (int) $autoMin ?>;
window.RONDINO_CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php
$content = ob_get_clean();
$title = 'وضعیت منابع — روندینو';
$active = 'sources';
require __DIR__ . '/layout.php';

