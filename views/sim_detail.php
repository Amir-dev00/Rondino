<?php
$raw = (string) (get_q('n', '') ?? '');
$number = Digits::extractSim0912($raw) ?? Digits::only(Digits::normalize($raw));
if (strlen($number) === 11 && str_starts_with($number, '0912')) {
    // ok
} else {
    $number = Digits::extractSim0912($raw) ?? '';
}

$sim = $number !== '' ? Inventory::simByNumber($number) : null;
if (!$sim) {
    http_response_code(404);
    ob_start();
    echo '<div class="panel"><div class="empty-state">این شماره در پایگاه داده یافت نشد.</div></div>';
    $content = ob_get_clean();
    $title = 'سیم یافت نشد — روندینو';
    $active = 'market';
    require __DIR__ . '/layout.php';
    return;
}

$offers = Inventory::offersForSim((int) $sim['id']);
$contactsByOffer = Inventory::contactsByOfferIds(array_map(static fn ($o) => (int) $o['id'], $offers));
$allPhones = [];
foreach ($contactsByOffer as $list) {
    foreach ($list as $c) {
        $allPhones[$c['phone']] = true;
    }
}
$best = null;
foreach ($offers as $o) {
    if ($o['price_amount'] === null) {
        continue;
    }
    if ($best === null || (int) $o['price_amount'] < (int) $best['price_amount']) {
        $best = $o;
    }
}

ob_start();
?>
<div class="page-head">
  <div>
    <h1><?= ltr_sim((string) $sim['number']) ?></h1>
    <p class="page-meta">کد رقم: <?= e((string) $sim['code_digit']) ?> · <?= count($offers) ?> آگهی در <?= count(array_unique(array_column($offers, 'source_id'))) ?> منبع</p>
  </div>
  <div class="page-meta">
    بهترین قیمت:
    <strong><?= $best ? e(format_price_display((int) $best['price_amount'], $best['price_type'])) : '—' ?></strong>
  </div>
</div>

<div class="stat-grid compact">
  <article class="stat-card"><div class="stat-label">آگهی‌ها</div><div class="stat-value ltr-num"><?= count($offers) ?></div></article>
  <article class="stat-card"><div class="stat-label">منابع</div><div class="stat-value ltr-num"><?= count(array_unique(array_column($offers, 'source_id'))) ?></div></article>
  <article class="stat-card accent"><div class="stat-label">تلفن‌های تأییدشده</div><div class="stat-value ltr-num"><?= count($allPhones) ?></div></article>
</div>

<?php if ($allPhones): ?>
<section class="panel">
  <h2>تماس‌های فروشنده مرتبط</h2>
  <div class="phone-chips">
    <?php foreach (array_keys($allPhones) as $ph): ?>
      <span class="contact-phone"><?= ltr_phone($ph) ?></span>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <h2>مقایسه قیمت بین منابع</h2>
  <?php if (!$offers): ?>
    <div class="empty-state">آگهی‌ای ثبت نشده.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>منبع</th>
            <th class="col-price">قیمت</th>
            <th>وضعیت</th>
            <th>شهر</th>
            <th>موجودی</th>
            <th>تماس فروشنده</th>
            <th>شناسه</th>
            <th>آخرین بررسی</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($offers as $o):
            $cs = $contactsByOffer[(int) $o['id']] ?? [];
            $isBest = $best && (int) $o['id'] === (int) $best['id'];
        ?>
          <tr class="<?= $isBest ? 'row-best' : '' ?>">
            <td><a href="/?r=source&amp;slug=<?= e(urlencode((string) $o['source_slug'])) ?>"><?= e($o['source_name']) ?></a></td>
            <td class="col-price">
              <?= e(format_price_display($o['price_amount'] !== null ? (int) $o['price_amount'] : null, $o['price_type'])) ?>
              <?php if ($isBest): ?><span class="badge-best">بهترین</span><?php endif; ?>
            </td>
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
            <td><a href="<?= e($o['canonical_url']) ?>" target="_blank" rel="noopener">آگهی اصلی</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = $sim['number'] . ' — جزئیات — روندینو';
$active = 'market';
require __DIR__ . '/layout.php';
