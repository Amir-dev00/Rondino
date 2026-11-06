<?php
$slug = trim((string) (get_q('slug', '') ?? ''));
$source = $slug !== '' ? Inventory::sourceBySlug($slug) : null;
if (!$source) {
    http_response_code(404);
    ob_start();
    echo '<div class="panel"><div class="empty-state">منبع یافت نشد.</div></div>';
    $content = ob_get_clean();
    $title = 'منبع نامعتبر — روندینو';
    $active = 'source';
    $activeSlug = '';
    require __DIR__ . '/layout.php';
    return;
}

$f = Inventory::parseFilters(['slug' => (string) $source['slug']]);
$dates = Inventory::resolveDateRange($f);
$dateFilterError = $dates['ok'] ? null : $dates['error'];
$st = Inventory::sourceStats((int) $source['id']);

$total = $dates['ok'] ? Inventory::countOffers($f, $dates) : 0;
$pager = Pagination::fromRequest($total, 50);
$offers = $dates['ok'] ? Inventory::listOffers($f, $dates, $pager) : [];
$contactsByOffer = Inventory::contactsByOfferIds(array_map(static fn ($o) => (int) $o['id'], $offers));
$baseQuery = Inventory::queryParams($f, 'source');

$chipAll = static function (array $omit) use ($f): array {
    return Inventory::queryParams($f, 'source', $omit);
};
$activeChips = [];
if ($f['q'] !== '') $activeChips[] = ['label' => 'سیم: ' . $f['q'], 'clear' => $chipAll(['q'])];
if ($f['has_contact'] === '1') $activeChips[] = ['label' => 'دارای تماس', 'clear' => $chipAll(['has_contact'])];
if ($f['has_contact'] === '0') $activeChips[] = ['label' => 'بدون تماس', 'clear' => $chipAll(['has_contact'])];
if ($f['condition'] !== '') $activeChips[] = ['label' => condition_label($f['condition']), 'clear' => $chipAll(['condition'])];
if ($f['min'] !== '') $activeChips[] = ['label' => 'حداقل: ' . number_format((int) $f['min']), 'clear' => $chipAll(['min'])];
if ($f['max'] !== '') $activeChips[] = ['label' => 'حداکثر: ' . number_format((int) $f['max']), 'clear' => $chipAll(['max'])];
$clearAllQuery = ['r' => 'source', 'slug' => $source['slug']];

$healthy = (int) $source['is_enabled'] === 1 && empty($source['last_error']);

ob_start();
?>
<div class="page-head">
  <div>
    <h1>موجودی <?= e($source['name']) ?></h1>
    <p class="page-meta">
      <span class="ltr-num" dir="ltr"><?= e($source['base_url']) ?></span>
      · <?= (int) $source['is_enabled'] ? 'فعال' : 'غیرفعال' ?>
      · سلامت: <?= $healthy ? 'سالم' : 'نیاز به بررسی' ?>
    </p>
  </div>
</div>

<div class="stat-grid compact">
  <article class="stat-card"><div class="stat-label">آگهی‌ها</div><div class="stat-value ltr-num"><?= number_format($st['listings']) ?></div></article>
  <article class="stat-card"><div class="stat-label">شماره یکتا</div><div class="stat-value ltr-num"><?= number_format($st['unique_sims']) ?></div></article>
  <article class="stat-card accent"><div class="stat-label">با تماس</div><div class="stat-value ltr-num"><?= number_format($st['with_contact']) ?></div></article>
  <article class="stat-card muted-card"><div class="stat-label">بدون تماس</div><div class="stat-value ltr-num"><?= number_format($st['without_contact']) ?></div></article>
  <article class="stat-card"><div class="stat-label">رکورد تلفن</div><div class="stat-value ltr-num"><?= number_format($st['phone_records']) ?></div></article>
  <article class="stat-card">
    <div class="stat-label">آخرین خزش موفق</div>
    <div class="stat-value small"><?= $source['last_success_at'] ? fa_datetime($source['last_success_at']) : '—' ?></div>
  </article>
