<?php
$sim = Digits::extractSim0912((string) (get_q('sim', '') ?? '')) ?? '';
$condition = get_q('condition', '') ?? '';
$result = null;
if ($sim !== '') {
    $result = (new Estimator())->estimate($sim, $condition !== '' ? $condition : null);
}
ob_start();
?>
<div class="hero">
  <h1>تخمین قیمت</h1>
  <p>برآورد بازه قیمت درخواستی بر اساس آگهی‌های قابل‌مقایسهٔ اخیر.</p>
</div>
<div class="panel">
  <form class="filters" method="get" action="/">
    <input type="hidden" name="r" value="estimate">
    <label>شماره ۰۹۱۲
      <input class="ltr" name="sim" required value="<?= e($sim) ?>" placeholder="09121234567">
    </label>
    <label>وضعیت خط (اختیاری)
      <select name="condition">
        <option value="">بدون فیلتر سخت</option>
        <?php foreach (['factory_sealed','new_unspecified','like_new','used'] as $c): ?>
          <option value="<?= $c ?>" <?= $condition === $c ? 'selected' : '' ?>><?= e(condition_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">تخمین بزن</button>
  </form>
</div>
<?php if ($result !== null): ?>
<div class="panel">
  <?php if (!$result['ok']): ?>
    <h2><?= e($result['message'] ?? 'داده کافی نیست') ?></h2>
    <p class="muted">تعداد سیم‌های متمایز قابل‌مقایسه: <?= (int) ($result['distinct_sims'] ?? 0) ?> (حداقل ۳ لازم است).</p>
  <?php else: ?>
    <h2>بازه پیشنهادی</h2>
    <p><strong><?= e(format_toman($result['low'])) ?></strong> تا <strong><?= e(format_toman($result['high'])) ?></strong></p>
    <p class="muted">میانگین تقریبی: <?= e(format_toman($result['mid'])) ?> · تعداد سیم‌های متمایز: <?= (int) $result['distinct_sims'] ?></p>
  <?php endif; ?>
  <?php if (!empty($result['offers'])): ?>
    <h3>آگهی‌های پشتیبان</h3>
    <table class="table">
      <thead><tr><th>شماره</th><th>قیمت</th><th>وضعیت</th><th>منبع</th><th>بررسی</th></tr></thead>
      <tbody>
      <?php foreach ($result['offers'] as $o): ?>
        <tr>
          <td class="ltr"><?= e($o['sim_number']) ?></td>
          <td><?= e(format_toman($o['price_amount'] !== null ? (int) $o['price_amount'] : null)) ?></td>
          <td><?= e(condition_label($o['condition_normalized'])) ?></td>
          <td><?= e($o['source_name']) ?></td>
          <td><?= fa_datetime($o['last_seen_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'تخمین قیمت — روندینو';
$active = 'estimate';
require __DIR__ . '/layout.php';
