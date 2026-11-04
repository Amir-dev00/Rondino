<?php
$pdo = Database::pdo();
$sources = $pdo->query('SELECT * FROM sources ORDER BY id')->fetchAll();
$csrf = Csrf::token();
$autoMin = (int) config('auto_refresh_minutes', 30);
ob_start();
?>
<div class="hero">
  <h1>وضعیت منابع</h1>
  <p>بروزرسانی دسته‌ای و محدود؛ یک سایت کند کل صفحه را قفل نمی‌کند.</p>
</div>
<div class="panel">
  <input type="hidden" id="page-csrf" name="_csrf" value="<?= e($csrf) ?>">
  <div class="actions">
    <button type="button" id="btn-refresh-all" data-csrf="<?= e($csrf) ?>">به‌روزرسانی همه</button>
    <span class="muted" id="refresh-status">آخرین بروزرسانی موفق هر منبع در جدول مشخص است.</span>
  </div>
  <div class="progress" id="refresh-progress" hidden><span></span></div>
  <p class="muted">اگر داشبورد باز بماند، منابع سررسید‌شده هر <?= (int) $autoMin ?> دقیقه یک‌بار با همان منطق بروزرسانی می‌شوند.</p>
</div>
<div class="panel">
  <table class="table" id="sources-table">
    <thead>
      <tr>
        <th>منبع</th>
        <th>آخرین تلاش</th>
        <th>آخرین موفقیت</th>
        <th>آگهی‌ها</th>
        <th>آخرین خطا</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sources as $s): ?>
      <tr data-slug="<?= e($s['slug']) ?>" data-interval="<?= (int) $s['refresh_interval_minutes'] ?>" data-success="<?= e($s['last_success_at'] ?? '') ?>">
        <td>
          <strong><?= e($s['name']) ?></strong><br>
          <a class="muted" href="<?= e($s['list_url']) ?>" target="_blank" rel="noopener"><?= e($s['base_url']) ?></a>
          <?php if ($s['notes']): ?><div class="muted"><?= e($s['notes']) ?></div><?php endif; ?>
        </td>
        <td><?= fa_datetime($s['last_attempt_at']) ?></td>
        <td class="last-success"><?= fa_datetime($s['last_success_at']) ?></td>
        <td><?= (int) $s['last_offers_found'] ?></td>
        <td class="muted last-error"><?= e($s['last_error'] ?? '—') ?></td>
        <td>
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
