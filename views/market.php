<?php
$f = Inventory::parseFilters();
$dates = Inventory::resolveDateRange($f);
$dateFilterError = $dates['ok'] ? null : $dates['error'];
$sources = Inventory::allSources();
$sourceNameById = [];
foreach ($sources as $s) {
    $sourceNameById[(string) $s['id']] = $s['name'];
}

$total = $dates['ok'] ? Inventory::countSims($f, $dates) : 0;
$pager = Pagination::fromRequest($total, 50);
$rows = $dates['ok'] ? Inventory::listSims($f, $dates, $pager) : [];
$baseQuery = Inventory::queryParams($f, 'market');

$chipAll = static function (array $omit) use ($f): array {
    return Inventory::queryParams($f, 'market', $omit);
};
$activeChips = [];
if ($f['q'] !== '') $activeChips[] = ['label' => 'شماره: ' . $f['q'], 'clear' => $chipAll(['q'])];
if ($f['code'] !== '') $activeChips[] = ['label' => 'کد: ' . $f['code'], 'clear' => $chipAll(['code'])];
if ($f['condition'] !== '') $activeChips[] = ['label' => 'وضعیت: ' . condition_label($f['condition']), 'clear' => $chipAll(['condition'])];
if ($f['city'] !== '') $activeChips[] = ['label' => 'شهر: ' . $f['city'], 'clear' => $chipAll(['city'])];
if ($f['source'] !== '') $activeChips[] = ['label' => 'منبع: ' . ($sourceNameById[$f['source']] ?? $f['source']), 'clear' => $chipAll(['source'])];
if ($f['has_contact'] === '1') $activeChips[] = ['label' => 'دارای تماس', 'clear' => $chipAll(['has_contact'])];
if ($f['has_contact'] === '0') $activeChips[] = ['label' => 'بدون تماس', 'clear' => $chipAll(['has_contact'])];
if ($f['min'] !== '') $activeChips[] = ['label' => 'حداقل: ' . number_format((int) $f['min']), 'clear' => $chipAll(['min'])];
if ($f['max'] !== '') $activeChips[] = ['label' => 'حداکثر: ' . number_format((int) $f['max']), 'clear' => $chipAll(['max'])];
if ($f['seen_from'] !== '') $activeChips[] = ['label' => 'از: ' . $f['seen_from'], 'clear' => $chipAll(['seen_from'])];
if ($f['seen_to'] !== '') $activeChips[] = ['label' => 'تا: ' . $f['seen_to'], 'clear' => $chipAll(['seen_to'])];
$clearAllQuery = ['r' => 'market'];
$moreOpen = ($f['city'] !== '' || $f['source'] !== '' || $f['min'] !== '' || $f['max'] !== '' || $f['seen_from'] !== '' || $f['seen_to'] !== '' || $f['has_contact'] !== '' || $f['availability'] !== '' || $f['price_type'] !== '');

ob_start();
?>
<div class="page-head">
  <div>
    <h1>بازار سراسری سیم‌کارت</h1>
    <p class="page-meta">هر ردیف یک شماره یکتاست؛ آگهی‌های هر شماره در صفحه جزئیات</p>
  </div>
  <div class="page-meta"><?= number_format($total) ?> شماره یکتا</div>
</div>

