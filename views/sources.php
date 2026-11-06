<?php
$pdo = Database::pdo();
// Panel listing excludes permanently hidden sources (e.g. setaresim); historical DB rows stay intact.
$sources = Inventory::allSources();
$csrf = Csrf::token();
$autoMin = (int) config('auto_refresh_minutes', 30);
$crawler = new Crawler();
$progressBySlug = [];
foreach ($crawler->allProgress() as $p) {
    if (Inventory::isHiddenFromPanel((string) $p['slug'])) {
        continue;
    }
    $progressBySlug[$p['slug']] = $p;
}

$activeCount = 0;
$totalOffers = 0;
$totalSims = 0;
$latestSuccess = null;
$receivingNow = [];
foreach ($sources as $s) {
    if ((int) $s['is_enabled']) {
        $activeCount++;
    }
    $prog = $progressBySlug[$s['slug']] ?? null;
    $totalOffers += (int) ($prog['db_stored_offers'] ?? 0);
    $totalSims += (int) ($prog['db_unique_sims'] ?? $prog['db_unique_offers'] ?? 0);
    if (!empty($s['last_success_at']) && ($latestSuccess === null || $s['last_success_at'] > $latestSuccess)) {
        $latestSuccess = $s['last_success_at'];
    }
    if (($prog['user_status'] ?? '') === CrawlStatusCopy::STATUS_RECEIVING) {
        $receivingNow[] = $prog;
    }
}

/**
 * Host-only display for English base URLs.
 */
function source_host_label(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? $host : $url;
}

ob_start();
?>
<div class="page-head">
  <div>
    <h1>سایت‌ها</h1>
    <p class="page-meta">وضعیت دریافت آگهی از وب‌سایت‌های متصل</p>
  </div>
</div>

<section class="sources-overview" aria-label="خلاصه">
  <article class="sources-overview-item">
    <div class="sources-overview-label">سایت‌های فعال</div>
    <div class="sources-overview-value ltr-num"><?= number_format($activeCount) ?></div>
  </article>
  <article class="sources-overview-item">
    <div class="sources-overview-label">کل آگهی‌های ذخیره‌شده</div>
    <div class="sources-overview-value ltr-num"><?= number_format($totalOffers) ?></div>
  </article>
  <article class="sources-overview-item">
    <div class="sources-overview-label">شماره‌های مختلف</div>
    <div class="sources-overview-value ltr-num"><?= number_format($totalSims) ?></div>
  </article>
  <article class="sources-overview-item">
    <div class="sources-overview-label">آخرین به‌روزرسانی</div>
    <div class="sources-overview-value sources-overview-time"><?= $latestSuccess ? fa_datetime($latestSuccess) : '—' ?></div>
  </article>
</section>

<div class="panel sources-toolbar-panel">
  <input type="hidden" id="page-csrf" name="_csrf" value="<?= e($csrf) ?>">
  <div class="sources-toolbar">
    <div class="actions">
      <button type="button" id="btn-refresh-all" data-csrf="<?= e($csrf) ?>" title="به‌روزرسانی سریع همه سایت‌ها">به‌روزرسانی سریع همه</button>
      <button type="button" id="btn-pause-all" class="secondary" hidden>توقف دریافت اطلاعات</button>
    </div>
    <span class="muted" id="refresh-status">با باز بودن این صفحه، سایت‌های سررسید‌شده هر <?= (int) $autoMin ?> دقیقه با «به‌روزرسانی سریع» بررسی می‌شوند.</span>
  </div>
  <div class="progress" id="refresh-progress" hidden><span></span></div>
  <div id="refresh-detail" class="muted sources-refresh-detail" hidden></div>
</div>

<section
  id="sources-activity"
  class="panel sources-activity<?= $receivingNow === [] ? ' is-empty' : '' ?>"
  aria-live="polite"
  <?= $receivingNow === [] ? 'hidden' : '' ?>
