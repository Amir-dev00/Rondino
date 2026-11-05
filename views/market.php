<?php
$q = get_q('q', '') ?? '';
$code = get_q('code', '') ?? '';
$condition = get_q('condition', '') ?? '';
$city = get_q('city', '') ?? '';
$sourceId = get_q('source', '') ?? '';
$min = get_q('min', '') ?? '';
$max = get_q('max', '') ?? '';
$seenFromFa = trim((string) (get_q('seen_from', '') ?? ''));
$seenToFa = trim((string) (get_q('seen_to', '') ?? ''));
$dateFilterError = null;

$pdo = Database::pdo();
$sources = $pdo->query('SELECT id, name FROM sources ORDER BY name')->fetchAll();
$sourceNameById = [];
foreach ($sources as $s) {
    $sourceNameById[(string) $s['id']] = $s['name'];
}

$sql = 'SELECT o.*, sn.number AS sim_number, sn.code_digit, src.name AS source_name, src.slug AS source_slug
        FROM offers o
        JOIN sim_numbers sn ON sn.id = o.sim_number_id
        JOIN sources src ON src.id = o.source_id
        WHERE o.availability = \'available\'';
$params = [];
if ($q !== '') {
    $digits = Digits::only(Digits::normalize($q));
    $sql .= ' AND sn.number LIKE ?';
    $params[] = '%' . $digits . '%';
}
if ($code !== '') {
    $sql .= ' AND sn.code_digit = ?';
    $params[] = $code;
}
if ($condition !== '') {
    $sql .= ' AND o.condition_normalized = ?';
    $params[] = $condition;
}
if ($city !== '') {
    $sql .= ' AND o.city LIKE ?';
    $params[] = '%' . $city . '%';
}
if ($sourceId !== '') {
    $sql .= ' AND o.source_id = ?';
    $params[] = (int) $sourceId;
}
if ($min !== '' && ctype_digit($min)) {
    $sql .= ' AND o.price_amount >= ?';
    $params[] = (int) $min;
}
if ($max !== '' && ctype_digit($max)) {
    $sql .= ' AND o.price_amount <= ?';
    $params[] = (int) $max;
}
if ($seenFromFa !== '') {
    $rs = Jalali::rangeStartUtc($seenFromFa);
    if (!$rs['ok']) {
        $dateFilterError = $rs['error'];
    } else {
        $sql .= ' AND o.last_checked_at >= ?';
        $params[] = $rs['utc'];
    }
}
if ($seenToFa !== '' && $dateFilterError === null) {
    $re = Jalali::rangeEndUtc($seenToFa);
    if (!$re['ok']) {
        $dateFilterError = $re['error'];
    } else {
        $sql .= ' AND o.last_checked_at <= ?';
        $params[] = $re['utc'];
    }
}
$sql .= ' ORDER BY sn.number ASC, o.price_amount IS NULL, o.price_amount ASC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$offers = $stmt->fetchAll();

$offerIds = array_column($offers, 'id');
$contactsByOffer = [];
if ($offerIds) {
    $in = implode(',', array_fill(0, count($offerIds), '?'));
    $cstmt = $pdo->prepare("SELECT * FROM offer_contacts WHERE offer_id IN ($in) ORDER BY scope, phone");
    $cstmt->execute($offerIds);
    foreach ($cstmt->fetchAll() as $c) {
        $contactsByOffer[(int) $c['offer_id']][] = $c;
    }
}

$groups = [];
$latestCheck = null;
foreach ($offers as $o) {
    $groups[$o['sim_number']][] = $o;
    if (!empty($o['last_checked_at']) && ($latestCheck === null || $o['last_checked_at'] > $latestCheck)) {
        $latestCheck = $o['last_checked_at'];
    }
}

$moreOpen = ($city !== '' || $sourceId !== '' || $min !== '' || $max !== '' || $seenFromFa !== '' || $seenToFa !== '');