<div class="panel">
  <form method="get" action="/" id="market-filter-form">
    <input type="hidden" name="r" value="market">
    <input type="hidden" name="per" value="<?= (int) $pager->perPage ?>">
    <div class="search-bar">
      <label class="search-field">جست‌وجوی شماره
        <input class="ltr-num" name="q" value="<?= e($f['q']) ?>" placeholder="0912…" inputmode="numeric" autocomplete="off">
      </label>
      <button type="submit">جست‌وجو</button>
    </div>
    <div class="toolbar">
      <label class="tool">رقم کد
        <select name="code">
          <option value="">همه</option>
          <?php for ($i = 0; $i <= 9; $i++): ?>
            <option value="<?= $i ?>" <?= $f['code'] === (string) $i ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="tool">وضعیت خط
        <select name="condition">
          <option value="">همه</option>
          <?php foreach (['factory_sealed','new_unspecified','like_new','used','unknown'] as $c): ?>
            <option value="<?= $c ?>" <?= $f['condition'] === $c ? 'selected' : '' ?>><?= e(condition_label($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="tool">مرتب‌سازی
        <select name="sort">
          <option value="" <?= $f['sort'] === '' ? 'selected' : '' ?>>شماره</option>
          <option value="price_asc" <?= $f['sort'] === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین</option>
          <option value="price_desc" <?= $f['sort'] === 'price_desc' ? 'selected' : '' ?>>گران‌ترین</option>
          <option value="listings" <?= $f['sort'] === 'listings' ? 'selected' : '' ?>>بیشترین آگهی</option>
          <option value="checked_desc" <?= $f['sort'] === 'checked_desc' ? 'selected' : '' ?>>تازه‌ترین بررسی</option>
        </select>
      </label>
      <div class="tool-actions">
        <button type="submit" class="secondary">اعمال</button>
        <button type="button" class="secondary" id="btn-more-filters" aria-expanded="<?= $moreOpen ? 'true' : 'false' ?>">فیلترهای بیشتر</button>
      </div>
    </div>
    <div class="more-filters<?= $moreOpen ? ' open' : '' ?>" id="more-filters">
      <div class="more-grid">
        <label>شهر<input name="city" value="<?= e($f['city']) ?>" placeholder="مثلاً تهران"></label>
        <label>منبع
          <select name="source">
            <option value="">همه</option>
            <?php foreach ($sources as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= $f['source'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>تماس تأییدشده
          <select name="has_contact">
            <option value="">همه</option>
            <option value="1" <?= $f['has_contact'] === '1' ? 'selected' : '' ?>>فقط با تماس</option>
            <option value="0" <?= $f['has_contact'] === '0' ? 'selected' : '' ?>>فقط بدون تماس</option>
          </select>
        </label>
        <label>حداقل قیمت <span class="field-hint">(تومان)</span>
          <input class="ltr-num" name="min" value="<?= e($f['min']) ?>" inputmode="numeric">
        </label>
        <label>حداکثر قیمت <span class="field-hint">(تومان)</span>
          <input class="ltr-num" name="max" value="<?= e($f['max']) ?>" inputmode="numeric">
        </label>
        <label>بررسی از تاریخ شمسی
          <input name="seen_from" class="jalali-date" value="<?= e($f['seen_from']) ?>" placeholder="۱۴۰۵/۰۸/۰۱" autocomplete="off" dir="rtl">
        </label>
        <label>بررسی تا تاریخ شمسی
          <input name="seen_to" class="jalali-date" value="<?= e($f['seen_to']) ?>" placeholder="۱۴۰۵/۰۸/۱۴" autocomplete="off" dir="rtl">
        </label>
      </div>
      <?php if ($dateFilterError): ?>
        <div class="flash error" style="margin-top:.75rem"><?= e($dateFilterError) ?></div>
      <?php endif; ?>
    </div>
    <?php require __DIR__ . '/_filter_chips.php'; ?>
  </form>
</div>

<div class="panel">
  <div class="results-meta">
    <div><?= number_format($total) ?> شماره یکتا</div>
    <div class="unit-note">قیمت‌ها به تومان · کلیک روی شماره برای جزئیات کامل</div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state">شماره‌ای با این فیلترها پیدا نشد.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>شماره سیم</th>
            <th class="col-price">بهترین قیمت</th>
            <th>آگهی‌ها</th>
            <th>منابع</th>
            <th>تماس فروشنده</th>
            <th>وضعیت</th>
            <th>شهر</th>
            <th>آخرین بررسی</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $conds = array_filter(explode(',', (string) ($r['conditions'] ?? '')));
            $condLabels = array_map('condition_label', $conds);
            $phones = $r['phones'] ?? [];
        ?>
          <tr>
            <td>
              <a class="sim-link" href="/?r=sim&amp;n=<?= e(urlencode((string) $r['number'])) ?>"><?= ltr_sim((string) $r['number']) ?></a>
            </td>
            <td class="col-price"><?= e(format_price_display($r['best_price'] !== null ? (int) $r['best_price'] : null, 'fixed')) ?></td>
            <td class="ltr-num"><?= (int) $r['listing_count'] ?></td>
            <td class="support"><?= e((string) ($r['source_names'] ?? '—')) ?></td>
            <td>
              <?php if (!$phones): ?>
                <span class="muted">—</span>
              <?php else: foreach ($phones as $ph): ?>
                <div class="contact-phone"><?= ltr_phone($ph) ?></div>
              <?php endforeach; endif; ?>
            </td>
            <td class="support"><?= $condLabels ? e(implode('، ', $condLabels)) : '—' ?></td>
            <td class="support"><?= $r['cities'] ? e((string) $r['cities']) : '—' ?></td>
            <td class="support"><?= !empty($r['last_checked_at']) ? fa_datetime($r['last_checked_at']) : '—' ?></td>
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
$title = 'بازار سراسری — روندینو';
$active = 'market';
require __DIR__ . '/layout.php';