>
  <h2 class="sources-activity-title">فعالیت جاری</h2>
  <div id="sources-activity-body">
    <?php if ($receivingNow !== []):
        $act = $receivingNow[0];
        $pct = $act['progress_pct'] ?? null;
        $modeFa = (string) ($act['crawl_mode_fa'] ?? CrawlStatusCopy::modeLabel($act['crawl_mode'] ?? null));
    ?>
      <div class="sources-activity-row">
        <strong class="js-activity-name"><?= e((string) ($act['name'] ?? $act['slug'])) ?></strong>
        <?php if ($modeFa !== ''): ?>
          <span class="sources-mode-chip js-activity-mode"><?= e($modeFa) ?></span>
        <?php else: ?>
          <span class="sources-mode-chip js-activity-mode" hidden></span>
        <?php endif; ?>
        <span class="muted js-activity-summary"><?= e((string) ($act['user_summary'] ?? 'در حال دریافت اطلاعات')) ?></span>
      </div>
      <div
        class="sources-progress<?= $pct === null ? ' is-indeterminate' : '' ?>"
        id="sources-activity-bar"
        role="progressbar"
        <?= $pct !== null ? 'aria-valuenow="' . (int) $pct . '" aria-valuemin="0" aria-valuemax="100"' : 'aria-valuetext="در حال دریافت"' ?>
      ><span style="<?= $pct !== null ? 'width:' . (int) $pct . '%' : '' ?>"></span></div>
      <div class="muted sources-activity-meta">
        <span class="js-activity-line"><?= e((string) ($act['user_activity'] ?: 'دریافت اطلاعات در جریان است.')) ?></span>
        <span class="js-activity-db">کل ذخیره‌شده: <bdi class="ltr-num"><?= number_format((int) ($act['db_stored_offers'] ?? 0)) ?></bdi></span>
        <span class="js-activity-updated">آخرین تأیید: <?= e((string) ($act['updated_at_fa'] ?? '—')) ?></span>
      </div>
    <?php endif; ?>
  </div>
</section>

<dialog id="full-crawl-confirm" class="sources-confirm" aria-labelledby="full-crawl-title">
  <form method="dialog" class="sources-confirm-body">
    <h3 id="full-crawl-title">بررسی کامل سایت شروع شود؟</h3>
    <p id="full-crawl-desc">تمام آگهی‌های این سایت دوباره بررسی می‌شوند. اطلاعات قبلی حذف نمی‌شوند، اما این کار ممکن است زمان‌بر باشد.</p>
    <div class="sources-confirm-actions">
      <button type="submit" value="cancel" class="secondary">انصراف</button>
      <button type="submit" value="confirm" class="btn-confirm-full">شروع بررسی کامل</button>
    </div>
  </form>
</dialog>

