(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  async function refreshOne(slug, csrf) {
    const body = new URLSearchParams();
    body.set('_csrf', csrf);
    body.set('slug', slug);
    body.set('mode', 'one');
    const res = await fetch('/?r=refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    });
    return res.json();
  }

  async function refreshAll(csrf, onProgress) {
    let index = 0;
    let total = 1;
    while (true) {
      const body = new URLSearchParams();
      body.set('_csrf', csrf);
      body.set('mode', 'all');
      body.set('index', String(index));
      const res = await fetch('/?r=refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString(),
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (data.done) {
        onProgress(total, total, null);
        break;
      }
      total = data.total || total;
      onProgress(data.next || (index + 1), total, data);
      index = data.next;
      if (index >= total) break;
    }
  }

  function bindSourcesPage() {
    const status = qs('#refresh-status');
    const bar = qs('#refresh-progress');
    const fill = bar ? bar.querySelector('span') : null;
    const csrf = window.RONDINO_CSRF || '';

    qsa('.btn-refresh-one').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        const slug = btn.getAttribute('data-slug');
        btn.disabled = true;
        if (status) status.textContent = 'در حال به‌روزرسانی ' + slug + '…';
        try {
          const data = await refreshOne(slug, btn.getAttribute('data-csrf') || csrf);
          if (status) status.textContent = (data.result && data.result.message) || 'انجام شد';
          location.reload();
        } catch (e) {
          if (status) status.textContent = 'خطا در ارتباط با سرور';
          btn.disabled = false;
        }
      });
    });

    const allBtn = qs('#btn-refresh-all');
    if (allBtn) {
      allBtn.addEventListener('click', async function () {
        allBtn.disabled = true;
        if (bar) bar.hidden = false;
        try {
          await refreshAll(allBtn.getAttribute('data-csrf') || csrf, function (cur, total, data) {
            if (fill) fill.style.width = Math.round((cur / total) * 100) + '%';
            if (status) {
              const msg = data && data.result ? data.result.message : '';
              status.textContent = 'پیشرفت ' + cur + ' از ' + total + (msg ? ' — ' + msg : '');
            }
          });
          if (status) status.textContent = 'به‌روزرسانی همه منابع تمام شد.';
          location.reload();
        } catch (e) {
          if (status) status.textContent = 'خطا در به‌روزرسانی همه';
          allBtn.disabled = false;
        }
      });
    }

    // Optional auto-refresh of due sources while page is open
    const minutes = Number(window.RONDINO_AUTO_REFRESH_MINUTES || 0);
    if (minutes > 0) {
      setInterval(async function () {
        const rows = qsa('#sources-table tr[data-slug]');
        const now = Date.now();
        for (const row of rows) {
          const slug = row.getAttribute('data-slug');
          const intervalMin = Number(row.getAttribute('data-interval') || minutes);
          const success = row.getAttribute('data-success') || '';
          let due = true;
          if (success) {
            const ts = Date.parse(success + 'Z');
            if (!isNaN(ts) && (now - ts) < intervalMin * 60 * 1000) due = false;
          }
          if (!due) continue;
          try {
            await refreshOne(slug, csrf);
          } catch (e) { /* keep page usable */ }
        }
      }, Math.max(60, minutes) * 1000);
    }
  }

  if (qs('#sources-table')) bindSourcesPage();
})();
