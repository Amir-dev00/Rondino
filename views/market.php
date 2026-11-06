<?php
declare(strict_types=1);

$pdo = Database::pdo();
$q = trim((string) (get_q('q', '') ?? ''));
$code = (string) (get_q('code', '') ?? '');
$condition = (string) (get_q('condition', '') ?? '');
$city = trim((string) (get_q('city', '') ?? ''));
$sourceId = (string) (get_q('source', '') ?? '');
$availability = (string) (get_q('availability', 'all') ?? 'all');
$contactFilter = (string) (get_q('contact', 'all') ?? 'all');
$priceType = (string) (get_q('price_type', '') ?? '');
$sort = (string) (get_q('sort', 'number') ?? 'number');
$minRaw = (string) (get_q('min', '') ?? '');
$maxRaw = (string) (get_q('max', '') ?? '');
$seenFromFa = trim((string) (get_q('seen_from', '') ?? ''));
$seenToFa = trim((string) (get_q('seen_to', '') ?? ''));
$page = max(1, (int) Digits::only(Digits::normalize((string) (get_q('page', '1') ?? '1'))));
$perPageRaw = (int) Digits::only(Digits::normalize((string) (get_q('per_page', '50') ?? '50')));
$perPage = in_array($perPageRaw, [25, 50, 100], true) ? $perPageRaw : 50;

$parseAmount = static function (string $raw): ?int {
    $digits = Digits::only(Digits::normalize($raw));
    return $digits !== '' && ctype_digit($digits) ? (int) $digits : null;
};
$min = $parseAmount($minRaw);
$max = $parseAmount($maxRaw);
$dateFilterError = null;

$sources = $pdo->query('SELECT id, name, slug FROM sources ORDER BY name')->fetchAll();
$sourceNameById = [];
foreach ($sources as $source) {
    $sourceNameById[(string) $source['id']] = $source['name'];
}

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $digits = Digits::only(Digits::normalize($q));
    if ($digits !== '') {
        $where .= ' AND sn.number LIKE ?';
        $params[] = '%' . $digits . '%';
    } else {
        $where .= ' AND o.external_id LIKE ?';
        $params[] = '%' . $q . '%';
    }
}
if ($code !== '' && preg_match('/^[0-9]$/', $code)) {
    $where .= ' AND sn.code_digit = ?';
    $params[] = $code;
}
if ($condition !== '') {
    $where .= ' AND o.condition_normalized = ?';
    $params[] = $condition;
}
if ($city !== '') {
    $where .= ' AND o.city LIKE ?';
    $params[] = '%' . $city . '%';
}
if ($sourceId !== '' && ctype_digit($sourceId)) {
    $where .= ' AND o.source_id = ?';
    $params[] = (int) $sourceId;
}
if ($availability === 'available') {
    $where .= " AND o.availability = 'available'";
} elseif ($availability === 'unavailable') {
    $where .= " AND o.availability <> 'available'";
}
if ($contactFilter === 'with') {
    $where .= ' AND EXISTS (SELECT 1 FROM offer_contacts ocf WHERE ocf.offer_id = o.id)';
} elseif ($contactFilter === 'without') {
    $where .= ' AND NOT EXISTS (SELECT 1 FROM offer_contacts ocf WHERE ocf.offer_id = o.id)';
}
if (in_array($priceType, ['fixed', 'negotiable', 'installment', 'call', 'unknown'], true)) {
    $where .= ' AND o.price_type = ?';
    $params[] = $priceType;
}
if ($min !== null) {
    $where .= ' AND o.price_amount >= ?';
    $params[] = $min;
}
if ($max !== null) {
    $where .= ' AND o.price_amount <= ?';
    $params[] = $max;
}
if ($seenFromFa !== '') {
    $range = Jalali::rangeStartUtc($seenFromFa);
    if (!$range['ok']) {
        $dateFilterError = $range['error'] ?? 'تاریخ شروع معتبر نیست.';
    } else {
        $where .= ' AND o.last_checked_at >= ?';
        $params[] = $range['utc'];
    }
}
if ($seenToFa !== '' && $dateFilterError === null) {
    $range = Jalali::rangeEndUtc($seenToFa);
    if (!$range['ok']) {
        $dateFilterError = $range['error'] ?? 'تاریخ پایان معتبر نیست.';
    } else {
        $where .= ' AND o.last_checked_at <= ?';
        $params[] = $range['utc'];
    }
}