<div class="sources-list" id="sources-table">
  <?php foreach ($sources as $s):
      $prog = $progressBySlug[$s['slug']] ?? null;
      $userStatus = (string) ($prog['user_status'] ?? CrawlStatusCopy::STATUS_NEVER);
      $statusLabel = (string) ($prog['user_status_label'] ?? CrawlStatusCopy::statusLabel($userStatus));
      $summary = (string) ($prog['user_summary'] ?? '');
      $next = (string) ($prog['user_next'] ?? '');
      $passOffers = (int) ($prog['unique_offers_pass'] ?? $prog['offers_found'] ?? $s['last_offers_found'] ?? 0);
      $inserted = (int) ($prog['inserted_pass'] ?? 0);
      $showInserted = is_array($prog) && array_key_exists('inserted_pass', $prog);
      $pages = (int) ($prog['pages_fetched'] ?? 0);
      $updatedPass = (int) ($prog['updated_pass'] ?? 0);
      $simsPass = (int) ($prog['distinct_sims_pass'] ?? 0);
      $batchOffers = (int) ($prog['last_batch_offers'] ?? 0);
      $dbOffers = (int) ($prog['db_stored_offers'] ?? 0);
      $dbSims = (int) ($prog['db_unique_sims'] ?? $prog['db_unique_offers'] ?? 0);
      $dbWithContact = (int) ($prog['db_offers_with_contact'] ?? $prog['contact_offers'] ?? 0);
      $dbPhones = (int) ($prog['db_phone_records'] ?? 0);
      $stopRaw = (string) ($prog['stop_reason_raw'] ?? $prog['stop_reason'] ?? '');
      $hasError = !empty($prog['last_error']) || !empty($s['last_error']);
      $errText = (string) ($prog['last_error'] ?? $s['last_error'] ?? '');
      $enabled = (int) $s['is_enabled'] === 1;
  ?>
  <article
    class="source-card"
    data-slug="<?= e($s['slug']) ?>"
    data-interval="<?= (int) $s['refresh_interval_minutes'] ?>"
    data-success="<?= e($s['last_success_at'] ?? '') ?>"
    data-user-status="<?= e($userStatus) ?>"
  >
    <header class="source-card-head">
      <div class="source-identity">
        <strong class="source-name"><?= e($s['name']) ?></strong>
        <div class="muted source-host ltr-num" dir="ltr"><?= e(source_host_label((string) $s['base_url'])) ?></div>
      </div>
      <span class="source-status source-status-<?= e($userStatus) ?> js-pass-status"><?= e($statusLabel) ?></span>
    </header>

    <p class="source-summary js-user-summary"><?= e($summary) ?></p>
    <?php if ($next !== ''): ?>
      <p class="source-next muted js-user-next"><?= e($next) ?></p>
    <?php else: ?>
      <p class="source-next muted js-user-next" hidden></p>
    <?php endif; ?>

    <div class="source-metrics">
      <div class="source-metric js-metric-db-offers">
        <span class="source-metric-label">کل آگهی‌های ذخیره‌شده</span>
        <span class="source-metric-value ltr-num"><?= number_format($dbOffers) ?></span>
      </div>
      <div class="source-metric js-metric-db-sims">
        <span class="source-metric-label">شماره‌های مختلف</span>
        <span class="source-metric-value ltr-num"><?= number_format($dbSims) ?></span>
      </div>
      <div class="source-metric js-metric-pass-offers">
        <span class="source-metric-label">آگهی‌های این بررسی</span>
        <span class="source-metric-value ltr-num"><?= number_format($passOffers) ?></span>
      </div>
      <div class="source-metric js-metric-inserted"<?= $showInserted ? '' : ' hidden' ?>>
        <span class="source-metric-label">آگهی‌های جدید</span>
        <span class="source-metric-value ltr-num"><?= number_format($inserted) ?></span>
      </div>
      <div class="source-metric source-metric-wide">
        <span class="source-metric-label">آخرین به‌روزرسانی موفق</span>
        <span class="source-metric-value js-last-success"><?= !empty($s['last_success_at']) ? fa_datetime($s['last_success_at']) : '—' ?></span>
      </div>
    </div>

    <?php
      $busy = in_array($userStatus, [CrawlStatusCopy::STATUS_RECEIVING], true)
          || in_array((string) ($prog['status'] ?? ''), ['running'], true);
    ?>
    <div class="source-card-actions">
      <?php if ($enabled): ?>
        <div class="source-crawl-actions">
          <button
            type="button"
            class="btn-crawl-quick"
            data-slug="<?= e($s['slug']) ?>"
            data-csrf="<?= e($csrf) ?>"
            data-crawl-mode="incremental"
            <?= $busy ? 'disabled' : '' ?>
            title="آگهی‌های جدید و تغییرات اخیر سایت بررسی می‌شوند."
          >
            <span class="btn-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-2.6-6.2"/><polyline points="21 3 21 9 15 9"/></svg>
            </span>
            به‌روزرسانی سریع
          </button>
          <button
            type="button"
            class="secondary btn-crawl-full"
            data-slug="<?= e($s['slug']) ?>"
            data-csrf="<?= e($csrf) ?>"
            data-crawl-mode="full"
            <?= $busy ? 'disabled' : '' ?>
            title="تمام آگهی‌های سایت دوباره بررسی می‌شوند. این کار ممکن است زمان بیشتری ببرد."
          >
            <span class="btn-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
            </span>
            بررسی کامل سایت
          </button>
          <a class="secondary btn-like" href="/?r=source&amp;slug=<?= e(urlencode((string) $s['slug'])) ?>">مشاهده شماره‌ها</a>
        </div>
        <p class="source-action-hints muted">
          <span><b>به‌روزرسانی سریع:</b> آگهی‌های جدید و تغییرات اخیر سایت بررسی می‌شوند.</span>
          <span><b>بررسی کامل:</b> تمام آگهی‌های سایت دوباره بررسی می‌شوند. این کار ممکن است زمان بیشتری ببرد.</span>
        </p>
      <?php else: ?>
        <div class="source-crawl-actions">
          <a class="secondary btn-like" href="/?r=source&amp;slug=<?= e(urlencode((string) $s['slug'])) ?>">مشاهده شماره‌ها</a>
        </div>
      <?php endif; ?>
      <?php if ($hasError): ?>
        <button type="button" class="secondary js-toggle-error" data-target="err-<?= e($s['slug']) ?>">مشاهده خطا</button>
      <?php endif; ?>
    </div>

    <?php if ($hasError): ?>
      <div class="source-error js-err-block" id="err-<?= e($s['slug']) ?>" hidden>
        <span class="status-fail js-err"><?= e($errText) ?></span>
      </div>
    <?php else: ?>
      <div class="source-error js-err-block" hidden>
        <span class="js-err"></span>
      </div>
    <?php endif; ?>

    <details class="source-details">
      <summary>جزئیات بیشتر</summary>
      <div class="source-details-body">
        <div class="source-details-grid">
          <div class="source-metric js-metric-pass-sims">
            <span class="source-metric-label">شماره‌های این بررسی</span>
            <span class="source-metric-value ltr-num"><?= number_format($simsPass) ?></span>
          </div>
          <div class="source-metric js-metric-updated">
            <span class="source-metric-label">آگهی‌های به‌روزشده</span>
            <span class="source-metric-value ltr-num"><?= number_format($updatedPass) ?></span>
          </div>
          <div class="source-metric js-metric-pages">
            <span class="source-metric-label">صفحات بررسی‌شده</span>
            <span class="source-metric-value ltr-num"><?= number_format($pages) ?></span>
          </div>
          <div class="source-metric js-metric-batch">
            <span class="source-metric-label">آگهی در آخرین دسته</span>
            <span class="source-metric-value ltr-num"><?= number_format($batchOffers) ?></span>
          </div>
          <div class="source-metric js-metric-db-with-contact">
            <span class="source-metric-label">آگهی دارای تماس</span>
            <span class="source-metric-value ltr-num"><?= number_format($dbWithContact) ?></span>
          </div>
          <div class="source-metric js-metric-db-phones">
            <span class="source-metric-label">شماره تماس ذخیره‌شده</span>
            <span class="source-metric-value ltr-num"><?= number_format($dbPhones) ?></span>
          </div>
        </div>
        <div class="muted source-subline js-metric-batch-time">
          زمان آخرین دسته: <?= !empty($prog['last_batch_at'] ?? null) ? fa_datetime((string) $prog['last_batch_at']) : '—' ?>
        </div>
        <div class="muted source-subline js-pass-retry">
          تلاش بعدی: <?= e((string) ($prog['next_retry_at_fa'] ?? '—')) ?>
        </div>
        <details class="source-tech">
          <summary>جزئیات فنی</summary>
          <div class="source-tech-body muted">
            <div>حالت بررسی: <span class="ltr-num js-tech-mode"><?= e((string) ($prog['crawl_mode'] ?? '—')) ?></span></div>
            <div>وضعیت داخلی: <span class="ltr-num js-tech-status"><?= e((string) ($prog['status'] ?? '—')) ?></span></div>
            <div>دلیل توقف: <span class="ltr-num js-tech-stop"><?= e($stopRaw !== '' ? $stopRaw : '—') ?></span></div>
            <div class="js-pass-label">برچسب پیشرفت: <?= e((string) ($prog['progress_label'] ?? '—')) ?></div>
            <div class="js-pass-coverage">
              <?php
                if (!empty($prog['inventory_path'])) {
                    echo 'مسیر پوشش: فهرست کامل سایت';
                    if (!empty($prog['source_total_elements'])) {
                        echo ' · اعلام سایت: ' . number_format((int) $prog['source_total_elements']);
                    }
                } elseif (($prog['coverage_scope'] ?? '') === 'homepage_or_html_sample') {
                    echo 'مسیر پوشش: نمونه صفحه اصلی';
                } elseif (!empty($prog['coverage_scope'])) {
                    echo 'مسیر پوشش: ' . e((string) $prog['coverage_scope']);
                } else {
                    echo 'مسیر پوشش: هنوز مشخص نشده';
                }
              ?>
            </div>
          </div>
        </details>
      </div>
    </details>

    <?php /* Legacy hooks for older JS consumers */ ?>
    <div class="js-pass-stats" hidden></div>
    <div class="js-pass-contact" hidden></div>
    <div class="js-pass-db" hidden></div>
    <div class="js-offers-found" hidden><?= $passOffers ?></div>
  </article>
  <?php endforeach; ?>
</div>
<script>
window.RONDINO_AUTO_REFRESH_MINUTES = <?= (int) $autoMin ?>;
window.RONDINO_CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php
$content = ob_get_clean();
$title = 'سایت‌ها — روندینو';
$active = 'sources';
require __DIR__ . '/layout.php';
