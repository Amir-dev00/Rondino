<?php
$pdo = Database::pdo();
$sources = $pdo->query('SELECT * FROM sources ORDER BY id')->fetchAll();
$csrf = Csrf::token();
$autoMin = (int) config('auto_refresh_minutes', 30);
$crawler = new Crawler();
$progressBySlug = [];
foreach ($crawler->allProgress() as $p) {
    $progressBySlug[$p['slug']] = $p;
}

$latestSuccess = null;
foreach ($sources as $s) {
    if (!empty($s['last_success_at']) && ($latestSuccess === null || $s['last_success_at'] > $latestSuccess)) {
        $latestSuccess = $s['last_success_at'];
    }
}

/**
 * Compact metric chip with Persian label + optional tooltip.
 */
function source_metric(string $label, string $valueHtml, string $tip, string $jsClass = ''): string
{
    $cls = 'source-metric' . ($jsClass !== '' ? ' ' . $jsClass : '');
    return '<div class="' . e($cls) . '" title="' . e($tip) . '">'
        . '<span class="source-metric-label">' . e($label)
        . ' <span class="metric-tip" aria-label="' . e($tip) . '">؟</span></span>'
        . '<span class="source-metric-value ltr-num">' . $valueHtml . '</span>'
        . '</div>';
}

ob_start();
?>
<div class="page-head">
  <div>
    <h1>وضعیت منابع و خزش</h1>
    <p class="page-meta">آمار اجرای فعلی از آمار ذخیره‌شده در دیتابیس جداست · آخرین موفقیت کلی: <?= $latestSuccess ? fa_datetime($latestSuccess) : '—' ?></p>
  </div>
</div>

<div class="panel">
  <input type="hidden" id="page-csrf" name="_csrf" value="<?= e($csrf) ?>">
  <div class="sources-toolbar">
    <div class="actions">
      <button type="button" id="btn-refresh-all" data-csrf="<?= e($csrf) ?>">به‌روزرسانی همه</button>
      <button type="button" id="btn-pause-all" class="secondary" hidden>توقف دسته‌ها</button>
    </div>
    <span class="muted" id="refresh-status">دسته‌های کوتاه؛ بستن تب کار را متوقف می‌کند و با بازگشت از همان نقطه ادامه می‌دهد.</span>
  </div>
  <div class="progress" id="refresh-progress" hidden><span></span></div>
  <div id="refresh-detail" class="muted" style="margin-top:.6rem;white-space:pre-line"></div>
  <p class="muted" style="margin:0">با باز بودن این صفحه، منابع سررسید‌شده هر <?= (int) $autoMin ?> دقیقه یک‌بار به‌روز می‌شوند.</p>
</div>

<div class="sources-legend panel">
  <strong>راهنمای کوتاه:</strong>
  <span><b>اجرای فعلی</b> = نتیجه همین خزش</span>
  <span><b>موجودی ذخیره‌شده</b> = کل سوابق MySQL برای همین منبع</span>
</div>

