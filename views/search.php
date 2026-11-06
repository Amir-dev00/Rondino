<?php
$f = Inventory::parseFilters();
$dates = Inventory::resolveDateRange($f);
$dateFilterError = $dates['ok'] ? null : $dates['error'];
$sources = Inventory::allSources();
$sourceNameById = [];
foreach ($sources as $s) {
    $sourceNameById[(string) $s['id']] = $s['name'];
}

$total = $dates['ok'] ? Inventory::countOffers($f, $dates) : 0;
$pager = Pagination::fromRequest($total, 50);
$offers = $dates['ok'] ? Inventory::listOffers($f, $dates, $pager) : [];
$contactsByOffer = Inventory::contactsByOfferIds(array_map(static fn ($o) => (int) $o['id'], $offers));
$baseQuery = Inventory::queryParams($f, 'search');

$chipAll = static function (array $omit) use ($f): array {
    return Inventory::queryParams($f, 'search', $omit);
};
$activeChips = [];
if ($f['q'] !== '') $activeChips[] = ['label' => 'سیم: ' . $f['q'], 'clear' => $chipAll(['q'])];
if ($f['phone'] !== '') $activeChips[] = ['label' => 'تلفن: ' . $f['phone'], 'clear' => $chipAll(['phone'])];
if ($f['code'] !== '') $activeChips[] = ['label' => 'کد: ' . $f['code'], 'clear' => $chipAll(['code'])];
if ($f['condition'] !== '') $activeChips[] = ['label' => 'وضعیت: ' . condition_label($f['condition']), 'clear' => $chipAll(['condition'])];
if ($f['availability'] !== '') $activeChips[] = ['label' => 'موجودی: ' . $f['availability'], 'clear' => $chipAll(['availability'])];
if ($f['has_contact'] === '1') $activeChips[] = ['label' => 'دارای تماس', 'clear' => $chipAll(['has_contact'])];
if ($f['has_contact'] === '0') $activeChips[] = ['label' => 'بدون تماس', 'clear' => $chipAll(['has_contact'])];
if ($f['price_type'] !== '') $activeChips[] = ['label' => 'نوع قیمت: ' . price_type_label($f['price_type']), 'clear' => $chipAll(['price_type'])];
if ($f['city'] !== '') $activeChips[] = ['label' => 'شهر: ' . $f['city'], 'clear' => $chipAll(['city'])];
if ($f['source'] !== '') $activeChips[] = ['label' => 'منبع: ' . ($sourceNameById[$f['source']] ?? $f['source']), 'clear' => $chipAll(['source'])];
if ($f['min'] !== '') $activeChips[] = ['label' => 'حداقل: ' . number_format((int) $f['min']), 'clear' => $chipAll(['min'])];
if ($f['max'] !== '') $activeChips[] = ['label' => 'حداکثر: ' . number_format((int) $f['max']), 'clear' => $chipAll(['max'])];
if ($f['seen_from'] !== '') $activeChips[] = ['label' => 'از: ' . $f['seen_from'], 'clear' => $chipAll(['seen_from'])];
if ($f['seen_to'] !== '') $activeChips[] = ['label' => 'تا: ' . $f['seen_to'], 'clear' => $chipAll(['seen_to'])];
$clearAllQuery = ['r' => 'search'];

ob_start();
?>
<div class="page-head">
  <div>
    <h1>جست‌وجوی پیشرفته بازار</h1>
    <p class="page-meta">فیلتر ترکیبی روی تک‌تک آگهی‌ها با صفحه‌بندی سمت سرور</p>
  </div>
  <div class="page-meta"><?= number_format($total) ?> آگهی</div>
</div>

