<?php
$pdo = Database::pdo();
$deals = $pdo->query(
    'SELECT d.*, u.name AS user_name FROM team_deals d JOIN users u ON u.id = d.user_id ORDER BY d.deal_date DESC, d.id DESC LIMIT 100'
)->fetchAll();
ob_start();
?>
<div class="hero">
  <h1>معاملات تیم</h1>
  <p>قیمت خرید/فروش واقعی تیم جدا از قیمت‌های آگهی ذخیره می‌شود.</p>
</div>
<div class="panel">
  <form method="post" action="/?r=deal" class="filters">
    <?= Csrf::field() ?>
    <label>شماره
      <input class="ltr" name="sim_number" required placeholder="0912...">
    </label>
    <label>نوع
      <select name="deal_type">
        <option value="buy">خرید</option>
        <option value="sell">فروش</option>
      </select>
    </label>
    <label>مبلغ (تومان)
      <input class="ltr" name="amount_toman" required>
    </label>
    <label>تاریخ
      <input type="date" name="deal_date" value="<?= e(date('Y-m-d')) ?>" required>
    </label>
    <label>یادداشت کوتاه
      <input name="note" maxlength="500">
    </label>
    <button type="submit">ثبت معامله</button>
  </form>
</div>
<div class="panel">
  <table class="table">
    <thead><tr><th>تاریخ</th><th>شماره</th><th>نوع</th><th>مبلغ</th><th>کاربر</th><th>یادداشت</th></tr></thead>
    <tbody>
    <?php if (!$deals): ?>
      <tr><td colspan="6" class="muted">هنوز معامله‌ای ثبت نشده.</td></tr>
    <?php else: foreach ($deals as $d): ?>
      <tr>
        <td><?= e($d['deal_date']) ?></td>
        <td class="ltr"><?= e($d['sim_number']) ?></td>
        <td><?= $d['deal_type'] === 'buy' ? 'خرید' : 'فروش' ?></td>
        <td><?= e(format_toman((int) $d['amount_toman'])) ?></td>
        <td><?= e($d['user_name']) ?></td>
        <td><?= e($d['note'] ?? '') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
$title = 'معاملات تیم — روندینو';
$active = 'deals';
require __DIR__ . '/layout.php';