<div class="panel sources-panel">
  <table class="table sources-table" id="sources-table">
    <thead>
      <tr>
        <th>منبع</th>
        <th>اجرای فعلی خزش</th>
        <th>موجودی ذخیره‌شده در دیتابیس</th>
        <th>سلامت منبع</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sources as $s):
        $hasError = !empty($s['last_error']);
        $okRecent = !empty($s['last_success_at']) && !$hasError;
        $prog = $progressBySlug[$s['slug']] ?? null;
        $passOffers = (int) ($prog['unique_offers_pass'] ?? $prog['offers_found'] ?? $s['last_offers_found'] ?? 0);
        $inserted = (int) ($prog['inserted_pass'] ?? 0);
        $updated = (int) ($prog['updated_pass'] ?? 0);
        $pages = (int) ($prog['pages_fetched'] ?? 0);
        $simsPass = (int) ($prog['distinct_sims_pass'] ?? 0);
        $batchOffers = (int) ($prog['last_batch_offers'] ?? 0);
        $dbOffers = (int) ($prog['db_stored_offers'] ?? 0);
        $dbSims = (int) ($prog['db_unique_sims'] ?? $prog['db_unique_offers'] ?? 0);
        $dbWithContact = (int) ($prog['db_offers_with_contact'] ?? $prog['contact_offers'] ?? 0);
        $dbPhones = (int) ($prog['db_phone_records'] ?? 0);
    ?>
      <tr data-slug="<?= e($s['slug']) ?>" data-interval="<?= (int) $s['refresh_interval_minutes'] ?>" data-success="<?= e($s['last_success_at'] ?? '') ?>">
        <td class="source-identity">
          <strong><?= e($s['name']) ?></strong>
          <div class="muted" style="overflow-wrap:anywhere"><?= e($s['base_url']) ?></div>
          <?php if (!(int) $s['is_enabled']): ?>
            <span class="nav-badge" style="color:var(--warn)">خاموش</span>
          <?php endif; ?>
        </td>

        <td class="js-pass-cell source-stats-cell">
          <div class="source-stat-block current">
            <div class="source-stat-block-title">اجرای فعلی</div>
            <div><strong class="js-pass-status"><?= e((string) ($prog['status_fa'] ?? '—')) ?></strong></div>
            <div class="muted js-pass-label"><?= e((string) ($prog['progress_label'] ?? '—')) ?></div>
            <div class="source-metric-grid">
              <?= source_metric(
                  'آگهی‌های یکتای اجرای فعلی',
                  (string) $passOffers,
                  'تعداد آگهی‌های یکتایی که در همین گذر خزش شناسایی شده‌اند. این عدد کل موجودی تاریخی نیست.',
                  'js-metric-pass-offers'
              ) ?>
              <?= source_metric(
                  'سیم‌کارت یکتای این گذر',
                  (string) $simsPass,
                  'تعداد شماره سیم‌کارت‌های متمایزی که در همین گذر دیده شده‌اند.',
                  'js-metric-pass-sims'
              ) ?>
              <?= source_metric(
                  'جدید ثبت‌شده',
                  (string) $inserted,
                  'آگهی‌هایی که در این گذر برای اولین‌بار در دیتابیس درج شده‌اند (در صورت پشتیبانی مسیر افزایشی).',
                  'js-metric-inserted'
              ) ?>
              <?= source_metric(
                  'به‌روزرسانی‌شده',
                  (string) $updated,
                  'آگهی‌های از قبل موجود که در این گذر دوباره دیده و به‌روز شده‌اند.',
                  'js-metric-updated'
              ) ?>
              <?= source_metric(
                  'صفحات دریافت‌شده',
                  (string) $pages,
                  'تعداد صفحاتی که در این گذر از منبع خوانده شده است.',
                  'js-metric-pages'
              ) ?>
              <?= source_metric(
                  'آگهی در آخرین دسته',
                  (string) $batchOffers,
                  'تعداد آگهی در آخرین دستهٔ پردازش‌شدهٔ این گذر.',
                  'js-metric-batch'
              ) ?>
            </div>
            <div class="muted js-metric-batch-time source-subline">
              زمان آخرین دسته: <?= !empty($prog['last_batch_at']) ? fa_datetime($prog['last_batch_at']) : '—' ?>
            </div>
            <div class="muted js-pass-coverage source-subline">
              <?php
                $covComplete = !empty($prog['inventory_path']);
                $covScope = (string) ($prog['coverage_scope'] ?? '');
                if ($covComplete) {
                    echo 'پوشش: موجودی لیست API';
                    if (!empty($prog['source_total_elements'])) {
                        echo ' · اعلام منبع: ' . (int) $prog['source_total_elements'];
                    }
                } elseif ($covScope === 'homepage_or_html_sample') {
                    echo 'پوشش: نمونه صفحه اصلی (موجودی کامل نیست)';
                } elseif ($covScope !== '') {
                    echo 'پوشش: ' . e($covScope);
                } else {
                    echo 'پوشش: هنوز مشخص نشده';
                }
                if (!empty($prog['stop_reason'])) {
                    echo ' · توقف: ' . e((string) $prog['stop_reason']);
                }
              ?>
            </div>
            <?php /* Keep legacy hooks for JS that still targets these classes */ ?>
            <div class="js-pass-stats" hidden></div>
            <div class="js-pass-contact" hidden></div>
            <div class="js-pass-db" hidden></div>
            <div class="js-offers-found" hidden><?= $passOffers ?></div>
          </div>
        </td>

        <td class="source-stats-cell">
          <div class="source-stat-block stored">
            <div class="source-stat-block-title">موجودی ذخیره‌شده</div>
            <div class="source-metric-grid">
              <?= source_metric(
                  'کل آگهی‌های ذخیره‌شده',
                  (string) $dbOffers,
                  'تعداد کل رکوردهای آگهی این منبع در MySQL، مستقل از گذر فعلی.',
                  'js-metric-db-offers'
              ) ?>
              <?= source_metric(
                  'کل سیم‌کارت‌های یکتای ذخیره‌شده',
                  (string) $dbSims,
                  'تعداد شماره سیم‌کارت‌های متمایز مرتبط با این منبع در دیتابیس، صرف‌نظر از تعداد آگهی هر شماره.',
                  'js-metric-db-sims'
              ) ?>
              <?= source_metric(
                  'آگهی دارای تماس تأییدشده',
                  (string) $dbWithContact,
                  'تعداد آگهی‌هایی از این منبع که حداقل یک شماره فروشندهٔ ذخیره‌شده دارند.',
                  'js-metric-db-with-contact'
              ) ?>
              <?= source_metric(
                  'رکوردهای تلفن فروشنده',
                  (string) $dbPhones,
                  'تعداد کل شماره‌های تماس فروشندهٔ ذخیره‌شده برای آگهی‌های این منبع.',
                  'js-metric-db-phones'
              ) ?>
            </div>
          </div>
        </td>

        <td class="source-stats-cell">
          <div class="source-stat-block health">
            <div class="source-stat-block-title">سلامت</div>
            <div class="last-success <?= $okRecent ? 'status-ok-text' : '' ?>">
              موفقیت: <?= fa_datetime($s['last_success_at']) ?>
            </div>
            <div class="muted js-pass-retry">تلاش بعدی: <?= e((string) ($prog['next_retry_at_fa'] ?? '—')) ?></div>
            <div class="last-error" style="margin-top:.35rem">
              <?php if ($hasError): ?>
                <span class="status-fail js-err"><?= e($s['last_error']) ?></span>
              <?php else: ?>
                <span class="status-ok-text js-err">بدون خطای اخیر</span>
              <?php endif; ?>
            </div>
          </div>
        </td>

        <td class="actions-cell">
          <a class="secondary btn-like" href="/?r=source&amp;slug=<?= e(urlencode((string) $s['slug'])) ?>">موجودی کامل</a>
          <button type="button" class="secondary btn-refresh-one" data-slug="<?= e($s['slug']) ?>" data-csrf="<?= e($csrf) ?>">به‌روزرسانی</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
window.RONDINO_AUTO_REFRESH_MINUTES = <?= (int) $autoMin ?>;
window.RONDINO_CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php
$content = ob_get_clean();
$title = 'وضعیت منابع — روندینو';
$active = 'sources';
require __DIR__ . '/layout.php';
