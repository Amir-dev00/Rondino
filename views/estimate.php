<?php
$sim = Digits::extractSim0912((string) (get_q('sim', '') ?? '')) ?? '';
$condition = get_q('condition', '') ?? '';
$result = null;
if ($sim !== '') {
    $result = (new Estimator())->estimate($sim, $condition !== '' ? $condition : null);
}
ob_start();
?>
<div class="page-head">
  <div>
    <h1>تخمین قیمت</h1>
    <p class="page-meta">برآورد بازه قیمت درخواستی از آگهی‌های قابل‌مقایسهٔ اخیر</p>
  </div>
</div>

<div class="panel">
  <form method="get" action="/" class="toolbar">
    <input type="hidden" name="r" value="estimate">
    <label class="tool" style="flex:2 1 12rem">شماره ۰۹۱۲
      <input class="ltr-num" name="sim" required value="<?= e($sim) ?>" placeholder="09121234567" inputmode="numeric">
    </label>
    <label class="tool">وضعیت خط
      <select name="condition">
        <option value="">بدون فیلتر سخت</option>
        <?php foreach (['factory_sealed','new_unspecified','like_new','used'] as $c): ?>
          <option value="<?= $c ?>" <?= $condition === $c ? 'selected' : '' ?>><?= e(condition_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="tool-actions">
      <button type="submit">تخمین بزن</button>
    </div>
  </form>
</div>

<?php if ($result !== null): ?>
  <div class="estimate-hero">
    <?php if (!$result['ok']): ?>
      <div class="page-meta">نتیجه</div>
      <div class="estimate-range"><?= e($result['message'] ?? 'داده کافی نیست') ?></div>
      <p class="muted">سیم‌های متمایز قابل‌مقایسه: <?= (int) ($result['distinct_sims'] ?? 0) ?> (حداقل ۳ لازم است).</p>
    <?php else: ?>
      <div class="page-meta">بازه پیشنهادی برای <?= ltr_sim($sim) ?></div>
      <div class="estimate-range">
        <?= e(format_toman($result['low'], false)) ?>
        <span class="muted"> تا </span>
        <?= e(format_toman($result['high'], false)) ?>
        <span class="unit">تومان</span>
      </div>
      <p class="muted">
        میانگین تقریبی <?= e(format_toman($result['mid'])) ?>
        · <?= (int) $result['distinct_sims'] ?> سیم متمایز
      </p>
    <?php endif; ?>
  </div>

  <?php if (!empty($result['offers'])): ?>
  <div class="panel">
    <h2 class="panel-title">آگهی‌های پشتیبان</h2>
    <table class="table">
      <thead>
        <tr>
          <th>شماره</th>
          <th>قیمت <span class="unit-note">(تومان)</span></th>
          <th>وضعیت</th>
          <th>منبع</th>
          <th>بررسی</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['offers'] as $o): ?>
        <tr>
          <td><?= ltr_sim($o['sim_number']) ?></td>
          <td class="ltr-num"><?= e(format_toman($o['price_amount'] !== null ? (int) $o['price_amount'] : null, false)) ?></td>
          <td><?= e(condition_label($o['condition_normalized'])) ?></td>
          <td><?= e($o['source_name']) ?></td>
          <td class="muted"><?= fa_datetime($o['last_seen_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'تخمین قیمت — روندینو';
$active = 'estimate';
require __DIR__ . '/layout.php';