$countSql = "SELECT COUNT(DISTINCT sn.id) FROM offers o JOIN sim_numbers sn ON sn.id=o.sim_number_id {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalNumbers = (int) $countStmt->fetchColumn();

$offerCountStmt = $pdo->prepare("SELECT COUNT(*) FROM offers o JOIN sim_numbers sn ON sn.id=o.sim_number_id {$where}");
$offerCountStmt->execute($params);
$totalOffers = (int) $offerCountStmt->fetchColumn();

$contactCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM offer_contacts oc JOIN offers o ON o.id=oc.offer_id JOIN sim_numbers sn ON sn.id=o.sim_number_id {$where}"
);
$contactCountStmt->execute($params);
$totalPhoneRows = (int) $contactCountStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalNumbers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$orderBy = match ($sort) {
    'price_asc' => 'best_price IS NULL ASC, best_price ASC, sn.number ASC',
    'price_desc' => 'best_price DESC, sn.number ASC',
    'recent' => 'freshest DESC, sn.number ASC',
    'offers' => 'offer_count DESC, sn.number ASC',
    default => 'sn.number ASC',
};

$groupStmt = $pdo->prepare(
    "SELECT sn.id, sn.number, MIN(o.price_amount) AS best_price, MAX(o.last_checked_at) AS freshest, COUNT(o.id) AS offer_count
     FROM offers o JOIN sim_numbers sn ON sn.id=o.sim_number_id
     {$where}
     GROUP BY sn.id, sn.number
     ORDER BY {$orderBy}
     LIMIT {$perPage} OFFSET {$offset}"
);
$groupStmt->execute($params);
$pageNumbers = $groupStmt->fetchAll();

$offers = [];
$contactsByOffer = [];
$groups = [];
$latestCheck = null;
if ($pageNumbers) {
    $simIds = array_map('intval', array_column($pageNumbers, 'id'));
    $in = implode(',', array_fill(0, count($simIds), '?'));
    $offerStmt = $pdo->prepare(
        "SELECT o.*, sn.number AS sim_number, sn.code_digit, src.name AS source_name, src.slug AS source_slug
         FROM offers o
         JOIN sim_numbers sn ON sn.id=o.sim_number_id
         JOIN sources src ON src.id=o.source_id
         {$where} AND sn.id IN ({$in})
         ORDER BY sn.number ASC, o.price_amount IS NULL ASC, o.price_amount ASC, src.name ASC"
    );
    $offerStmt->execute(array_merge($params, $simIds));
    $offers = $offerStmt->fetchAll();

    $offerIds = array_map('intval', array_column($offers, 'id'));
    if ($offerIds) {
        $offerIn = implode(',', array_fill(0, count($offerIds), '?'));
        $contactStmt = $pdo->prepare("SELECT offer_id, phone, phone_type, scope, label FROM offer_contacts WHERE offer_id IN ({$offerIn}) ORDER BY scope, phone");
        $contactStmt->execute($offerIds);
        foreach ($contactStmt->fetchAll() as $contact) {
            $contactsByOffer[(int) $contact['offer_id']][] = $contact;
        }
    }

    foreach ($offers as $offer) {
        $groups[$offer['sim_number']][] = $offer;
        if (!empty($offer['last_checked_at']) && ($latestCheck === null || $offer['last_checked_at'] > $latestCheck)) {
            $latestCheck = $offer['last_checked_at'];
        }
    }
}

function market_best_price(array $rows): array
{
    $best = null;
    foreach ($rows as $row) {
        if ($row['price_amount'] === null) {
            continue;
        }
        if ($best === null || (int) $row['price_amount'] < (int) $best['price_amount']) {
            $best = $row;
        }
    }
    if ($best !== null) {
        return ['amount' => (int) $best['price_amount'], 'type' => $best['price_type']];
    }
    foreach ($rows as $row) {
        if ($row['price_type'] === 'negotiable') {
            return ['amount' => null, 'type' => 'negotiable'];
        }
    }
    return ['amount' => null, 'type' => $rows[0]['price_type'] ?? 'unknown'];
}

function market_contact_label(string $scope): string
{
    return match ($scope) {
        'seller' => 'فروشنده',
        'listing' => 'تماس آگهی',
        'store' => 'فروشگاه',
        default => 'تماس',
    };
}