$chipBase = ['r' => 'market'];
$chipAll = static function (array $omit) use ($chipBase, $q, $code, $condition, $city, $sourceId, $min, $max, $seenFromFa, $seenToFa): array {
    $all = [
        'q' => $q, 'code' => $code, 'condition' => $condition, 'city' => $city,
        'source' => $sourceId, 'min' => $min, 'max' => $max,
        'seen_from' => $seenFromFa, 'seen_to' => $seenToFa,
    ];
    foreach ($omit as $k) {
        unset($all[$k]);
    }
    return array_merge($chipBase, array_filter($all, static fn ($v) => $v !== '' && $v !== null));
};
$activeChips = [];
if ($q !== '') {
    $activeChips[] = ['label' => 'شماره: ' . $q, 'clear' => $chipAll(['q'])];
}
if ($code !== '') {
    $activeChips[] = ['label' => 'کد: ' . $code, 'clear' => $chipAll(['code'])];
}
if ($condition !== '') {
    $activeChips[] = ['label' => 'وضعیت: ' . condition_label($condition), 'clear' => $chipAll(['condition'])];
}
if ($city !== '') {
    $activeChips[] = ['label' => 'شهر: ' . $city, 'clear' => $chipAll(['city'])];
}
if ($seenFromFa !== '') {
    $activeChips[] = ['label' => 'از: ' . $seenFromFa, 'clear' => $chipAll(['seen_from'])];
}
if ($seenToFa !== '') {
    $activeChips[] = ['label' => 'تا: ' . $seenToFa, 'clear' => $chipAll(['seen_to'])];
}
if ($sourceId !== '') {
    $activeChips[] = ['label' => 'منبع: ' . ($sourceNameById[$sourceId] ?? $sourceId), 'clear' => $chipAll(['source'])];
}
if ($min !== '') {
    $activeChips[] = ['label' => 'حداقل: ' . number_format((int) $min), 'clear' => $chipAll(['min'])];
}
if ($max !== '') {
    $activeChips[] = ['label' => 'حداکثر: ' . number_format((int) $max), 'clear' => $chipAll(['max'])];
}

function market_best_price(array $rows): array
{
    $best = null;
    foreach ($rows as $o) {
        if ($o['price_amount'] === null) {
            continue;
        }
        if ($best === null || (int) $o['price_amount'] < (int) $best['price_amount']) {
            $best = $o;
        }
    }
    if ($best) {
        return ['amount' => (int) $best['price_amount'], 'type' => $best['price_type']];
    }
    // Prefer negotiable label if any
    foreach ($rows as $o) {
        if ($o['price_type'] === 'negotiable') {
            return ['amount' => null, 'type' => 'negotiable'];
        }
    }
    return ['amount' => null, 'type' => $rows[0]['price_type'] ?? 'unknown'];
}

function market_freshness_label(?string $utc): array
{
    if (!$utc) {
        return ['text' => '—', 'stale' => false];
    }
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) {
        return ['text' => fa_datetime($utc), 'stale' => false];
    }
    $ageHours = (time() - $ts) / 3600;
    return [
        'text' => fa_datetime($utc),
        'stale' => $ageHours > 48,
    ];
}

ob_start();
?>
<div class="page-head">
  <div>
    <h1>بازار خطوط</h1>
  </div>
  <div class="page-meta">
    آخرین به‌روزرسانی داده‌ها: <?= $latestCheck ? fa_datetime($latestCheck) : '—' ?>
  </div>
</div>