<div class="panel">
  <form method="get" action="/">
    <input type="hidden" name="r" value="search">
    <input type="hidden" name="per" value="<?= (int) $pager->perPage ?>">
    <div class="more-grid filter-grid">
      <label>شماره سیم (کامل یا جزئی)
        <input class="ltr-num" name="q" value="<?= e($f['q']) ?>" placeholder="0912…" inputmode="numeric">
      </label>
      <label>رقم کد
        <select name="code">
          <option value="">همه</option>
          <?php for ($i = 0; $i <= 9; $i++): ?>
            <option value="<?= $i ?>" <?= $f['code'] === (string) $i ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label>منبع
        <select name="source">
          <option value="">همه</option>
          <?php foreach ($sources as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $f['source'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>وضعیت خط
        <select name="condition">
          <option value="">همه</option>
          <?php foreach (['factory_sealed','new_unspecified','like_new','used','unknown'] as $c): ?>
            <option value="<?= $c ?>" <?= $f['condition'] === $c ? 'selected' : '' ?>><?= e(condition_label($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>موجودی آگهی
        <select name="availability">
          <option value="">همه</option>
          <option value="available" <?= $f['availability'] === 'available' ? 'selected' : '' ?>>موجود</option>
          <option value="sold" <?= $f['availability'] === 'sold' ? 'selected' : '' ?>>فروخته‌شده</option>
          <option value="unknown" <?= $f['availability'] === 'unknown' ? 'selected' : '' ?>>نامشخص</option>
        </select>
      </label>
      <label>تماس تأییدشده
        <select name="has_contact">
          <option value="">همه</option>
          <option value="1" <?= $f['has_contact'] === '1' ? 'selected' : '' ?>>فقط با تماس</option>
          <option value="0" <?= $f['has_contact'] === '0' ? 'selected' : '' ?>>فقط بدون تماس</option>
        </select>
      </label>
      <label>نوع قیمت
        <select name="price_type">
          <option value="">همه</option>
          <?php foreach (['fixed','negotiable','installment','call','unknown'] as $pt): ?>
            <option value="<?= $pt ?>" <?= $f['price_type'] === $pt ? 'selected' : '' ?>><?= e(price_type_label($pt)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>حداقل قیمت
        <input class="ltr-num" name="min" value="<?= e($f['min']) ?>" inputmode="numeric">
      </label>
      <label>حداکثر قیمت
        <input class="ltr-num" name="max" value="<?= e($f['max']) ?>" inputmode="numeric">
      </label>
      <label>شهر
        <input name="city" value="<?= e($f['city']) ?>">
      </label>
      <label>تلفن فروشنده
        <input class="ltr-num" name="phone" value="<?= e($f['phone']) ?>" inputmode="numeric">
      </label>
      <label>بررسی از
        <input name="seen_from" class="jalali-date" value="<?= e($f['seen_from']) ?>" placeholder="۱۴۰۵/۰۸/۰۱" dir="rtl">
      </label>
      <label>بررسی تا
        <input name="seen_to" class="jalali-date" value="<?= e($f['seen_to']) ?>" placeholder="۱۴۰۵/۰۸/۱۴" dir="rtl">
      </label>
      <label>مرتب‌سازی
        <select name="sort">
          <option value="checked_desc" <?= $f['sort'] === '' || $f['sort'] === 'checked_desc' ? 'selected' : '' ?>>تازه‌ترین بررسی</option>
          <option value="price_asc" <?= $f['sort'] === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین</option>
          <option value="price_desc" <?= $f['sort'] === 'price_desc' ? 'selected' : '' ?>>گران‌ترین</option>
          <option value="sim_asc" <?= $f['sort'] === 'sim_asc' ? 'selected' : '' ?>>شماره سیم</option>
        </select>
      </label>
    </div>
    <?php if ($dateFilterError): ?>
      <div class="flash error" style="margin-top:.75rem"><?= e($dateFilterError) ?></div>
    <?php endif; ?>
    <div class="tool-actions" style="margin-top:1rem">
      <button type="submit">اعمال فیلترها</button>
      <a class="secondary btn-like" href="/?r=search">پاک کردن همه</a>
    </div>
    <?php require __DIR__ . '/_filter_chips.php'; ?>
  </form>
</div>

<div class="panel">
  <?php if (!$offers): ?>
    <div class="empty-state">آگهی‌ای پیدا نشد.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>سیم</th>
            <th>منبع</th>
            <th class="col-price">قیمت</th>
            <th>وضعیت</th>
            <th>شهر</th>
            <th>موجودی</th>
            <th>تماس فروشنده</th>
            <th>شناسه آگهی</th>
            <th>بررسی</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($offers as $o):
            $cs = $contactsByOffer[(int) $o['id']] ?? [];
        ?>
          <tr>
            <td><a class="sim-link" href="/?r=sim&amp;n=<?= e(urlencode((string) $o['sim_number'])) ?>"><?= ltr_sim((string) $o['sim_number']) ?></a></td>
            <td><a href="/?r=source&amp;slug=<?= e(urlencode((string) $o['source_slug'])) ?>"><?= e($o['source_name']) ?></a></td>
            <td class="col-price"><?= e(format_price_display($o['price_amount'] !== null ? (int) $o['price_amount'] : null, $o['price_type'])) ?></td>
            <td><?= e(condition_label($o['condition_normalized'])) ?></td>
            <td><?= $o['city'] ? e($o['city']) : '—' ?></td>
            <td><?= e($o['availability']) ?></td>
            <td>
              <?php if (!$cs): ?><span class="muted">—</span>
              <?php else: foreach ($cs as $c): ?>
                <div class="contact-phone"><?= ltr_phone($c['phone']) ?> <span class="muted"><?= e($c['scope']) ?></span></div>
              <?php endforeach; endif; ?>
            </td>
            <td class="ltr-num support"><?= e((string) $o['external_id']) ?></td>
            <td class="support"><?= fa_datetime($o['last_checked_at'] ?? null) ?></td>
            <td><a href="<?= e($o['canonical_url']) ?>" target="_blank" rel="noopener">لینک</a></td>
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
$title = 'جست‌وجوی پیشرفته — روندینو';
$active = 'search';
require __DIR__ . '/layout.php';
