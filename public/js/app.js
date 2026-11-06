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

  function formatCount(n) {
    try {
      return Number(n || 0).toLocaleString('en-US');
    } catch (e) {
      return String(n || 0);
    }
  }

  function setMetricValue(row, cls, text) {
    var el = qs('.' + cls + ' .source-metric-value', row);
    if (el) el.textContent = String(text);
  }

  function setStatusPill(row, p) {
    var statusEl = qs('.js-pass-status', row);
    if (!statusEl) return;
    var label = p.user_status_label || p.status_fa || '';
    var userStatus = p.user_status || '';
    statusEl.textContent = label;
    statusEl.className = 'source-status js-pass-status'
      + (userStatus ? ' source-status-' + userStatus : '');
    row.setAttribute('data-user-status', userStatus);
  }

  function updateActivityPanel(allProgress) {
    var panel = qs('#sources-activity');
    var body = qs('#sources-activity-body');
    if (!panel || !body) return;
    var receiving = (allProgress || []).filter(function (p) {
      return p.user_status === 'receiving' || p.status === 'running';
    });
    if (!receiving.length) {
      panel.hidden = true;
      panel.classList.add('is-empty');
      return;
    }
    panel.hidden = false;
    panel.classList.remove('is-empty');
    var act = receiving[0];
    var pct = (typeof act.progress_pct === 'number') ? act.progress_pct : null;
    var barClass = 'sources-progress' + (pct === null ? ' is-indeterminate' : '');
    var barStyle = pct !== null ? ('width:' + pct + '%') : '';
    body.innerHTML =
      '<div class="sources-activity-row">'
      + '<strong class="js-activity-name"></strong>'
      + '<span class="sources-mode-chip js-activity-mode" hidden></span>'
      + '<span class="muted js-activity-summary"></span>'
      + '</div>'
      + '<div class="' + barClass + '" id="sources-activity-bar" role="progressbar">'
      + '<span style="' + barStyle + '"></span></div>'
      + '<div class="muted sources-activity-meta">'
      + '<span class="js-activity-line"></span>'
      + '<span class="js-activity-db"></span>'
      + '<span class="js-activity-updated"></span>'
      + '</div>';
    var nameEl = qs('.js-activity-name', body);
    var modeEl = qs('.js-activity-mode', body);
    var sumEl = qs('.js-activity-summary', body);
    var lineEl = qs('.js-activity-line', body);
    var dbEl = qs('.js-activity-db', body);
    var updEl = qs('.js-activity-updated', body);
    if (nameEl) nameEl.textContent = act.name || act.slug || '';
    if (modeEl) {
      if (act.crawl_mode_fa) {
        modeEl.hidden = false;
        modeEl.textContent = act.crawl_mode_fa;
      } else {
        modeEl.hidden = true;
        modeEl.textContent = '';
      }
    }
    if (sumEl) sumEl.textContent = act.user_summary || act.user_status_label || 'در حال دریافت اطلاعات';
    if (lineEl) lineEl.textContent = act.user_activity || 'دریافت اطلاعات در جریان است.';
    if (dbEl) dbEl.textContent = 'کل ذخیره‌شده: ' + formatCount(act.db_stored_offers || 0);
    if (updEl) updEl.textContent = 'آخرین تأیید: ' + (act.updated_at_fa || '—');
  }

  function applyProgressRows(allProgress) {
    if (!allProgress || !allProgress.length) return;
    allProgress.forEach(function (p) {
      const row = qs('#sources-table [data-slug="' + p.slug + '"]')
        || qs('#sources-table tr[data-slug="' + p.slug + '"]');
      if (!row) return;

      setStatusPill(row, p);

      var summaryEl = qs('.js-user-summary', row);
      if (summaryEl && p.user_summary) summaryEl.textContent = p.user_summary;
      var nextEl = qs('.js-user-next', row);
      if (nextEl) {
        if (p.user_next) {
          nextEl.hidden = false;
          nextEl.textContent = p.user_next;
        } else {
          nextEl.hidden = true;
          nextEl.textContent = '';
        }
      }

      var passOffers = p.unique_offers_pass || p.offers_found || 0;
      var dbSims = p.db_unique_sims != null ? p.db_unique_sims : (p.db_unique_offers || 0);
      var dbOffers = p.db_stored_offers != null ? p.db_stored_offers : 0;
      var withContact = p.db_offers_with_contact != null ? p.db_offers_with_contact : (p.contact_offers || 0);
      var phones = p.db_phone_records != null ? p.db_phone_records : 0;
      var inserted = p.inserted_pass || 0;

      setMetricValue(row, 'js-metric-pass-offers', formatCount(passOffers));
      setMetricValue(row, 'js-metric-pass-sims', formatCount(p.distinct_sims_pass || 0));
      setMetricValue(row, 'js-metric-inserted', formatCount(inserted));
      setMetricValue(row, 'js-metric-updated', formatCount(p.updated_pass || 0));
      setMetricValue(row, 'js-metric-pages', formatCount(p.pages_fetched || 0));
      setMetricValue(row, 'js-metric-batch', formatCount(p.last_batch_offers || 0));
      setMetricValue(row, 'js-metric-db-offers', formatCount(dbOffers));
      setMetricValue(row, 'js-metric-db-sims', formatCount(dbSims));
      setMetricValue(row, 'js-metric-db-with-contact', formatCount(withContact));
      setMetricValue(row, 'js-metric-db-phones', formatCount(phones));

      var insertedBlock = qs('.js-metric-inserted', row);
      if (insertedBlock) {
        insertedBlock.hidden = !(typeof p.inserted_pass !== 'undefined');
      }

      if (p.last_success_at_fa) {
        var ls = qs('.js-last-success', row);
        if (ls) ls.textContent = p.last_success_at_fa;
      }

      var batchTimeEl = qs('.js-metric-batch-time', row);
      if (batchTimeEl) {
        batchTimeEl.textContent = 'زمان آخرین دسته: ' + (p.last_batch_at_fa || '—');
      }

      var covEl = qs('.js-pass-coverage', row);
      if (covEl) {
        var covText = 'مسیر پوشش: هنوز مشخص نشده';
        if (p.inventory_path) {
          covText = 'مسیر پوشش: فهرست کامل سایت'
            + (p.source_total_elements ? (' · اعلام سایت: ' + formatCount(p.source_total_elements)) : '');
        } else if (p.coverage_scope === 'homepage_or_html_sample') {
          covText = 'مسیر پوشش: نمونه صفحه اصلی';
        } else if (p.coverage_scope) {
          covText = 'مسیر پوشش: ' + p.coverage_scope;
        }
        covEl.textContent = covText;
      }
      var retryEl = qs('.js-pass-retry', row);
      if (retryEl) retryEl.textContent = 'تلاش بعدی: ' + (p.next_retry_at_fa || '—');

      var techMode = qs('.js-tech-mode', row);
      if (techMode) techMode.textContent = p.crawl_mode || '—';
      var techStatus = qs('.js-tech-status', row);
      if (techStatus) techStatus.textContent = p.status || '—';
      var techStop = qs('.js-tech-stop', row);
      if (techStop) techStop.textContent = p.stop_reason_raw || p.stop_reason || '—';
      var labelEl = qs('.js-pass-label', row);
      if (labelEl) labelEl.textContent = 'برچسب پیشرفت: ' + (p.progress_label || '—');

      var offersEl = qs('.js-offers-found', row);
      if (offersEl) offersEl.textContent = String(passOffers);

      var busy = p.user_status === 'receiving' || p.status === 'running';
      qsa('.btn-crawl-quick, .btn-crawl-full', row).forEach(function (btn) {
        btn.disabled = !!busy;
      });

      const err = qs('.js-err', row);
      const errBlock = qs('.js-err-block', row);
      if (err && p.last_error) {
        err.className = 'status-fail js-err';
        err.textContent = p.last_error;
        if (errBlock) errBlock.hidden = false;
      }
    });
    updateActivityPanel(allProgress);
  }

  function formatBatchMessage(data) {
    const r = data.result || data;
    const p = (r && r.progress) || {};
    const parts = [];
    if (p.name || (r && r.slug)) parts.push('سایت: ' + (p.name || r.slug));
    if (p.crawl_mode_fa) parts.push('حالت: ' + p.crawl_mode_fa);
    if (p.user_status_label || p.status_fa) parts.push(p.user_status_label || p.status_fa);
    if (p.user_summary) parts.push(p.user_summary);
    else if (p.user_activity) parts.push(p.user_activity);
    if (typeof p.unique_offers_pass !== 'undefined' || typeof p.offers_found !== 'undefined') {
      parts.push('آگهی‌های این بررسی: ' + formatCount(p.unique_offers_pass || p.offers_found || 0));
    }
    if (p.inserted_pass) parts.push('آگهی‌های جدید: ' + formatCount(p.inserted_pass));
    if (typeof p.db_stored_offers !== 'undefined') {
      parts.push('کل ذخیره‌شده: ' + formatCount(p.db_stored_offers));
    }
    if (r && r.message && !/یکتا|خزش|گذر|API|MySQL|کارگر|SSR|DB\b/i.test(String(r.message))) {
      parts.push(r.message);
    }
    return parts.join('\n');
  }

  function isTechnicalStatusMessage(text) {
    return /یکتا|خزش|گذر|API|MySQL|کارگر|SSR|\bDB\b/i.test(String(text || ''));
  }

  async function refreshOneUntilDone(slug, csrf, crawlMode, onTick) {
    let first = true;
    const mode = crawlMode === 'full' ? 'full' : 'incremental';
    while (true) {
      const params = { _csrf: csrf, slug: slug, mode: 'one', crawl_mode: mode };
      if (first) params.restart = '1';
      else params.continue = '1';
      first = false;
      const data = await postRefresh(params);
      if (onTick) onTick(data);
      var passStatus = data.result && data.result.pass_status;
      if (
        data.done
        || passStatus === 'completed'
        || passStatus === 'blocked'
        || passStatus === 'partial'
      ) {
        return data;
      }
      if (data.result && data.result.busy) {
        await new Promise(function (r) { setTimeout(r, 800); });
        continue;
      }
      await new Promise(function (r) { setTimeout(r, 50); });
    }
  }

  function confirmFullCrawl(siteName) {
    var dlg = qs('#full-crawl-confirm');
    if (!dlg || typeof dlg.showModal !== 'function') {
      return Promise.resolve(window.confirm(
        'بررسی کامل «' + siteName + '» شروع شود؟\nاطلاعات قبلی حذف نمی‌شوند، اما ممکن است زمان‌بر باشد.'
      ));
    }
    var desc = qs('#full-crawl-desc', dlg);
    if (desc) {
      desc.textContent = 'تمام آگهی‌های «'
        + siteName
        + '» دوباره بررسی می‌شوند. اطلاعات قبلی حذف نمی‌شوند، اما این کار ممکن است زمان‌بر باشد.';
    }
    return new Promise(function (resolve) {
      function onClose() {
        dlg.removeEventListener('close', onClose);
        resolve(dlg.returnValue === 'confirm');
      }
      dlg.addEventListener('close', onClose);
      dlg.showModal();
    });
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

    qsa('.js-toggle-error').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-target');
        var block = id ? document.getElementById(id) : null;
        if (!block) return;
        block.hidden = !block.hidden;
      });
    });

    async function runSourceCrawl(btn, crawlMode) {
      const slug = btn.getAttribute('data-slug');
      const card = btn.closest('[data-slug]');
      const siteName = card && qs('.source-name', card)
        ? qs('.source-name', card).textContent.trim()
        : slug;
      const mode = crawlMode === 'full' ? 'full' : 'incremental';
      const modeFa = mode === 'full' ? 'بررسی کامل سایت' : 'به‌روزرسانی سریع';

      if (mode === 'full') {
        var ok = await confirmFullCrawl(siteName);
        if (!ok) return;
      }

      qsa('.btn-crawl-quick, .btn-crawl-full').forEach(function (b) { b.disabled = true; });
      stopAll = true;
      if (status) status.textContent = modeFa + ' برای «' + siteName + '»…';
      if (bar) {
        bar.hidden = false;
        bar.classList.add('is-indeterminate');
      }
      if (detail) {
        detail.hidden = false;
        detail.textContent = '';
      }
      try {
        await refreshOneUntilDone(slug, btn.getAttribute('data-csrf') || csrf, mode, function (data) {
          const r = data.result || {};
          const p = r.progress || {};
          if (detail) {
            detail.hidden = false;
            detail.textContent = formatBatchMessage(data);
          }
          if (status) {
            status.textContent = modeFa
              + ' · '
              + (p.user_status_label || p.status_fa || 'در حال دریافت اطلاعات')
              + (p.user_activity ? ' — ' + p.user_activity : '');
          }
          if (fill) {
            if (typeof p.progress_pct === 'number') {
              bar.classList.remove('is-indeterminate');
              fill.style.width = Math.min(100, Math.max(0, p.progress_pct)) + '%';
            } else {
              bar.classList.add('is-indeterminate');
            }
          }
          applyProgressRows(r.progress ? [r.progress] : []);
        });
        if (status) status.textContent = modeFa + ' برای «' + siteName + '» تمام شد.';
        location.reload();
      } catch (e) {
        if (status) status.textContent = 'ارتباط با سرور برقرار نشد.';
        qsa('.btn-crawl-quick, .btn-crawl-full').forEach(function (b) { b.disabled = false; });
      }
    }

    qsa('.btn-crawl-quick').forEach(function (btn) {
      btn.addEventListener('click', function () { runSourceCrawl(btn, 'incremental'); });
    });
    qsa('.btn-crawl-full').forEach(function (btn) {
      btn.addEventListener('click', function () { runSourceCrawl(btn, 'full'); });
    });

    const allBtn = qs('#btn-refresh-all');
    const pauseBtn = qs('#btn-pause-all');
    if (allBtn) {
      allBtn.addEventListener('click', async function () {
        if (!window.confirm('به‌روزرسانی سریع همه سایت‌های فعال شروع شود؟ آگهی‌های تازه بررسی می‌شوند و اطلاعات قبلی حذف نمی‌گردد.')) {
          return;
        }
        allBtn.disabled = true;
        stopAll = false;
        qsa('.btn-crawl-quick, .btn-crawl-full').forEach(function (b) { b.disabled = true; });
        if (pauseBtn) pauseBtn.hidden = false;
        if (bar) {
          bar.hidden = false;
          bar.classList.add('is-indeterminate');
        }
        if (detail) detail.hidden = false;
        if (status) status.textContent = 'به‌روزرسانی سریع همه سایت‌ها…';
        try {
          const final = await refreshAllBatches(allBtn.getAttribute('data-csrf') || csrf, function () { return stopAll; }, function (data) {
            if (detail) {
              detail.hidden = false;
              detail.textContent = formatBatchMessage(data);
            }
            applyProgressRows(data.all_progress || []);
            const list = data.all_progress || [];
            const doneCount = list.filter(function (p) {
              return p.status === 'completed' || p.status === 'blocked' || p.status === 'partial';
            }).length;
            const total = list.length || 1;
            if (fill) {
              bar.classList.remove('is-indeterminate');
              fill.style.width = Math.round((doneCount / total) * 100) + '%';
            }
            if (status) {
              const name = (data.progress && data.progress.name)
                || (data.result && data.result.progress && data.result.progress.name)
                || data.slug
                || (data.result && data.result.slug)
                || '';
              const st = (data.progress && (data.progress.user_status_label || data.progress.status_fa))
                || (data.result && data.result.progress && (data.result.progress.user_status_label || data.result.progress.status_fa))
                || '';
              status.textContent = 'به‌روزرسانی سریع'
                + (name ? ' · ' + name : '')
                + (st ? ' · ' + st : '')
                + ' · ' + doneCount + ' از ' + total;
            }
          });
          if (final.paused) {
            if (status) status.textContent = 'دریافت اطلاعات متوقف شد. از همین‌جا قابل ادامه است.';
            allBtn.disabled = false;
            qsa('.btn-crawl-quick, .btn-crawl-full').forEach(function (b) { b.disabled = false; });
            if (pauseBtn) pauseBtn.hidden = true;
            return;
          }
          if (status) status.textContent = 'به‌روزرسانی همه سایت‌ها تمام شد.';
          location.reload();
        } catch (e) {
          if (status) status.textContent = 'به‌روزرسانی سریع همه سایت‌ها با مشکل روبه‌رو شد.';
          allBtn.disabled = false;
          qsa('.btn-crawl-quick, .btn-crawl-full').forEach(function (b) { b.disabled = false; });
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
        // Auto-resume stays quiet unless a site is actively receiving.
        var receiving = (data.all_progress || []).some(function (p) {
          return p.user_status === 'receiving' || p.status === 'running';
        });
        if (detail) {
          if (receiving) {
            detail.hidden = false;
            detail.textContent = formatBatchMessage(data);
          } else {
            detail.hidden = true;
            detail.textContent = '';
          }
        }
        if (!data.done) {
          setTimeout(autoTick, 200);
          return;
        }
      } catch (e) { /* keep page usable */ }
      finally { autoBusy = false; }
    }
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