<div class="panel">
  <form method="get" action="/" id="market-filter-form">
    <input type="hidden" name="r" value="market">

    <div class="search-bar">
      <label class="search-field">جست‌وجوی شماره
        <input class="ltr-num" name="q" value="<?= e($q) ?>" placeholder="0912…" inputmode="numeric" autocomplete="off" autofocus>
      </label>
      <button type="submit">جست‌وجو</button>
    </div>

    <div class="toolbar">
      <label class="tool">رقم کد
        <select name="code">
          <option value="">همه</option>
          <?php for ($i = 0; $i <= 9; $i++): ?>
            <option value="<?= $i ?>" <?= $code === (string) $i ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="tool">وضعیت خط
        <select name="condition">
          <option value="">همه</option>
          <?php foreach (['factory_sealed','new_unspecified','like_new','used','unknown'] as $c): ?>
            <option value="<?= $c ?>" <?= $condition === $c ? 'selected' : '' ?>><?= e(condition_label($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="tool-actions">
        <button type="submit" class="secondary">اعمال</button>
        <button type="button" class="secondary" id="btn-more-filters" aria-expanded="<?= $moreOpen ? 'true' : 'false' ?>">
          فیلترهای بیشتر
        </button>
      </div>
    </div>

    <div class="more-filters<?= $moreOpen ? ' open' : '' ?>" id="more-filters">
      <div class="more-grid">
        <label>شهر
          <input name="city" value="<?= e($city) ?>" placeholder="مثلاً تهران">
        </label>
        <label>منبع
          <select name="source">
            <option value="">همه</option>
            <?php foreach ($sources as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= $sourceId === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>حداقل قیمت <span class="field-hint">(تومان)</span>
          <input class="ltr-num" name="min" value="<?= e($min) ?>" inputmode="numeric">
        </label>
        <label>حداکثر قیمت <span class="field-hint">(تومان)</span>
          <input class="ltr-num" name="max" value="<?= e($max) ?>" inputmode="numeric">
        </label>
        <label>بررسی از تاریخ شمسی
          <input name="seen_from" class="jalali-date" value="<?= e($seenFromFa) ?>" placeholder="۱۴۰۵/۰۸/۰۱" autocomplete="off" dir="rtl">
        </label>
        <label>بررسی تا تاریخ شمسی
          <input name="seen_to" class="jalali-date" value="<?= e($seenToFa) ?>" placeholder="۱۴۰۵/۰۸/۱۴" autocomplete="off" dir="rtl">
          <span class="field-hint">کل روز پایانی به وقت تهران لحاظ می‌شود</span>
        </label>
      </div>
      <?php if ($dateFilterError): ?>
        <div class="flash error" style="margin-top:.75rem"><?= e($dateFilterError) ?></div>
      <?php endif; ?>
    </div>

    <?php if ($activeChips): ?>
      <div class="chips" aria-label="فیلترهای فعال">
        <?php foreach ($activeChips as $chip): ?>
          <span class="chip">
            <?= e($chip['label']) ?>
            <a href="/?<?= e(http_build_query($chip['clear'])) ?>" title="حذف فیلتر" aria-label="حذف فیلتر">×</a>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </form>
</div>

<div class="panel">
  <div class="results-meta">
    <div><?= count($groups) ?> شماره · <?= count($offers) ?> پیشنهاد</div>
    <div class="unit-note">قیمت‌ها به تومان</div>
  </div>

  <?php if (!$groups): ?>
    <div class="empty-state">آگهی‌ای با این فیلترها پیدا نشد. از «وضعیت منابع» بروزرسانی کنید.</div>
  <?php else: ?>

    <div class="market-table-wrap">
      <table class="market-table">
        <thead>
          <tr>
            <th>شماره</th>
            <th class="col-price">قیمت</th>
            <th>وضعیت</th>
            <th>شهر</th>
            <th>پیشنهادها</th>
            <th>تازگی</th>
            <th class="col-action"></th>
          </tr>
        </thead>
        <tbody>
        <?php $idx = 0; foreach ($groups as $sim => $rows):
            $idx++;
            $best = market_best_price($rows);
            $conds = [];
            $cities = [];
            $freshest = null;
            foreach ($rows as $o) {
                $conds[$o['condition_normalized']] = true;
                if (!empty($o['city'])) {
                    $cities[$o['city']] = true;
                }
                if ($freshest === null || ($o['last_checked_at'] ?? '') > $freshest) {
                    $freshest = $o['last_checked_at'] ?? null;
                }
            }
            $condLabels = array_map('condition_label', array_keys($conds));
            $fresh = market_freshness_label($freshest);
            $detailId = 'detail-' . $idx;
        ?>
          <tr class="sim-row">
            <td><?= ltr_sim($sim) ?></td>
            <td class="col-price <?= $best['type'] === 'negotiable' ? 'price-negotiable' : '' ?>">
              <?= e(format_price_display($best['amount'], $best['type'])) ?>
            </td>
            <td class="support"><?= e(implode('، ', $condLabels)) ?></td>
            <td class="support"><?= $cities ? e(implode('، ', array_keys($cities))) : '—' ?></td>
            <td class="support"><?= count($rows) ?></td>
            <td class="support <?= $fresh['stale'] ? 'fresh-stale' : '' ?>"><?= $fresh['text'] ?></td>
            <td class="col-action">
              <button type="button" class="btn-link js-toggle-detail" data-target="<?= e($detailId) ?>" aria-expanded="false">
                مشاهده پیشنهادها
              </button>
            </td>
          </tr>
          <tr class="detail-row" id="<?= e($detailId) ?>" hidden>
            <td colspan="7">
              <div class="seller-list">
                <?php foreach ($rows as $o):
                    $cs = $contactsByOffer[(int) $o['id']] ?? [];
                    $of = market_freshness_label($o['last_checked_at'] ?? null);
                ?>
                  <article class="seller-card">
                    <div class="seller-head">
                      <div>
                        <strong><?= e($o['source_name']) ?></strong>
                        <div class="support">
                          <?= e(condition_label($o['condition_normalized'])) ?>
                          <?php if ($o['city']): ?> · <?= e($o['city']) ?><?php endif; ?>
                          · <span class="<?= $of['stale'] ? 'fresh-stale' : '' ?>"><?= $of['text'] ?></span>
                        </div>
                      </div>
                      <div class="seller-price <?= $o['price_type'] === 'negotiable' ? 'price-negotiable' : '' ?>">
                        <?= e(format_price_display($o['price_amount'] !== null ? (int) $o['price_amount'] : null, $o['price_type'])) ?>
                        <?php if ($o['price_type'] === 'fixed' && $o['price_amount'] !== null): ?>
                          <span class="unit-note">تومان</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div>
                      <a href="<?= e($o['canonical_url']) ?>" target="_blank" rel="noopener">آگهی اصلی</a>
                    </div>
                    <div class="seller-contacts">
                      <?php if (!$cs): ?>
                        <span class="muted">تماس ثبت نشده</span>
                      <?php else: foreach ($cs as $c):
                          $scope = $c['scope'] === 'listing' ? 'تماس آگهی' : 'تماس فروشگاه';
                      ?>
                        <div class="contact-line">
                          <span class="contact-phone"><?= ltr_phone($c['phone']) ?></span>
                          <span class="muted">
                            <?= e($scope) ?><?php if ($c['label']): ?> — <?= e($c['label']) ?><?php endif; ?>
                          </span>
                        </div>
                      <?php endforeach; endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="market-cards">
      <?php $idx = 0; foreach ($groups as $sim => $rows):
          $idx++;
          $best = market_best_price($rows);
          $conds = [];
          $cities = [];
          $freshest = null;
          foreach ($rows as $o) {
              $conds[$o['condition_normalized']] = true;
              if (!empty($o['city'])) {
                  $cities[$o['city']] = true;
              }
              if ($freshest === null || ($o['last_checked_at'] ?? '') > $freshest) {
                  $freshest = $o['last_checked_at'] ?? null;
              }
          }
          $condLabels = array_map('condition_label', array_keys($conds));
          $fresh = market_freshness_label($freshest);
          $mDetailId = 'mdetail-' . $idx;
      ?>
        <article class="sim-card">
          <div class="sim-card-top">
            <div><?= ltr_sim($sim) ?></div>
            <div class="sim-card-price <?= $best['type'] === 'negotiable' ? 'price-negotiable' : '' ?>">
              <?= e(format_price_display($best['amount'], $best['type'])) ?>
              <?php if ($best['type'] === 'fixed' && $best['amount'] !== null): ?><span class="unit-note">تومان</span><?php endif; ?>
            </div>
          </div>
          <div class="sim-card-meta">
            <span><?= e(implode('، ', $condLabels)) ?></span>
            <span><?= $cities ? e(implode('، ', array_keys($cities))) : 'شهر نامشخص' ?></span>
            <span><?= count($rows) ?> پیشنهاد</span>
            <span class="<?= $fresh['stale'] ? 'fresh-stale' : '' ?>"><?= $fresh['text'] ?></span>
          </div>
          <div class="sim-card-actions">
            <button type="button" class="secondary js-toggle-detail" data-target="<?= e($mDetailId) ?>" aria-expanded="false">
              مشاهده پیشنهادها
            </button>
          </div>
          <div class="sim-card-detail" id="<?= e($mDetailId) ?>" hidden>
            <div class="seller-list">
              <?php foreach ($rows as $o):
                  $cs = $contactsByOffer[(int) $o['id']] ?? [];
              ?>
                <article class="seller-card">
                  <div class="seller-head">
                    <strong><?= e($o['source_name']) ?></strong>
                    <div class="seller-price">
                      <?= e(format_price_display($o['price_amount'] !== null ? (int) $o['price_amount'] : null, $o['price_type'])) ?>
                      <?php if ($o['price_type'] === 'fixed' && $o['price_amount'] !== null): ?>
                        <span class="unit-note">تومان</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <a href="<?= e($o['canonical_url']) ?>" target="_blank" rel="noopener">آگهی اصلی</a>
                  <div class="seller-contacts">
                    <?php if (!$cs): ?>
                      <span class="muted">تماس ثبت نشده</span>
                    <?php else: foreach ($cs as $c):
                        $scope = $c['scope'] === 'listing' ? 'تماس آگهی' : 'تماس فروشگاه';
                    ?>
                      <div class="contact-line">
                        <span class="contact-phone"><?= ltr_phone($c['phone']) ?></span>
                        <span class="muted"><?= e($scope) ?><?php if ($c['label']): ?> — <?= e($c['label']) ?><?php endif; ?></span>
                      </div>
                    <?php endforeach; endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'بازار خطوط — روندینو';
$active = 'market';
require __DIR__ . '/layout.php';
