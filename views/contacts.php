<?php
$f = Inventory::parseFilters();
$dates = Inventory::resolveDateRange($f);
$dateFilterError = $dates['ok'] ? null : $dates['error'];
$sources = Inventory::allSources();
$sourceNameById = [];
foreach ($sources as $s) {
    $sourceNameById[(string) $s['id']] = $s['name'];
}

$total = $dates['ok'] ? Inventory::countContacts($f, $dates) : 0;
$pager = Pagination::fromRequest($total, 50);
$rows = $dates['ok'] ? Inventory::listContacts($f, $dates, $pager) : [];
$baseQuery = Inventory::queryParams($f, 'contacts');

$chipAll = static function (array $omit) use ($f): array {
    return Inventory::queryParams($f, 'contacts', $omit);
};
$activeChips = [];
if ($f['phone'] !== '') $activeChips[] = ['label' => 'تلفن: ' . $f['phone'], 'clear' => $chipAll(['phone'])];
if ($f['q'] !== '') $activeChips[] = ['label' => 'سیم: ' . $f['q'], 'clear' => $chipAll(['q'])];
if ($f['source'] !== '') $activeChips[] = ['label' => 'منبع: ' . ($sourceNameById[$f['source']] ?? $f['source']), 'clear' => $chipAll(['source'])];
if ($f['contact_scope'] !== '') $activeChips[] = ['label' => 'نوع: ' . $f['contact_scope'], 'clear' => $chipAll(['contact_scope'])];
$clearAllQuery = ['r' => 'contacts'];

ob_start();
?>
<div class="page-head">
  <div>
    <h1>دفترچه تماس فروشندگان</h1>
    <p class="page-meta">فقط شماره‌های تأییدشدهٔ ذخیره‌شده — بدون حدس یا ساختگی</p>
  </div>
  <div class="page-meta"><?= number_format($total) ?> رکورد تماس</div>
</div>

<div class="panel">
  <form method="get" action="/">
    <input type="hidden" name="r" value="contacts">
    <input type="hidden" name="per" value="<?= (int) $pager->perPage ?>">
    <div class="more-grid filter-grid">
      <label>شماره تلفن فروشنده
        <input class="ltr-num" name="phone" value="<?= e($f['phone']) ?>" placeholder="09…" inputmode="numeric">
      </label>
      <label>شماره سیم‌کارت
        <input class="ltr-num" name="q" value="<?= e($f['q']) ?>" placeholder="0912…" inputmode="numeric">
      </label>
      <label>منبع
        <select name="source">
          <option value="">همه</option>
          <?php foreach ($sources as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $f['source'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>نوع تماس
        <select name="contact_scope">
          <option value="">همه</option>
          <option value="listing" <?= $f['contact_scope'] === 'listing' ? 'selected' : '' ?>>تماس آگهی</option>
          <option value="store" <?= $f['contact_scope'] === 'store' ? 'selected' : '' ?>>تماس فروشگاه</option>
        </select>
      </label>
      <label>مرتب‌سازی
        <select name="sort">
          <option value="" <?= $f['sort'] === '' ? 'selected' : '' ?>>جدیدترین ثبت</option>
          <option value="phone_asc" <?= $f['sort'] === 'phone_asc' ? 'selected' : '' ?>>تلفن</option>
          <option value="sim_asc" <?= $f['sort'] === 'sim_asc' ? 'selected' : '' ?>>سیم</option>
          <option value="price_asc" <?= $f['sort'] === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین آگهی</option>
          <option value="price_desc" <?= $f['sort'] === 'price_desc' ? 'selected' : '' ?>>گران‌ترین آگهی</option>
        </select>
      </label>
    </div>
    <?php if ($dateFilterError): ?><div class="flash error" style="margin-top:.75rem"><?= e($dateFilterError) ?></div><?php endif; ?>
    <div class="tool-actions" style="margin-top:1rem">
      <button type="submit">جست‌وجو</button>
      <a class="secondary btn-like" href="/?r=contacts">پاک کردن</a>
    </div>
    <?php require __DIR__ . '/_filter_chips.php'; ?>
  </form>
</div>

<div class="panel">
  <?php if (!$rows): ?>
    <div class="empty-state">تماس تأییدشده‌ای با این فیلترها نیست.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>تلفن فروشنده</th>
            <th>شماره سیم</th>
            <th>منبع</th>
            <th>نوع</th>
            <th>آگهی</th>
            <th class="col-price">قیمت آگهی</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $scope = $r['scope'] === 'listing' ? 'تماس آگهی' : ($r['scope'] === 'store' ? 'تماس فروشگاه' : (string) $r['scope']);
        ?>
          <tr>
            <td><span class="contact-phone"><?= ltr_phone((string) $r['phone']) ?></span></td>
            <td><a class="sim-link" href="/?r=sim&amp;n=<?= e(urlencode((string) $r['sim_number'])) ?>"><?= ltr_sim((string) $r['sim_number']) ?></a></td>
            <td><a href="/?r=source&amp;slug=<?= e(urlencode((string) $r['source_slug'])) ?>"><?= e($r['source_name']) ?></a></td>
            <td><?= e($scope) ?><?php if (!empty($r['label'])): ?> — <?= e($r['label']) ?><?php endif; ?></td>
            <td class="ltr-num support">#<?= e((string) $r['external_id']) ?></td>
            <td class="col-price"><?= e(format_price_display($r['price_amount'] !== null ? (int) $r['price_amount'] : null, $r['price_type'])) ?></td>
            <td><a href="<?= e($r['canonical_url']) ?>" target="_blank" rel="noopener">لینک</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php require __DIR__ . '/_pagination.php'; ?>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'دفترچه تماس — روندینو';
$active = 'contacts';
require __DIR__ . '/layout.php';