function market_freshness_label(?string $utc): array
{
    if (!$utc) {
        return ['text' => '—', 'stale' => false];
    }
    $timestamp = strtotime($utc . ' UTC');
    if ($timestamp === false) {
        return ['text' => fa_datetime($utc), 'stale' => false];
    }
    return ['text' => fa_datetime($utc), 'stale' => ((time() - $timestamp) / 3600) > 48];
}

$filterCount = 0;
foreach ([$q, $code, $condition, $city, $sourceId, $minRaw, $maxRaw, $seenFromFa, $seenToFa] as $value) {
    if ($value !== '') $filterCount++;
}
if ($availability !== 'all') $filterCount++;
if ($contactFilter !== 'all') $filterCount++;
if ($priceType !== '') $filterCount++;

$urlParams = [
    'r' => 'market', 'q' => $q, 'code' => $code, 'condition' => $condition,
    'city' => $city, 'source' => $sourceId, 'min' => $minRaw, 'max' => $maxRaw,
    'seen_from' => $seenFromFa, 'seen_to' => $seenToFa, 'availability' => $availability,
    'contact' => $contactFilter, 'price_type' => $priceType, 'sort' => $sort, 'per_page' => $perPage,
];
$urlParams = array_filter($urlParams, static fn ($value) => $value !== '' && $value !== null);
$pageUrl = static function (int $target) use ($urlParams): string {
    return '/?' . http_build_query(array_merge($urlParams, ['page' => $target]));
};

ob_start();
?>
<section class="ui-page-head">
  <div>
    <div class="eyebrow">RONDINO · MARKET INTELLIGENCE</div>
    <h1>بازار خطوط</h1>
    <p>مقایسه آگهی‌های ۰۹۱۲ از تمام منابع، با نمایش شماره‌های تماس ثبت‌شده</p>
  </div>
  <div class="ui-head-meta">
    <span class="live-dot"></span>
    <span>آخرین بررسی این صفحه: <?= $latestCheck ? fa_datetime($latestCheck) : '—' ?></span>
  </div>
</section>

<section class="ui-stat-grid">
  <article class="ui-stat-card"><span>شماره‌های متمایز</span><strong><?= number_format($totalNumbers) ?></strong><small>پس از اعمال فیلترها</small></article>
  <article class="ui-stat-card"><span>کل پیشنهادها</span><strong><?= number_format($totalOffers) ?></strong><small>از تمام منابع انتخاب‌شده</small></article>
  <article class="ui-stat-card"><span>ردیف‌های تماس ثبت‌شده</span><strong><?= number_format($totalPhoneRows) ?></strong><small>فقط شماره‌های ذخیره‌شده در دیتابیس</small></article>
  <article class="ui-stat-card"><span>صفحه فعلی</span><strong><?= number_format($page) ?> <small>/ <?= number_format($totalPages) ?></small></strong><small>هر صفحه <?= number_format($perPage) ?> شماره متمایز</small></article>
</section>

