<?php
/** @var list<array{label:string,clear:array}> $activeChips */
if (empty($activeChips)) {
    return;
}
?>
<div class="chips" aria-label="فیلترهای فعال">
  <?php foreach ($activeChips as $chip): ?>
    <span class="chip">
      <?= e($chip['label']) ?>
      <a href="/?<?= e(http_build_query($chip['clear'])) ?>" title="حذف فیلتر" aria-label="حذف فیلتر">×</a>
    </span>
  <?php endforeach; ?>
  <?php if (!empty($clearAllQuery)): ?>
    <a class="chip-clear-all" href="/?<?= e(http_build_query($clearAllQuery)) ?>">پاک کردن همه</a>
  <?php endif; ?>
</div>
