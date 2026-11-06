/**
 * Concurrent SSR contact fetch over one warmed Playwright session.
 *
 * Modes:
 *   node browser-ngstate-pool.mjs --urls-file f.txt --out o.json [--concurrency 4]
 *   node browser-ngstate-pool.mjs --stdio   # JSON lines: {"op":"fetch","id":1,"urls":[...]}
 *
 * Adaptive concurrency, Retry-After / 429 / challenge handling, circuit breaker.
 * Never launches more than one Chromium; never bypasses challenges.
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import readline from 'readline';
import { fileURLToPath } from 'url';
import { looksLikeChallenge, normalizeProxyServer } from './browser-fetch-lib.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(path.join(root, 'scripts'));

function arg(name, fallback = null) {
  const i = process.argv.indexOf(name);
  return i === -1 ? fallback : process.argv[i + 1] ?? fallback;
}
function hasFlag(name) {
  return process.argv.includes(name);
}

const proxy = normalizeProxyServer(process.env.RONDINO_BROWSER_PROXY || '');
const OVERALL_MS = Number(process.env.RONDINO_BROWSER_DEADLINE_MS || 0); // 0 = no limit in stdio mode
const RECYCLE_EVERY = Math.max(20, Number(process.env.RONDINO_CONTACT_RECYCLE_EVERY || 200));
const BASE_DELAY_MS = Math.max(0, Number(process.env.RONDINO_CONTACT_DELAY_MS || 40));
const MAX_CONCURRENCY = Math.max(1, Math.min(8, Number(arg('--concurrency', process.env.RONDINO_CONTACT_CONCURRENCY || '4'))));
const MIN_CONCURRENCY = 1;

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function jitter(ms) {
  const j = Math.floor(ms * 0.25 * Math.random());
  return ms + j;
}

function percentile(sorted, p) {
  if (!sorted.length) return null;
  const idx = Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1));
  return sorted[idx];
}

async function warm(page) {
  let lastErr = null;
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 90000 });
      for (let i = 0; i < 45; i++) {
        let h = '';
        try {
          h = await page.content();
        } catch {
          await page.waitForTimeout(700);
          continue;
        }
        if (h.includes('/Sims/0912') && !looksLikeChallenge(h)) return;
        await page.waitForTimeout(700);
      }
      lastErr = new Error('warm-up content not ready');
    } catch (e) {
      lastErr = e;
      await page.waitForTimeout(jitter(1500 * (attempt + 1)));
    }
  }
  throw lastErr || new Error('warm-up failed');
}

async function openSession() {
  let lastErr = null;
  for (let attempt = 0; attempt < 3; attempt++) {
    const browser = await chromium.launch({ headless: true });
    try {
      const context = await browser.newContext({
        locale: 'fa-IR',
        proxy: proxy ? { server: proxy } : undefined,
        userAgent:
          'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      });
      const page = await context.newPage();
      const t0 = Date.now();
      await warm(page);
      return { browser, context, page, warmMs: Date.now() - t0, fetchedSinceWarm: 0 };
    } catch (e) {
      lastErr = e;
      await browser.close().catch(() => {});
      await sleep(jitter(2000 * (attempt + 1)));
    }
  }
  throw lastErr || new Error('openSession failed');
}

/** Deduplicate URLs preserving first occurrence order. */
function uniqueUrls(urls) {
  const seen = new Set();
  const out = [];
  for (const u of urls) {
    const key = String(u || '').trim();
    if (!key || seen.has(key)) continue;
    seen.add(key);
    out.push(key);
  }
  return out;
}

class AdaptivePool {
  constructor(maxConcurrency) {
    this.maxConcurrency = maxConcurrency;
    this.concurrency = Math.min(2, maxConcurrency);
    this.successWindow = [];
    this.failWindow = [];
    this.circuitOpenUntil = 0;
    this.consecutiveHardFails = 0;
    this.delayMs = BASE_DELAY_MS;
  }

  record(ok, status, hard) {
    const now = Date.now();
    this.successWindow.push(ok ? 1 : 0);
    this.failWindow.push(ok ? 0 : 1);
    if (this.successWindow.length > 40) {
      this.successWindow.shift();
      this.failWindow.shift();
    }
    if (hard) {
      this.consecutiveHardFails++;
      this.concurrency = MIN_CONCURRENCY;
      this.delayMs = Math.min(5000, Math.max(this.delayMs * 2, 250));
      if (this.consecutiveHardFails >= 5) {
        this.circuitOpenUntil = now + jitter(30000);
      }
    } else if (ok) {
      this.consecutiveHardFails = 0;
      const recent = this.successWindow.slice(-20);
      const okRate = recent.reduce((a, b) => a + b, 0) / recent.length;
      if (okRate >= 0.92 && this.concurrency < this.maxConcurrency) {
        this.concurrency += 1;
        this.delayMs = Math.max(BASE_DELAY_MS, Math.floor(this.delayMs * 0.85));
      }
    } else if (status === 429 || status === 403) {
      this.concurrency = Math.max(MIN_CONCURRENCY, this.concurrency - 1);
      this.delayMs = Math.min(5000, Math.max(this.delayMs * 2, 400));
    } else {
      const fails = this.failWindow.slice(-15).reduce((a, b) => a + b, 0);
      if (fails >= 5) {
        this.concurrency = Math.max(MIN_CONCURRENCY, this.concurrency - 1);
        this.delayMs = Math.min(3000, this.delayMs + 50);
      }
    }
  }

