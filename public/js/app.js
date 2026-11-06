(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  async function postRefresh(params) {
    const body = new URLSearchParams();
    Object.keys(params).forEach(function (k) { body.set(k, params[k]); });
    const res = await fetch('/?r=refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    });
    return res.json();
  }

  function applyProgressRows(allProgress) {
    if (!allProgress || !allProgress.length) return;
    allProgress.forEach(function (p) {
      const row = qs('#sources-table tr[data-slug="' + p.slug + '"]');
      if (!row) return;
      const statusEl = qs('.js-pass-status', row);
      const labelEl = qs('.js-pass-label', row);
      const statsEl = qs('.js-pass-stats', row);
      const offersEl = qs('.js-offers-found', row);
      if (statusEl) statusEl.textContent = p.status_fa || p.status || '';
      if (labelEl) labelEl.textContent = p.progress_label || '—';
      if (statsEl) {
        var when = p.last_batch_at_fa || '—';
        statsEl.textContent = 'یکتای گذر: ' + (p.unique_offers_pass || p.offers_found || 0)
          + ' · صفحات: ' + (p.pages_fetched || 0)
          + ' · آخرین دسته: ' + (p.last_batch_offers || 0)
          + (when && when !== '—' ? ' · ' + when : '');
      }
      var covEl = qs('.js-pass-coverage', row);
      if (covEl) {
        var covText = 'پوشش: هنوز مشخص نشده';
        if (p.inventory_path) {
          covText = 'مسیر پوشش: موجودی لیست API (نه نمونه صفحه اصلی)'
            + (p.source_total_elements ? (' · کل منبع: ' + p.source_total_elements) : '');
        } else if (p.coverage_scope === 'homepage_or_html_sample') {
          covText = 'مسیر پوشش: نمونه صفحه اصلی (موجودی کامل نیست)';
        } else if (p.coverage_scope) {
          covText = 'مسیر پوشش: ' + p.coverage_scope;
        }
        if (p.stop_reason) covText += ' · توقف: ' + p.stop_reason;
        covEl.textContent = covText;
      }
      var contactEl = qs('.js-pass-contact', row);
      if (contactEl) contactEl.textContent = 'آگهی دارای تماس (کل سابقه): ' + (p.contact_offers || 0) + ' · شماره یکتای این گذر: ' + (p.distinct_sims_pass || 0);
      var retryEl = qs('.js-pass-retry', row);
      if (retryEl) retryEl.textContent = 'تلاش بعدی: ' + (p.next_retry_at_fa || '—');
      var dbEl = qs('.js-pass-db', row);
      if (dbEl) dbEl.textContent = 'DB تاریخی یکتا: ' + (p.db_unique_offers || 0);
      if (offersEl) {
        offersEl.innerHTML = String(p.unique_offers_pass || p.offers_found || 0)
          + '<div class="muted js-db-unique">DB: ' + (p.db_unique_offers || 0) + '</div>';
      }
      const err = qs('.js-err', row);
      if (err && p.last_error) {
        err.className = 'status-fail js-err';
        err.textContent = p.last_error;
      }
    });
  }

  function formatBatchMessage(data) {
    const r = data.result || data;
    const p = (r && r.progress) || {};
    const parts = [];
    if (r && r.slug) parts.push('منبع: ' + r.slug);
    if (p.status_fa) parts.push('وضعیت: ' + p.status_fa);
    if (p.progress_label) parts.push(p.progress_label);
    if (typeof p.offers_found !== 'undefined') parts.push('آگهی‌های گذر: ' + p.offers_found);
    if (typeof p.last_batch_offers !== 'undefined') parts.push('آخرین دسته: ' + p.last_batch_offers);
    if (r && r.message) parts.push(r.message);
    return parts.join('\n');
  }

  async function refreshOneUntilDone(slug, csrf, onTick) {
    let first = true;
    while (true) {
      const params = { _csrf: csrf, slug: slug, mode: 'one' };
      if (first) params.restart = '1';
      else params.continue = '1';
      first = false;
      const data = await postRefresh(params);
      if (onTick) onTick(data);
      if (data.done || (data.result && (data.result.pass_status === 'completed' || data.result.pass_status === 'blocked'))) {
        return data;
      }
      if (data.result && data.result.busy) {
        await new Promise(function (r) { setTimeout(r, 800); });
        continue;
      }
      // Yield so UI can paint between batches
      await new Promise(function (r) { setTimeout(r, 50); });
    }
  }

  async function refreshAllBatches(csrf, shouldStop, onTick) {
    let started = false;
    while (!shouldStop()) {
      const params = { _csrf: csrf, mode: 'all' };
      if (!started) {
        params.start = '1';
        started = true;
      }
      const data = await postRefresh(params);
      if (onTick) onTick(data);

      // Local worker: enqueue once, then poll status until job completes
      if (data.worker) {
        while (!shouldStop()) {
          const st = await postRefresh({ _csrf: csrf, mode: 'status' });
          if (onTick) onTick(st);
          const job = st.job || data.job || {};
          const js = job.status || '';
          if (js === 'completed' || js === 'failed' || js === 'cancelled') {
            return { done: true, worker: true, job: job, message: job.message || 'کارگر تمام کرد.', all_progress: st.all_progress };
          }
          // Attach: if job still queued/running, keep polling
          await new Promise(function (r) { setTimeout(r, 400); });
        }
        return { done: false, paused: true, message: 'متوقف شد؛ کارگر از نقطهٔ ذخیره‌شده ادامه می‌دهد.' };
      }

      if (data.done) return data;
      await new Promise(function (r) { setTimeout(r, 40); });
    }
    return { done: false, paused: true, message: 'متوقف شد؛ موقعیت در پایگاه ذخیره است.' };
  }

  function bindSourcesPage() {
    const status = qs('#refresh-status');
    const detail = qs('#refresh-detail');
    const bar = qs('#refresh-progress');
    const fill = bar ? bar.querySelector('span') : null;
    const csrf = window.RONDINO_CSRF || '';
    let stopAll = false;

    qsa('.btn-refresh-one').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        const slug = btn.getAttribute('data-slug');
        btn.disabled = true;
        stopAll = true;
        if (status) status.textContent = 'در حال به‌روزرسانی ' + slug + '…';
        if (bar) bar.hidden = false;
        try {
          await refreshOneUntilDone(slug, btn.getAttribute('data-csrf') || csrf, function (data) {
            const r = data.result || {};
            const p = r.progress || {};
            if (detail) detail.textContent = formatBatchMessage(data);
            if (status) status.textContent = (p.status_fa || '') + ' — ' + (p.progress_label || r.message || '');
            if (fill && p.seed_total) {
              const pct = Math.min(100, Math.round(((p.seed_index || 0) / p.seed_total) * 100));
              fill.style.width = pct + '%';
            }
            applyProgressRows(r.progress ? [r.progress] : []);
          });
          if (status) status.textContent = 'به‌روزرسانی ' + slug + ' تمام شد.';
          location.reload();
        } catch (e) {
          if (status) status.textContent = 'خطا در ارتباط با سرور';
          btn.disabled = false;
        }
      });
    });

    const allBtn = qs('#btn-refresh-all');
    const pauseBtn = qs('#btn-pause-all');
    if (allBtn) {
      allBtn.addEventListener('click', async function () {
        allBtn.disabled = true;
        stopAll = false;
        if (pauseBtn) pauseBtn.hidden = false;
        if (bar) bar.hidden = false;
        if (status) status.textContent = 'شروع دسته‌های چرخشی…';
        try {
          const final = await refreshAllBatches(allBtn.getAttribute('data-csrf') || csrf, function () { return stopAll; }, function (data) {
            if (detail) detail.textContent = formatBatchMessage(data);
            applyProgressRows(data.all_progress || []);
            const list = data.all_progress || [];
            const doneCount = list.filter(function (p) {
              return p.status === 'completed' || p.status === 'blocked';
            }).length;
            const total = list.length || 1;
            if (fill) fill.style.width = Math.round((doneCount / total) * 100) + '%';
            if (status) {
              const slug = data.slug || (data.result && data.result.slug) || '';
              const st = (data.progress && data.progress.status_fa) || (data.result && data.result.progress && data.result.progress.status_fa) || '';
              status.textContent = 'چرخش منابع' + (slug ? ' · ' + slug : '') + (st ? ' · ' + st : '')
                + ' · کامل‌شده ' + doneCount + '/' + total;
            }
          });
          if (final.paused) {
            if (status) status.textContent = final.message || 'متوقف شد.';
            allBtn.disabled = false;
            if (pauseBtn) pauseBtn.hidden = true;
            return;
          }
          if (status) status.textContent = final.message || 'به‌روزرسانی همه منابع تمام شد.';
          location.reload();
        } catch (e) {
          if (status) status.textContent = 'خطا در به‌روزرسانی همه';
          allBtn.disabled = false;
          if (pauseBtn) pauseBtn.hidden = true;
        }
      });
    }
    if (pauseBtn) {
      pauseBtn.addEventListener('click', function () {
        stopAll = true;
        pauseBtn.hidden = true;
        if (status) status.textContent = 'در حال توقف… موقعیت ذخیره می‌شود.';
      });
    }

    // Auto-resume incomplete passes while the dashboard is open
    const minutes = Number(window.RONDINO_AUTO_REFRESH_MINUTES || 0);
    let autoBusy = false;
    async function autoTick() {
      if (autoBusy) return;
      if (allBtn && allBtn.disabled) return;
      autoBusy = true;
      try {
        const data = await postRefresh({ _csrf: csrf, mode: 'all' });
        applyProgressRows(data.all_progress || []);
        if (detail && data.message) detail.textContent = formatBatchMessage(data);
        if (!data.done) {
          // Keep draining incomplete passes with short delays
          setTimeout(autoTick, 200);
          return;
        }
      } catch (e) { /* keep page usable */ }
      finally { autoBusy = false; }
    }
    // On load: resume any paused/running pass without restarting completed ones
    setTimeout(autoTick, 600);
    if (minutes > 0) {
      setInterval(function () {
        if (!autoBusy) autoTick();
      }, Math.max(60, minutes) * 60 * 1000);
    }
  }

  if (qs('#sources-table')) bindSourcesPage();

  function bindMarketPage() {
    const moreBtn = qs('#btn-more-filters');
    const morePanel = qs('#more-filters');
    if (moreBtn && morePanel) {
      moreBtn.addEventListener('click', function () {
        const open = morePanel.classList.toggle('open');
        moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    qsa('.js-toggle-detail').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const id = btn.getAttribute('data-target');
        const el = id ? document.getElementById(id) : null;
        if (!el) return;
        const willShow = el.hasAttribute('hidden');
        if (willShow) el.removeAttribute('hidden');
        else el.setAttribute('hidden', '');
        btn.setAttribute('aria-expanded', willShow ? 'true' : 'false');
        btn.textContent = willShow ? 'بستن پیشنهادها' : 'مشاهده پیشنهادها';
      });
    });
  }

  if (qs('#market-filter-form')) bindMarketPage();

  function bindShellNav() {
    var toggle = qs('#nav-toggle');
    var sidebar = qs('#sidebar');
    var backdrop = qs('#sidebar-backdrop');
    if (!toggle || !sidebar) return;
    function close() {
      sidebar.classList.remove('open');
      if (backdrop) backdrop.hidden = true;
      document.body.classList.remove('nav-open');
    }
    function open() {
      sidebar.classList.add('open');
      if (backdrop) backdrop.hidden = false;
      document.body.classList.add('nav-open');
    }
    toggle.addEventListener('click', function () {
      if (sidebar.classList.contains('open')) close();
      else open();
    });
    if (backdrop) backdrop.addEventListener('click', close);
  }
  bindShellNav();
})();