</div>

<div class="panel">
  <form method="get" action="/" class="toolbar wrap-form">
    <input type="hidden" name="r" value="source">
    <input type="hidden" name="slug" value="<?= e($source['slug']) ?>">
    <input type="hidden" name="per" value="<?= (int) $pager->perPage ?>">
    <label class="tool" style="flex:2 1 12rem">شماره سیم
      <input class="ltr-num" name="q" value="<?= e($f['q']) ?>" placeholder="0912…" inputmode="numeric">
    </label>
    <label class="tool">وضعیت
      <select name="condition">
        <option value="">همه</option>
        <?php foreach (['factory_sealed','new_unspecified','like_new','used','unknown'] as $c): ?>
          <option value="<?= $c ?>" <?= $f['condition'] === $c ? 'selected' : '' ?>><?= e(condition_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="tool">تماس
      <select name="has_contact">
        <option value="">همه</option>
        <option value="1" <?= $f['has_contact'] === '1' ? 'selected' : '' ?>>با تماس</option>
        <option value="0" <?= $f['has_contact'] === '0' ? 'selected' : '' ?>>بدون تماس</option>
      </select>
    </label>
    <label class="tool">مرتب‌سازی
      <select name="sort">
        <option value="checked_desc" <?= $f['sort'] === '' || $f['sort'] === 'checked_desc' ? 'selected' : '' ?>>تازه‌ترین</option>
        <option value="price_asc" <?= $f['sort'] === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین</option>
        <option value="price_desc" <?= $f['sort'] === 'price_desc' ? 'selected' : '' ?>>گران‌ترین</option>
        <option value="sim_asc" <?= $f['sort'] === 'sim_asc' ? 'selected' : '' ?>>شماره</option>
      </select>
    </label>
    <div class="tool-actions"><button type="submit">اعمال</button></div>
  </form>
  <?php if ($dateFilterError): ?><div class="flash error"><?= e($dateFilterError) ?></div><?php endif; ?>
  <?php require __DIR__ . '/_filter_chips.php'; ?>
</div>

<div class="panel">
  <div class="results-meta">
    <div><?= number_format($total) ?> آگهی در این منبع (صفحه‌بندی مستقل)</div>
    <div class="unit-note">آخرین به‌روزرسانی آگهی: <?= $st['latest_listing_at'] ? fa_datetime($st['latest_listing_at']) : '—' ?></div>
  </div>
  <?php if (!$offers): ?>
    <div class="empty-state">آگهی‌ای برای این منبع با فیلتر فعلی نیست.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>شماره سیم</th>
            <th>تماس فروشنده</th>
            <th class="col-price">قیمت</th>
            <th>وضعیت</th>
            <th>شهر</th>
            <th>موجودی</th>
            <th>شناسه</th>
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
            <td>
              <?php if (!$cs): ?><span class="muted">—</span>
              <?php else: foreach ($cs as $c): ?>
                <div class="contact-phone"><?= ltr_phone($c['phone']) ?></div>
              <?php endforeach; endif; ?>
            </td>
            <td class="col-price"><?= e(format_price_display($o['price_amount'] !== null ? (int) $o['price_amount'] : null, $o['price_type'])) ?></td>
            <td><?= e(condition_label($o['condition_normalized'])) ?></td>
            <td><?= $o['city'] ? e($o['city']) : '—' ?></td>
            <td><?= e($o['availability']) ?></td>
            <td class="ltr-num support"><?= e((string) $o['external_id']) ?></td>
            <td class="support"><?= fa_datetime($o['last_checked_at'] ?? null) ?></td>
            <td><a href="<?= e($o['canonical_url']) ?>" target="_blank" rel="noopener">آگهی</a></td>
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
$title = $source['name'] . ' — موجودی — روندینو';
$active = 'source';
$activeSlug = (string) $source['slug'];
require __DIR__ . '/layout.php';
