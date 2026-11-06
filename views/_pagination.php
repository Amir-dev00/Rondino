<?php
/** @var Pagination $pager */
/** @var array<string,scalar> $baseQuery */
if (!isset($pager) || !($pager instanceof Pagination)) {
    return;
}
$baseQuery = $baseQuery ?? [];
?>
<nav class="pager" aria-label="صفحه‌بندی">
  <div class="pager-meta">
    <?php if ($pager->total === 0): ?>
      بدون نتیجه
    <?php else: ?>
      <?= number_format($pager->fromItem()) ?>–<?= number_format($pager->toItem()) ?>
      از <?= number_format($pager->total) ?>
      · صفحه <?= number_format($pager->page) ?> از <?= number_format($pager->totalPages) ?>
    <?php endif; ?>
  </div>
  <div class="pager-controls">
    <?php if ($pager->page > 1): ?>
      <a class="pager-btn" href="<?= e($pager->url($baseQuery, 1)) ?>">اول</a>
      <a class="pager-btn" href="<?= e($pager->url($baseQuery, $pager->page - 1)) ?>">قبلی</a>
    <?php else: ?>
      <span class="pager-btn disabled">اول</span>
      <span class="pager-btn disabled">قبلی</span>
    <?php endif; ?>

    <?php
    $window = 2;
    $start = max(1, $pager->page - $window);
    $end = min($pager->totalPages, $pager->page + $window);
    for ($p = $start; $p <= $end; $p++):
    ?>
      <?php if ($p === $pager->page): ?>
        <span class="pager-btn current" aria-current="page"><?= $p ?></span>
      <?php else: ?>
        <a class="pager-btn" href="<?= e($pager->url($baseQuery, $p)) ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($pager->page < $pager->totalPages): ?>
      <a class="pager-btn" href="<?= e($pager->url($baseQuery, $pager->page + 1)) ?>">بعدی</a>
      <a class="pager-btn" href="<?= e($pager->url($baseQuery, $pager->totalPages)) ?>">آخر</a>
    <?php else: ?>
      <span class="pager-btn disabled">بعدی</span>
      <span class="pager-btn disabled">آخر</span>
    <?php endif; ?>
  </div>
  <form method="get" action="/" class="pager-per">
    <?php foreach ($baseQuery as $k => $v): if ($k === 'per' || $k === 'page') continue; ?>
      <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="page" value="1">
    <label>در هر صفحه
      <select name="per" onchange="this.form.submit()">
        <?php foreach (Pagination::ALLOWED_PER_PAGE as $n): ?>
          <option value="<?= $n ?>" <?= $pager->perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
</nav>
