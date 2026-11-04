<?php
$q = get_q('q', '') ?? '';
$code = get_q('code', '') ?? '';
$condition = get_q('condition', '') ?? '';
$city = get_q('city', '') ?? '';
$sourceId = get_q('source', '') ?? '';
$min = get_q('min', '') ?? '';
$max = get_q('max', '') ?? '';

$pdo = Database::pdo();
$sources = $pdo->query('SELECT id, name FROM sources ORDER BY name')->fetchAll();

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
foreach ($offers as $o) {
    $groups[$o['sim_number']][] = $o;
}

ob_start();
?>
<div class="hero">
  <h1>بازار خطوط</h1>
  <p>جست‌وجو و مقایسه آگهی‌های ۰۹۱۲ از منابع مختلف.</p>
</div>
<div class="panel">
  <form class="filters" method="get" action="/">
    <input type="hidden" name="r" value="market">
    <label>شماره (کامل یا بخشی)
      <input class="ltr" name="q" value="<?= e($q) ?>" placeholder="0912...">
    </label>
    <label>رقم کد
      <select name="code">
        <option value="">همه</option>
        <?php for ($i = 0; $i <= 9; $i++): ?>
          <option value="<?= $i ?>" <?= $code === (string) $i ? 'selected' : '' ?>><?= $i ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <label>وضعیت خط
      <select name="condition">
        <option value="">همه</option>
        <?php foreach (['factory_sealed','new_unspecified','like_new','used','unknown'] as $c): ?>
          <option value="<?= $c ?>" <?= $condition === $c ? 'selected' : '' ?>><?= e(condition_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>شهر
      <input name="city" value="<?= e($city) ?>">
    </label>
    <label>منبع
      <select name="source">
        <option value="">همه</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= $sourceId === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>حداقل قیمت
      <input class="ltr" name="min" value="<?= e($min) ?>">
    </label>
    <label>حداکثر قیمت
      <input class="ltr" name="max" value="<?= e($max) ?>">
    </label>
    <button type="submit">اعمال فیلتر</button>
  </form>
</div>
<div class="panel">
  <?php if (!$groups): ?>
    <p class="muted">آگهی‌ای با این فیلترها پیدا نشد. از «وضعیت منابع» بروزرسانی کنید.</p>
  <?php else: ?>
    <?php foreach ($groups as $sim => $rows): ?>
      <div class="sim-group">
        <div class="sim-title">
          <strong class="ltr"><?= e($sim) ?></strong>
          <span class="muted"><?= count($rows) ?> آگهی</span>
        </div>
        <?php foreach ($rows as $o): ?>
          <div class="offer">
            <div>
              <strong><?= e(format_toman($o['price_amount'] !== null ? (int) $o['price_amount'] : null)) ?></strong>
              <span class="badge"><?= e(price_type_label($o['price_type'])) ?></span>
              <span class="badge"><?= e(condition_label($o['condition_normalized'])) ?></span>
            </div>
            <div class="offer-meta">
              <span>منبع: <?= e($o['source_name']) ?></span>
              <?php if ($o['city']): ?><span>شهر: <?= e($o['city']) ?></span><?php endif; ?>
              <span>آخرین بررسی: <?= fa_datetime($o['last_checked_at']) ?></span>
              <a href="<?= e($o['canonical_url']) ?>" target="_blank" rel="noopener">مشاهده آگهی اصلی</a>
            </div>
            <?php if ($o['price_raw']): ?><div class="muted">متن قیمت: <?= e($o['price_raw']) ?></div><?php endif; ?>
            <div>
              تماس:
              <?php
              $cs = $contactsByOffer[(int) $o['id']] ?? [];
              if (!$cs) {
                  echo '<span class="muted">نامشخص</span>';
              } else {
                  foreach ($cs as $c) {
                      $scope = $c['scope'] === 'listing' ? 'آگهی' : 'فروشگاه';
                      echo ltr_phone($c['phone']) . ' <span class="muted">(' . e($scope);
                      if ($c['label']) echo ' — ' . e($c['label']);
                      echo ')</span> ';
                  }
              }
              ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'بازار خطوط — روندینو';
$active = 'market';
require __DIR__ . '/layout.php';
