<?php
$pdo = Database::pdo();

$dateFromFa = trim((string) (get_q('from', '') ?? ''));
$dateToFa = trim((string) (get_q('to', '') ?? ''));
$filterError = null;

$sql = 'SELECT d.*, u.name AS user_name FROM team_deals d JOIN users u ON u.id = d.user_id WHERE 1=1';
$params = [];

if ($dateFromFa !== '') {
    $parsed = Jalali::parseDateInput($dateFromFa);
    if (!$parsed['ok']) {
        $filterError = $parsed['error'];
    } else {
        $sql .= ' AND d.deal_date >= ?';
        $params[] = $parsed['ymd'];
    }
}
if ($dateToFa !== '' && $filterError === null) {
    $parsed = Jalali::parseDateInput($dateToFa);
    if (!$parsed['ok']) {
        $filterError = $parsed['error'];
    } else {
        // Inclusive end calendar day (date-only column — no TZ shift)
        $sql .= ' AND d.deal_date <= ?';
        $params[] = $parsed['ymd'];
    }
}

$sql .= ' ORDER BY d.deal_date DESC, d.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deals = $filterError ? [] : $stmt->fetchAll();

$dealDateDefault = Jalali::todayTehranFa();
if (!empty($_SESSION['_deal_date_fa']) && is_string($_SESSION['_deal_date_fa'])) {
    $dealDateDefault = $_SESSION['_deal_date_fa'];
    unset($_SESSION['_deal_date_fa']);
}

ob_start();
?>
<div class="page-head">
  <div>
    <h1>معاملات تیم</h1>
    <p class="page-meta">قیمت خرید و فروش واقعی، جدا از قیمت آگهی · تاریخ‌ها شمسی</p>
  </div>
</div>

<?php if ($filterError): ?>
  <div class="flash error"><?= e($filterError) ?></div>
<?php endif; ?>

<div class="deals-layout">
  <div class="panel">
    <h2 class="panel-title">ثبت معامله</h2>
    <form method="post" action="/?r=deal" class="form-grid">
      <?= Csrf::field() ?>
      <label>شماره
        <input class="ltr-num" name="sim_number" required placeholder="0912…" inputmode="numeric" value="<?= e((string) ($_SESSION['_deal_sim'] ?? '')) ?>">
      </label>
      <label>نوع
        <select name="deal_type">
          <option value="buy">خرید</option>
          <option value="sell">فروش</option>
        </select>
      </label>
      <label>مبلغ <span class="field-hint">(تومان)</span>
        <input class="ltr-num" name="amount_toman" required inputmode="numeric">
      </label>
      <label>تاریخ شمسی
        <input name="deal_date" class="jalali-date" value="<?= e($dealDateDefault) ?>" required placeholder="۱۴۰۵/۰۸/۱۴" autocomplete="off" inputmode="numeric" dir="rtl">
        <span class="field-hint">نمونه: ۱۴۰۵/۰۸/۱۴ (رقم فارسی یا لاتین)</span>
      </label>
      <label>یادداشت کوتاه
        <input name="note" maxlength="500">
      </label>
      <button type="submit">ثبت معامله</button>
    </form>
  </div>

  <div class="panel">
    <h2 class="panel-title">تاریخچه معاملات</h2>
    <form method="get" action="/" class="toolbar" style="margin-bottom:1rem">
      <input type="hidden" name="r" value="deals">
      <label class="tool">از تاریخ
        <input name="from" class="jalali-date" value="<?= e($dateFromFa) ?>" placeholder="۱۴۰۵/۰۱/۰۱" autocomplete="off" dir="rtl">
      </label>
      <label class="tool">تا تاریخ
        <input name="to" class="jalali-date" value="<?= e($dateToFa) ?>" placeholder="۱۴۰۵/۱۲/۲۹" autocomplete="off" dir="rtl">
      </label>
      <button type="submit" class="secondary">فیلتر</button>
    </form>
    <?php if (!$deals): ?>
      <div class="empty-state">هنوز معامله‌ای ثبت نشده<?= ($dateFromFa !== '' || $dateToFa !== '') ? ' (در این بازه)' : '' ?>.</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>تاریخ</th>
            <th>شماره</th>
            <th>نوع</th>
            <th>مبلغ <span class="unit-note">(تومان)</span></th>
            <th>کاربر</th>
            <th>یادداشت</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($deals as $d): ?>
          <tr>
            <td><?= fa_date($d['deal_date']) ?></td>
            <td><?= ltr_sim($d['sim_number']) ?></td>
            <td><?= $d['deal_type'] === 'buy' ? 'خرید' : 'فروش' ?></td>
            <td class="ltr-num"><?= e(format_toman((int) $d['amount_toman'], false)) ?></td>
            <td><?= e($d['user_name']) ?></td>
            <td class="muted"><?= e($d['note'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php
unset($_SESSION['_deal_sim']);
$content = ob_get_clean();
$title = 'معاملات تیم — روندینو';
$active = 'deals';
require __DIR__ . '/layout.php';
