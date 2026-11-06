/**
 * Probe rond.ir listing/search flow with the same Playwright + proxy path as production helper.
 * Captures document/XHR/fetch URLs and pagination cues. Never invents endpoints.
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.resolve('storage/cache');
fs.mkdirSync(outDir, { recursive: true });

function resolveProxy() {
  for (const k of ['RONDINO_BROWSER_PROXY', 'RONDINO_BROWSER_FALLBACK_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY']) {
    const v = process.env[k];
    if (v && String(v).trim()) {
      let s = String(v).trim();
      if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) s = 'socks5://' + s;
      return s;
    }
  }
  return 'socks5://127.0.0.1:10808';
}

async function safeContent(page) {
  for (let i = 0; i < 8; i++) {
    try {
      return await page.content();
    } catch (e) {
      if (!/navigating|changing the content/i.test(String(e.message || e))) throw e;
      await page.waitForTimeout(800);
    }
  }
  return await page.content();
}

async function waitListings(page) {
  for (let i = 0; i < 40; i++) {
    await page.waitForTimeout(1200);
    try {
      const html = await safeContent(page);
      if (!/__arcsjs|Transferring to the website/i.test(html) && /\/Sims\/0912/.test(html)) {
        return true;
      }
    } catch {
      // keep waiting through redirects / SPA loads
    }
  }
  return false;
}

async function listingStats(page) {
  return page.evaluate(() => {
    const anchors = Array.from(document.querySelectorAll('a[href*="/Sims/0912"]'));
    const hrefs = [...new Set(anchors.map((a) => a.href))];
    const texts = Array.from(document.querySelectorAll('a,button,li,span,div'))
      .map((el) => (el.textContent || '').trim().replace(/\s+/g, ' '))
      .filter((t) => t && t.length < 50)
      .filter((t) => /صفحه|بعدی|قبلی|بیشتر|مشاهده|۰۹۱۲|0912|کد|همه|فروش|خرید|سیم|pager|page/i.test(t))
      .slice(0, 80);
    const pagers = Array.from(document.querySelectorAll('a,button,[role="button"],li'))
      .map((el) => ({
        tag: el.tagName,
        text: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40),
        href: el.href || el.getAttribute('href') || null,
        cls: el.className || '',
      }))
      .filter((x) => /صفحه|بعد|قبلی|prev|next|page|\d+|»|«|>|</i.test(x.text + ' ' + (x.href || '') + ' ' + x.cls))
      .slice(0, 40);
    const forms = Array.from(document.querySelectorAll('form')).map((f) => ({
      action: f.action || '',
      method: f.method || '',
      inputs: Array.from(f.querySelectorAll('input,select')).map((i) => ({
        name: i.name || '',
        type: i.type || i.tagName,
        value: (i.value || '').slice(0, 40),
      })).slice(0, 20),
    }));
    return {
      url: location.href,
      title: document.title,
      listing_anchors: anchors.length,
      unique_sims: hrefs.length,
      sample_sims: hrefs.slice(0, 8),
      ui_texts: texts,
      pagers,
      forms: forms.slice(0, 10),
      tables: Array.from(document.querySelectorAll('table')).map((t, i) => ({
        i,
        className: t.className,
        rows: t.querySelectorAll('tr').length,
        sims: t.querySelectorAll('a[href*="/Sims/"]').length,
      })),
    };
  });
}

const proxy = resolveProxy();
const network = [];
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  locale: 'fa-IR',
  userAgent:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
  viewport: { width: 1400, height: 900 },
  proxy: { server: proxy },
});
const page = await context.newPage();
page.on('response', async (res) => {
  const req = res.request();
  const type = req.resourceType();
  if (!['xhr', 'fetch', 'document'].includes(type)) return;
  const u = res.url();
  if (!/rond\.ir|api|sim|search|list|offer|product|graphql|page|prefix|code/i.test(u)) return;
  let preview = '';
  try {
    const ct = (res.headers()['content-type'] || '').toLowerCase();
    if (ct.includes('json') || ct.includes('text') || ct.includes('javascript') || ct.includes('html')) {
      preview = (await res.text()).slice(0, 500);
    }
  } catch {}
  network.push({
    status: res.status(),
    type,
    method: req.method(),
    url: u,
    preview,
  });
});

const report = { proxy, steps: [], errors: [] };

try {
  console.error('goto homepage with', proxy);
  await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 90000 });
  const readyHome = await waitListings(page);
  const homeHtml = await safeContent(page);
  fs.writeFileSync(path.join(outDir, 'rond-probe-home.html'), homeHtml, 'utf8');
  const homeStats = await listingStats(page);
  report.steps.push({ step: 'home', ready: readyHome, ...homeStats });

  const links = await page.evaluate(() => {
    const out = [];
    for (const a of document.querySelectorAll('a[href]')) {
      const href = a.href || '';
      const text = (a.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 80);
      if (!/rond\.ir/i.test(href)) continue;
      if (/0912|sim|buy|search|فروش|خرید|سیم|کد|لیست|فهرست|پیش\s*شماره|همراه/i.test(href + ' ' + text)) {
        out.push({ href, text });
      }
    }
    // de-dupe
    const seen = new Set();
    return out.filter((x) => (seen.has(x.href) ? false : (seen.add(x.href), true))).slice(0, 100);
  });
  report.candidate_links = links;

  // Prefer discovered links that look like catalog/search, then a few known public paths.
  const discovered = links
    .map((l) => l.href)
    .filter((h) => /buy|sim|search|0912|code|prefix|list/i.test(h) && !/\/Sims\/0912\d{7}/i.test(h));
  const tryUrls = [...new Set([
    ...discovered.slice(0, 12),
    'https://rond.ir/buy/simcard',
    'https://rond.ir/buy/simcard/?prefix=0912',
    'https://rond.ir/Sims',
    'https://rond.ir/search?q=0912',
  ])];

  for (const u of tryUrls) {
    try {
      console.error('try', u);
      const before = network.length;
      await page.goto(u, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await waitListings(page);
      const stats = await listingStats(page);
      const html = await safeContent(page);
      report.steps.push({
        step: 'url',
        requested: u,
        has_listings: stats.unique_sims > 0 && !/__arcsjs/i.test(html),
        new_network: network.slice(before).map((n) => ({ status: n.status, type: n.type, method: n.method, url: n.url })),
        ...stats,
      });
      const slug = Buffer.from(u).toString('base64url').slice(0, 28);
      fs.writeFileSync(path.join(outDir, 'rond-probe-' + slug + '.html'), html.slice(0, 250000), 'utf8');
    } catch (e) {
      report.steps.push({ step: 'url', requested: u, error: String(e.message || e).split('\n')[0] });
    }
  }

  // Return home and attempt UI-driven next/page if controls exist.
  await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 90000 });
  await waitListings(page);
  const beforeClick = await listingStats(page);
  const beforeNet = network.length;
  const clicked = await page.evaluate(() => {
    const candidates = Array.from(document.querySelectorAll('a,button,[role="button"],li'));
    const next = candidates.find((el) => {
      const t = (el.textContent || '').trim();
      return /^(بعدی|next|»|>)$/i.test(t) || /بعدی|صفحه\s*بعد/i.test(t);
    });
    if (!next) return null;
    next.click();
    return (next.textContent || '').trim().slice(0, 40);
  });
  if (clicked) {
    await page.waitForTimeout(5000);
    const after = await listingStats(page);
    report.steps.push({
      step: 'clicked_next',
      label: clicked,
      before_unique: beforeClick.unique_sims,
      after_unique: after.unique_sims,
      after_sample: after.sample_sims,
      url: after.url,
      new_network: network.slice(beforeNet).map((n) => ({ status: n.status, type: n.type, method: n.method, url: n.url })),
    });
  } else {
    report.steps.push({ step: 'clicked_next', label: null, before_unique: beforeClick.unique_sims });
  }

  // Try typing 0912 into visible search inputs if present.
  const searchTried = await page.evaluate(() => {
    const input = Array.from(document.querySelectorAll('input')).find((i) => {
      const ph = (i.placeholder || '') + ' ' + (i.name || '') + ' ' + (i.id || '') + ' ' + (i.getAttribute('aria-label') || '');
      return /جستجو|search|شماره|sim|0912|کد/i.test(ph) || i.type === 'search';
    });
    if (!input) return null;
    input.focus();
    input.value = '0912';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    const form = input.closest('form');
    if (form) {
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }
    return { name: input.name || '', id: input.id || '', placeholder: input.placeholder || '' };
  });
  if (searchTried) {
    await page.keyboard.press('Enter');
    await page.waitForTimeout(5000);
    const afterSearch = await listingStats(page);
    report.steps.push({ step: 'search_0912', input: searchTried, ...afterSearch });
  } else {
    report.steps.push({ step: 'search_0912', input: null });
  }
} catch (e) {
  report.errors.push(String(e.message || e));
  console.error(e);
} finally {
  await browser.close();
}

report.network = network.slice(0, 200);
fs.writeFileSync(path.join(outDir, 'rond-listing-flow-probe.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify({
  proxy: report.proxy,
  errors: report.errors,
  steps: report.steps.map((s) => ({
    step: s.step,
    requested: s.requested,
    url: s.url,
    ready: s.ready,
    unique_sims: s.unique_sims,
    listing_anchors: s.listing_anchors,
    has_listings: s.has_listings,
    label: s.label,
    before_unique: s.before_unique,
    after_unique: s.after_unique,
    error: s.error,
    sample_sims: s.sample_sims,
    new_network_count: s.new_network ? s.new_network.length : undefined,
  })),
  candidate_link_count: (report.candidate_links || []).length,
  network_interesting: network
    .filter((n) => /api|json|sim|search|list|graphql|page|prefix/i.test(n.url) && !/\.(css|js|png|jpg|woff)/i.test(n.url))
    .slice(0, 40)
    .map((n) => ({ status: n.status, type: n.type, method: n.method, url: n.url, preview: (n.preview || '').slice(0, 120) })),
}, null, 2));
