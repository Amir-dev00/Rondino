/**
 * Persistent Playwright session for rond.ir contact detail pages.
 * Warms up once, then fetches many /Sims/0912… pages sequentially.
 *
 * Usage:
 *   node scripts/browser-contact-session.mjs --urls-file urls.txt --out results.json
 *   node scripts/browser-contact-session.mjs --bench-launch   # local launch timing only
 *
 * Env: RONDINO_BROWSER_PROXY, RONDINO_BROWSER_DEADLINE_MS,
 *      RONDINO_CONTACT_DELAY_MS (default 800), RONDINO_CONTACT_RECYCLE_EVERY (default 40)
 *
 * Does not write to MySQL. Safe to run for offline micro-benches; avoid running against
 * the live site while another contact worker already holds the proxy.
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { looksLikeChallenge, normalizeProxyServer } from './browser-fetch-lib.mjs';

function arg(name, fallback = null) {
  const i = process.argv.indexOf(name);
  if (i === -1) return fallback;
  return process.argv[i + 1] ?? fallback;
}
function has(name) {
  return process.argv.includes(name);
}

const DELAY_MS = Math.max(200, Number(process.env.RONDINO_CONTACT_DELAY_MS || 800));
const RECYCLE_EVERY = Math.max(5, Number(process.env.RONDINO_CONTACT_RECYCLE_EVERY || 40));
const OVERALL_MS = Number(process.env.RONDINO_BROWSER_DEADLINE_MS || 600000);

function resolveProxy() {
  for (const k of ['RONDINO_BROWSER_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY']) {
    const v = process.env[k];
    if (v && String(v).trim()) return normalizeProxyServer(v);
  }
  return null;
}

async function safeContent(page) {
  for (let i = 0; i < 10; i++) {
    try {
      return await page.content();
    } catch {
      await page.waitForTimeout(400);
    }
  }
  return '';
}

async function warmUp(page) {
  const t0 = Date.now();
  let lastErr = null;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 45000 });
      lastErr = null;
      break;
    } catch (err) {
      lastErr = err;
      await page.waitForTimeout(1000 * attempt);
    }
  }
  if (lastErr) throw lastErr;
  const deadline = Date.now() + 50000;
  while (Date.now() < deadline) {
    const html = await safeContent(page);
    if (html && !looksLikeChallenge(html) && /\/Sims\/0912\d{7}/.test(html)) {
      return Date.now() - t0;
    }
    await page.waitForTimeout(800);
  }
  throw new Error('warm-up did not reach usable homepage');
}

async function fetchDetail(page, url) {
  const t0 = Date.now();
  const target = new URL(url);
  if (target.origin !== 'https://rond.ir' || !/^\/Sims\/0912\d{7}$/.test(target.pathname)) {
    throw new Error('Invalid detail URL');
  }
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (!response || !response.ok()) throw new Error('Detail HTTP ' + response?.status());
  try {
    await page.waitForFunction(
      () => {
        const body = document.body?.innerText || '';
        if (!body.includes('اطلاعات تماس فروشنده')) return false;
        const tel = document.querySelector('a[href^="tel:"]');
        if (tel && /tel:0\d{10}/i.test(tel.getAttribute('href') || '')) return true;
        return /اطلاعات\s*تماس\s*فروشنده\s*0\d[\d\s-]{8,18}\d/.test(body);
      },
      { timeout: 15000 }
    );
  } catch {
    await page.waitForTimeout(2000);
  }
  const html = await safeContent(page);
  if (looksLikeChallenge(html)) throw new Error('Detail challenge observed');
  return { html, elapsed_ms: Date.now() - t0 };
}

async function benchLaunchOnly() {
  const samples = [];
  for (let i = 0; i < 3; i++) {
    const t0 = Date.now();
    const browser = await chromium.launch({ headless: true });
    const launchMs = Date.now() - t0;
    const t1 = Date.now();
    const context = await browser.newContext({ locale: 'fa-IR' });
    await context.newPage();
    const contextMs = Date.now() - t1;
    await browser.close();
    samples.push({ launch_ms: launchMs, context_ms: contextMs });
  }
  const out = {
    mode: 'bench-launch',
    samples,
    avg_launch_ms: Math.round(samples.reduce((a, s) => a + s.launch_ms, 0) / samples.length),
    avg_context_ms: Math.round(samples.reduce((a, s) => a + s.context_ms, 0) / samples.length),
  };
  console.log(JSON.stringify(out, null, 2));
  return out;
}

async function runSession(urls, outPath) {
  const proxy = resolveProxy();
  const started = Date.now();
  const results = [];
  const timings = { warmups_ms: [], details_ms: [], launches_ms: [] };

  let browser = null;
  let context = null;
  let page = null;
  let sinceRecycle = 0;

  async function openBrowser() {
    const t0 = Date.now();
    browser = await chromium.launch({ headless: true });
    const opts = {
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      locale: 'fa-IR',
      viewport: { width: 1400, height: 900 },
    };
    if (proxy) opts.proxy = { server: proxy };
    context = await browser.newContext(opts);
    page = await context.newPage();
    timings.launches_ms.push(Date.now() - t0);
    const warmMs = await warmUp(page);
    timings.warmups_ms.push(warmMs);
    sinceRecycle = 0;
  }

  async function closeBrowser() {
    try {
      await browser?.close();
    } catch {
      /* ignore */
    }
    browser = context = page = null;
  }

  try {
    await openBrowser();
    for (const url of urls) {
      if (Date.now() - started > OVERALL_MS) break;
      if (sinceRecycle >= RECYCLE_EVERY) {
        await closeBrowser();
        await openBrowser();
      }
      try {
        const { html, elapsed_ms } = await fetchDetail(page, url);
        timings.details_ms.push(elapsed_ms);
        results.push({ url, ok: true, elapsed_ms, html_bytes: html.length, html });
      } catch (err) {
        results.push({
          url,
          ok: false,
          elapsed_ms: null,
          error: String(err && err.message ? err.message : err),
          html: '',
        });
        // Soft recovery: recycle browser after a failure.
        await closeBrowser();
        try {
          await openBrowser();
        } catch (e2) {
          results.push({
            url: '__session__',
            ok: false,
            error: 'session_reopen_failed: ' + String(e2 && e2.message ? e2.message : e2),
            html: '',
          });
          break;
        }
      }
      sinceRecycle++;
      await page.waitForTimeout(DELAY_MS);
    }
  } finally {
    await closeBrowser();
  }

  // Strip HTML from summary copy for small report; full payloads go to out file.
  const summary = {
    mode: 'session',
    proxy: proxy ? String(proxy).replace(/\/\/([^/@]+)@/, '//***@') : null,
    count: results.length,
    ok: results.filter((r) => r.ok).length,
    failed: results.filter((r) => !r.ok).length,
    timings: {
      avg_launch_ms: avg(timings.launches_ms),
      avg_warmup_ms: avg(timings.warmups_ms),
      avg_detail_ms: avg(timings.details_ms),
      launches: timings.launches_ms.length,
      warmups: timings.warmups_ms.length,
    },
    elapsed_total_ms: Date.now() - started,
    per_item_avg_ms:
      results.length > 0 ? Math.round((Date.now() - started) / results.length) : null,
  };

  if (outPath) {
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify({ summary, results }, null, 2));
  }
  console.log(JSON.stringify(summary, null, 2));
  return summary;
}

function avg(xs) {
  if (!xs.length) return null;
  return Math.round(xs.reduce((a, b) => a + b, 0) / xs.length);
}

if (has('--bench-launch')) {
  await benchLaunchOnly();
  process.exit(0);
}

const urlsFile = arg('--urls-file');
const outPath = arg('--out', path.join('storage', 'logs', 'contact-session-bench.json'));
if (!urlsFile) {
  console.error('Provide --urls-file or --bench-launch');
  process.exit(2);
}
const urls = fs
  .readFileSync(urlsFile, 'utf8')
  .split(/\r?\n/)
  .map((s) => s.trim())
  .filter((s) => s && !s.startsWith('#'));
if (!urls.length) {
  console.error('No URLs in file');
  process.exit(2);
}
await runSession(urls, outPath);