  async waitIfNeeded() {
    const now = Date.now();
    if (now < this.circuitOpenUntil) {
      await sleep(this.circuitOpenUntil - now);
      this.circuitOpenUntil = 0;
      this.consecutiveHardFails = 0;
    }
    if (this.delayMs > 0) {
      await sleep(jitter(this.delayMs));
    }
  }
}

async function fetchOne(session, url, attempt = 0) {
  const t0 = Date.now();
  try {
    const res = await session.context.request.get(url, { timeout: 30000 });
    const status = res.status();
    const retryAfter = res.headers()['retry-after'];
    const html = await res.text();
    const challenge = looksLikeChallenge(html);
    const ok = res.ok() && !challenge;
    let error = null;
    if (challenge) error = 'challenge_page';
    else if (!res.ok()) error = `http_${status}`;

    if ((status === 429 || status === 503) && attempt < 3) {
      let waitMs = 2000 * 2 ** attempt;
      if (retryAfter) {
        const n = Number(retryAfter);
        if (!Number.isNaN(n)) waitMs = Math.max(waitMs, n * 1000);
      }
      await sleep(jitter(waitMs));
      return fetchOne(session, url, attempt + 1);
    }

    return {
      url,
      ok,
      status,
      ms: Date.now() - t0,
      challenge,
      html: ok ? html : '',
      error,
      retry_after: retryAfter || null,
      attempts: attempt + 1,
    };
  } catch (err) {
    const msg = String(err && err.message ? err.message : err);
    if (attempt < 2 && /Timeout|ECONNRESET|ERR_PROXY|ERR_SOCKS|network/i.test(msg)) {
      await sleep(jitter(1000 * 2 ** attempt));
      return fetchOne(session, url, attempt + 1);
    }
    return {
      url,
      ok: false,
      status: 0,
      ms: Date.now() - t0,
      html: '',
      error: msg,
      attempts: attempt + 1,
    };
  }
}

async function runPool(sessionHolder, urls, pool, startedAt) {
  const unique = uniqueUrls(urls);
  const resultsByUrl = new Map();
  const latencies = [];
  let httpFails = 0;
  let challenges = 0;
  let proxyFails = 0;
  let recycleLock = Promise.resolve();
  let inFlight = 0;

  async function withRecycle(fn) {
    const prev = recycleLock;
    let release;
    recycleLock = new Promise((r) => {
      release = r;
    });
    await prev;
    try {
      return await fn();
    } finally {
      release();
    }
  }

  async function processUrl(url) {
    await pool.waitIfNeeded();
    if (OVERALL_MS > 0 && Date.now() - startedAt > OVERALL_MS) {
      resultsByUrl.set(url, { url, ok: false, status: 0, ms: 0, html: '', error: 'deadline' });
      return;
    }

    let result = await fetchOne(sessionHolder.session, url);
    const hard =
      result.challenge ||
      /ERR_PROXY|ERR_SOCKS|ECONNREFUSED|Timeout|challenge/i.test(String(result.error || ''));
    if (result.challenge) challenges++;
    if (!result.ok) httpFails++;
    if (/ERR_PROXY|ERR_SOCKS|proxy/i.test(String(result.error || ''))) proxyFails++;

    if (hard && !result.ok) {
      result = await withRecycle(async () => {
        try {
          await sessionHolder.session.browser.close().catch(() => {});
          sessionHolder.session = await openSession();
          return fetchOne(sessionHolder.session, url);
        } catch (e) {
          return {
            url,
            ok: false,
            status: 0,
            ms: 0,
            html: '',
            error: 'session_recycle_failed:' + String(e.message || e),
          };
        }
      });
    }

    await withRecycle(async () => {
      sessionHolder.session.fetchedSinceWarm++;
      if (sessionHolder.session.fetchedSinceWarm >= RECYCLE_EVERY) {
        await sessionHolder.session.browser.close().catch(() => {});
        sessionHolder.session = await openSession();
      }
    });

    pool.record(!!result.ok, result.status || 0, hard && !result.ok);
    latencies.push(result.ms || 0);
    resultsByUrl.set(url, result);
  }

  let next = 0;
  await new Promise((resolve) => {
    const tryLaunch = () => {
      while (
        inFlight < pool.concurrency &&
        inFlight < pool.maxConcurrency &&
        next < unique.length
      ) {
        if (OVERALL_MS > 0 && Date.now() - startedAt > OVERALL_MS) break;
        const url = unique[next++];
        inFlight++;
        processUrl(url).finally(() => {
          inFlight--;
          if (next >= unique.length && inFlight === 0) resolve();
          else tryLaunch();
        });
      }
      if (unique.length === 0) resolve();
      else if (next >= unique.length && inFlight === 0) resolve();
    };
    tryLaunch();
  });

  // Expand results to original URL list order (duplicates share same payload reference)
  const results = urls.map((u) => {
    const r = resultsByUrl.get(String(u).trim());
    return r || { url: u, ok: false, status: 0, ms: 0, html: '', error: 'missing' };
  });

  const sorted = [...latencies].sort((a, b) => a - b);
  const ok = results.filter((r) => r.ok).length;
  return {
    results,
    metrics: {
      unique_urls: unique.length,
      requested: urls.length,
      saved_duplicate_fetches: Math.max(0, urls.length - unique.length),
      ok,
      failed: results.length - ok,
      concurrency_cap: pool.maxConcurrency,
      concurrency_end: pool.concurrency,
      delay_ms_end: pool.delayMs,
      http_fail_rate: results.length ? Number(((httpFails / results.length) * 100).toFixed(2)) : 0,
      challenge_count: challenges,
      proxy_fail_count: proxyFails,
      avg_ms: latencies.length ? Math.round(latencies.reduce((a, b) => a + b, 0) / latencies.length) : null,
      p50_ms: percentile(sorted, 50),
      p90_ms: percentile(sorted, 90),
    },
  };
}