<section class="ui-panel">
  <div class="ui-panel-heading">
    <div><span class="eyebrow">SMART FILTERS</span><h2>جست‌وجو و فیلتر پیشرفته</h2></div>
    <span class="ui-filter-count"><?= number_format($filterCount) ?> فیلتر فعال</span>
  </div>
  <form method="get" action="/" class="ui-filter-form">
    <input type="hidden" name="r" value="market">
    <label class="ui-field ui-field-wide">شماره سیم‌کارت یا شناسه آگهی
      <input class="ltr-num" name="q" value="<?= e($q) ?>" placeholder="0912… یا شناسه آگهی">
    </label>
    <label class="ui-field">منبع
      <select name="source"><option value="">همه منابع</option><?php foreach ($sources as $source): ?><option value="<?= (int) $source['id'] ?>" <?= $sourceId === (string) $source['id'] ? 'selected' : '' ?>><?= e($source['name']) ?></option><?php endforeach; ?></select>
    </label>
    <label class="ui-field">رقم کد
      <select name="code"><option value="">همه</option><?php for ($i=0; $i<=9; $i++): ?><option value="<?= $i ?>" <?= $code === (string) $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
    </label>
    <label class="ui-field">وضعیت خط
      <select name="condition"><option value="">همه وضعیت‌ها</option><?php foreach (['factory_sealed','new_unspecified','like_new','used','unknown'] as $value): ?><option value="<?= e($value) ?>" <?= $condition === $value ? 'selected' : '' ?>><?= e(condition_label($value)) ?></option><?php endforeach; ?></select>
    </label>
    <label class="ui-field">وضعیت آگهی
      <select name="availability"><option value="all" <?= $availability === 'all' ? 'selected' : '' ?>>همه وضعیت‌ها</option><option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>فعال</option><option value="unavailable" <?= $availability === 'unavailable' ? 'selected' : '' ?>>غیرفعال / ناموجود</option></select>
    </label>
    <label class="ui-field">وضعیت تماس
      <select name="contact"><option value="all" <?= $contactFilter === 'all' ? 'selected' : '' ?>>همه آگهی‌ها</option><option value="with" <?= $contactFilter === 'with' ? 'selected' : '' ?>>دارای شماره تماس</option><option value="without" <?= $contactFilter === 'without' ? 'selected' : '' ?>>بدون شماره ثبت‌شده</option></select>
    </label>
    <label class="ui-field">نوع قیمت
      <select name="price_type"><option value="">همه</option><?php foreach (['fixed'=>'مقطوع','negotiable'=>'توافقی','installment'=>'اقساط','call'=>'استعلام','unknown'=>'نامشخص'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $priceType === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    </label>
    <label class="ui-field">شهر
      <input name="city" value="<?= e($city) ?>" placeholder="مثلاً تهران">
    </label>
    <label class="ui-field">حداقل قیمت (تومان)
      <input class="ltr-num" name="min" value="<?= e($minRaw) ?>" inputmode="numeric" placeholder="مثلاً ۱۰۰۰۰۰۰۰۰">
    </label>
    <label class="ui-field">حداکثر قیمت (تومان)
      <input class="ltr-num" name="max" value="<?= e($maxRaw) ?>" inputmode="numeric" placeholder="مثلاً ۵۰۰۰۰۰۰۰۰">
    </label>
    <label class="ui-field">از تاریخ بررسی
      <input class="jalali-date" name="seen_from" value="<?= e($seenFromFa) ?>" placeholder="۱۴۰۵/۰۸/۰۱" autocomplete="off">
    </label>
    <label class="ui-field">تا تاریخ بررسی
      <input class="jalali-date" name="seen_to" value="<?= e($seenToFa) ?>" placeholder="۱۴۰۵/۰۸/۳۰" autocomplete="off">
    </label>
    <label class="ui-field">مرتب‌سازی
      <select name="sort"><option value="number" <?= $sort === 'number' ? 'selected' : '' ?>>شماره سیم‌کارت</option><option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین قیمت</option><option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>گران‌ترین قیمت</option><option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>آخرین بررسی</option><option value="offers" <?= $sort === 'offers' ? 'selected' : '' ?>>بیشترین پیشنهاد</option></select>
    </label>
    <label class="ui-field">تعداد در صفحه
      <select name="per_page"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> شماره</option><?php endforeach; ?></select>
    </label>
    <div class="ui-filter-actions"><button type="submit">اعمال فیلترها</button><a class="ui-button ui-button-ghost" href="/?r=market">پاک‌کردن فیلترها</a></div>
  </form>
  <?php if ($dateFilterError): ?><div class="ui-alert ui-alert-warning"><?= e($dateFilterError) ?></div><?php endif; ?>
</section>

<section class="ui-panel">
  <div class="ui-panel-heading">
    <div><span class="eyebrow">RESULTS</span><h2>نتایج بازار</h2></div>
    <div class="ui-results-caption">نمایش <?= $totalNumbers ? number_format($offset + 1) : '۰' ?> تا <?= number_format(min($offset + $perPage, $totalNumbers)) ?> از <?= number_format($totalNumbers) ?> شماره</div>
  </div>

  <?php if (!$groups): ?>
    <div class="ui-empty"><strong>نتیجه‌ای پیدا نشد</strong><span>فیلترها را تغییر بده یا موجودی منابع را بررسی کن.</span></div>
  <?php else: ?>
    <div class="ui-table-scroll">
      <table class="ui-data-table">
        <thead><tr><th>شماره سیم‌کارت</th><th>بهترین قیمت</th><th>پیشنهادها</th><th>شماره‌های تماس فروشنده</th><th>وضعیت / شهر</th><th>آخرین بررسی</th><th></th></tr></thead>
        <tbody>
        <?php $idx = 0; foreach ($groups as $sim => $rows): $idx++;
          $best = market_best_price($rows);
          $conditions = []; $cities = []; $phoneMap = []; $freshest = null;
          foreach ($rows as $offer) {
              $conditions[$offer['condition_normalized']] = true;
              if (!empty($offer['city'])) $cities[$offer['city']] = true;
              if ($freshest === null || ($offer['last_checked_at'] ?? '') > $freshest) $freshest = $offer['last_checked_at'] ?? null;
              foreach (($contactsByOffer[(int) $offer['id']] ?? []) as $contact) {
                  $phoneMap[$contact['phone']] = ['phone' => $contact['phone'], 'source' => $offer['source_name']];
              }
          }
          $fresh = market_freshness_label($freshest);
          $detailId = 'market-detail-' . $idx;
        ?>
          <tr>
            <td><strong class="ui-sim-number"><?= ltr_sim($sim) ?></strong><a class="ui-sub-link" href="/?r=contacts&amp;sim=<?= e($sim) ?>">دفتر تماس این شماره</a></td>
            <td><strong class="ui-price"><?= e(format_price_display($best['amount'], $best['type'])) ?></strong></td>
            <td><span class="ui-count-pill"><?= count($rows) ?></span></td>
            <td><div class="ui-phone-list"><?php if (!$phoneMap): ?><span class="ui-muted">شماره ثبت نشده</span><?php else: foreach ($phoneMap as $item): ?><a class="ui-phone-pill" href="tel:<?= e($item['phone']) ?>" title="<?= e($item['source']) ?>"><?= ltr_phone($item['phone']) ?></a><?php endforeach; endif; ?></div></td>
            <td><div class="ui-meta-stack"><span><?= e(implode('، ', array_map('condition_label', array_keys($conditions)))) ?></span><span><?= $cities ? e(implode('، ', array_keys($cities))) : 'شهر نامشخص' ?></span></div></td>
            <td><span class="<?= $fresh['stale'] ? 'ui-stale' : 'ui-muted' ?>"><?= $fresh['text'] ?></span></td>
            <td><button type="button" class="ui-button ui-button-ghost js-toggle-detail" data-target="<?= e($detailId) ?>">جزئیات</button></td>
          </tr>
          <tr id="<?= e($detailId) ?>" class="ui-detail-row" hidden><td colspan="7"><div class="ui-offer-grid">
            <?php foreach ($rows as $offer): $contacts = $contactsByOffer[(int) $offer['id']] ?? []; ?>
              <article class="ui-offer-card">
                <div class="ui-offer-card-head"><div><strong><?= e($offer['source_name']) ?></strong><span><?= e(condition_label($offer['condition_normalized'])) ?> · <?= e($offer['city'] ?: 'شهر نامشخص') ?></span></div><strong class="ui-price"><?= e(format_price_display($offer['price_amount'] !== null ? (int) $offer['price_amount'] : null, $offer['price_type'])) ?></strong></div>
                <div class="ui-phone-list"><?php if (!$contacts): ?><span class="ui-muted">شماره تماس ثبت نشده</span><?php else: foreach ($contacts as $contact): ?><a class="ui-phone-pill" href="tel:<?= e($contact['phone']) ?>"><?= ltr_phone($contact['phone']) ?><small><?= e(market_contact_label((string) $contact['scope'])) ?></small></a><?php endforeach; endif; ?></div>
                <a class="ui-sub-link" href="<?= e($offer['canonical_url']) ?>" target="_blank" rel="noopener">مشاهده آگهی اصلی ↗</a>
              </article>
            <?php endforeach; ?>
          </div></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <nav class="ui-pagination" aria-label="صفحه‌بندی نتایج">
      <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? e($pageUrl($page - 1)) : '#' ?>">قبلی</a>
      <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        if ($startPage > 1) echo '<span class="ui-page-gap">…</span>';
        for ($p = $startPage; $p <= $endPage; $p++):
      ?>
        <a class="<?= $p === $page ? 'active' : '' ?>" href="<?= e($pageUrl($p)) ?>"><?= number_format($p) ?></a>
      <?php endfor;
        if ($endPage < $totalPages) echo '<span class="ui-page-gap">…</span>';
      ?>
      <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page < $totalPages ? e($pageUrl($page + 1)) : '#' ?>">بعدی</a>
    </nav>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'بازار خطوط — روندینو';
$active = 'market';
require __DIR__ . '/layout.php';