async function mainUrlsFile() {
  const urlsFile = arg('--urls-file');
  const outPath = arg('--out', path.join(root, 'storage', 'logs', 'ngstate-pool-out.json'));
  if (!urlsFile) {
    console.error('Usage: --urls-file <path> [--out path] [--concurrency N]');
    process.exit(2);
  }
  const urls = fs
    .readFileSync(urlsFile, 'utf8')
    .split(/\r?\n/)
    .map((s) => s.trim())
    .filter(Boolean);

  const started = Date.now();
  const sessionHolder = { session: await openSession() };
  const pool = new AdaptivePool(MAX_CONCURRENCY);
  const warmMs = sessionHolder.session.warmMs;
  try {
    const { results, metrics } = await runPool(sessionHolder, urls, pool, started);
    const elapsed = Date.now() - started;
    const summary = {
      ...metrics,
      warm_ms: warmMs,
      elapsed_ms: elapsed,
      per_min: urls.length ? Number((urls.length / (elapsed / 60000)).toFixed(2)) : null,
      ok_per_min: metrics.ok ? Number((metrics.ok / (elapsed / 60000)).toFixed(2)) : null,
    };
    // Strip huge html for optional slim? PHP needs html — write full
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify({ summary, results }, null, 2));
    console.log(JSON.stringify(summary, null, 2));
    process.exit(summary.ok > 0 ? 0 : 2);
  } finally {
    await sessionHolder.session.browser.close().catch(() => {});
  }
}

async function mainStdio() {
  const started = Date.now();
  const sessionHolder = { session: await openSession() };
  const pool = new AdaptivePool(MAX_CONCURRENCY);
  console.log(JSON.stringify({ op: 'ready', warm_ms: sessionHolder.session.warmMs, concurrency: pool.maxConcurrency }));

  const rl = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
  for await (const line of rl) {
    const trimmed = line.trim();
    if (!trimmed) continue;
    let msg;
    try {
      msg = JSON.parse(trimmed);
    } catch {
      console.log(JSON.stringify({ op: 'error', error: 'invalid_json' }));
      continue;
    }
    if (msg.op === 'quit') break;
    if (msg.op === 'ping') {
      console.log(JSON.stringify({ op: 'pong', id: msg.id ?? null }));
      continue;
    }
    if (msg.op === 'fetch') {
      const urls = Array.isArray(msg.urls) ? msg.urls : [];
      if (typeof msg.concurrency === 'number' && msg.concurrency >= 1 && msg.concurrency <= 8) {
        pool.maxConcurrency = Math.floor(msg.concurrency);
        pool.concurrency = Math.min(pool.concurrency, pool.maxConcurrency);
      }
      const { results, metrics } = await runPool(sessionHolder, urls, pool, started);
      // Emit without pretty print — one line
      console.log(JSON.stringify({ op: 'result', id: msg.id ?? null, results, metrics }));
      continue;
    }
    console.log(JSON.stringify({ op: 'error', error: 'unknown_op' }));
  }
  await sessionHolder.session.browser.close().catch(() => {});
  process.exit(0);
}

if (hasFlag('--stdio')) {
  await mainStdio();
} else {
  await mainUrlsFile();
}
