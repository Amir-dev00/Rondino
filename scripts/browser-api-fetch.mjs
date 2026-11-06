/**
 * Browser-mediated JSON API fetch for rond.ir (ArvanCloud challenge bypass).
 * Warms up on the public homepage, then POSTs to the verified listing endpoint.
 *
 * Usage: node scripts/browser-api-fetch.mjs <apiUrl> [out.json] [diag.json]
 *
 * Optional env:
 *   RONDINO_BROWSER_PROXY / HTTPS_PROXY
 *   RONDINO_API_POST_BODY  JSON body (defaults to 0912 MCI landing search)
 *   RONDINO_BROWSER_DEADLINE_MS
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { looksLikeChallenge, normalizeProxyServer } from './browser-fetch-lib.mjs';

const url = process.argv[2];
const out = process.argv[3] || '';
const diagPath = process.argv[4] || (out ? out.replace(/\.json$/i, '') + '.diag.json' : '');
if (!url) {
  console.error('Usage: node scripts/browser-api-fetch.mjs <apiUrl> [out.json] [diag.json]');
  process.exit(2);
}

const OVERALL_MS = Number(process.env.RONDINO_BROWSER_DEADLINE_MS || 120000);
const startedAt = Date.now();
const detailMode = process.argv.includes('--detail');
const batchSize = Math.max(1, Math.min(4, Number(process.env.RONDINO_API_BATCH_SIZE || 3)));

const DEFAULT_SEARCH = {
  advertSellTypeIn: null,
  advertSellTypeNotIn: null,
  advertSpecialTypeIn: null,
  advertSpecialTypeNotIn: null,
  advertPriceTypeIn: null,
  advertPriceTypeNotIn: null,
  fromPrice: null,
  toPrice: null,
  simCardNumberPattern: null,
  simCardNumberPatternIsNot: null,
  simCardNumberPreCodes: ['0912'],
  simCardNumberPreCodesNotIn: null,
  simCardNumberWithoutPreCodePattern: null,
  simCardNumberWithoutPreCodePatternIsNot: null,
  operatorIdIn: ['65cb3414c8133a36e6f8708f'],
  operatorIdNotIn: null,
  operatorTypesIn: null,
  operatorTypesNotIn: null,
  simCardStatusIn: null,
  simCardTypeIn: null,
  rondTypeIds: null,
  provinceIdIn: null,
  cityIdIn: null,
  allFieldNull: false,
};

/** @type {Record<string, unknown>} */
const diag = {
  input_url: url,
  started_at: new Date().toISOString(),
  proxy_used: null,
  classification: null,
  error: null,
};

function remainingMs() {
  return Math.max(1000, OVERALL_MS - (Date.now() - startedAt));
}

function resolveProxyServer() {
  for (const k of ['RONDINO_BROWSER_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY', 'https_proxy', 'http_proxy']) {
    const v = process.env[k];
    if (v && String(v).trim()) {
      return normalizeProxyServer(v);
    }
  }
  return null;
}

function writeDiag(extra = {}) {
  Object.assign(diag, extra, { finished_at: new Date().toISOString(), elapsed_ms: Date.now() - startedAt });
  if (!diagPath) return;
  try {
    fs.mkdirSync(path.dirname(diagPath), { recursive: true });
    const safe = { ...diag };
    if (typeof safe.proxy_used === 'string') {
      safe.proxy_used = safe.proxy_used.replace(/\/\/([^/@]+)@/, '//***@');
    }
    fs.writeFileSync(diagPath, JSON.stringify(safe, null, 2), 'utf8');
  } catch {
    // ignore
  }
}

function parseBody() {
  const raw = process.env.RONDINO_API_POST_BODY;
  if (raw && String(raw).trim()) {
    return JSON.parse(String(raw));
  }
  return DEFAULT_SEARCH;
}

async function safeContent(page) {
  for (let i = 0; i < 12; i++) {
    try {
      return await page.content();
    } catch {
      await page.waitForTimeout(600);
    }
  }
  return '';
}

async function warmUp(page) {
  let lastErr = null;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      console.error(`warm-up goto attempt=${attempt}`);
      await page.goto('https://rond.ir/', {
        waitUntil: 'domcontentloaded',
        timeout: Math.min(45000, remainingMs()),
      });
      lastErr = null;
      break;
    } catch (err) {
      lastErr = err;
      console.error(`warm-up goto fail: ${String(err && err.message ? err.message : err).split('\n')[0]}`);
      await page.waitForTimeout(1200 * attempt);
    }
  }
  if (lastErr) {
    const msg = String(lastErr && lastErr.message ? lastErr.message : lastErr);
    const kind = /ERR_NAME_NOT_RESOLVED|ENOTFOUND/i.test(msg)
      ? 'dns_failure'
      : /ERR_PROXY|ERR_CONNECTION|ERR_TIMED_OUT|ETIMEDOUT|Timeout|timed out/i.test(msg)
        ? 'connection_failure'
        : 'unexpected_error';
    throw Object.assign(new Error(msg), { code: kind === 'connection_failure' || kind === 'dns_failure' ? 10 : 20, kind });
  }
  const deadline = Date.now() + Math.min(50000, remainingMs());
  while (Date.now() < deadline) {
    const html = await safeContent(page);
    if (html && !looksLikeChallenge(html) && /\/Sims\/0912\d{7}/.test(html)) {
      return true;
    }
    await page.waitForTimeout(1000);
  }
  const html = await safeContent(page);
  if (looksLikeChallenge(html)) {
    throw Object.assign(new Error('challenge page during warm-up'), { code: 13, kind: 'challenge_observed' });
  }
  throw Object.assign(new Error('warm-up did not reach usable homepage'), { code: 14, kind: 'no_listings' });
}

async function runWithProxy(proxyServer) {
  const contextOpts = {
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    locale: 'fa-IR',
    viewport: { width: 1400, height: 900 },
  };
  if (proxyServer) {
    contextOpts.proxy = { server: proxyServer };
    diag.proxy_used = proxyServer;
  } else {
    diag.proxy_used = null;
  }

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext(contextOpts);
  const page = await context.newPage();
  try {
    await warmUp(page);
    if (detailMode) {
      const target = new URL(url);
      if (target.origin !== 'https://rond.ir' || !/^\/Sims\/0912\d{7}$/.test(target.pathname)) throw new Error('Invalid detail URL');
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: Math.min(30000, remainingMs()) });
      if (!response || !response.ok()) throw new Error('Detail HTTP ' + response?.status());
      await page.waitForTimeout(1500);
      const html = await safeContent(page);
      if (looksLikeChallenge(html)) throw new Error('Detail challenge observed');
      fs.mkdirSync(path.dirname(out), { recursive: true });
      fs.writeFileSync(out, html);
      diag.classification = 'success'; writeDiag();
      return { code: 0 };
    }
    const body = parseBody();
    const pages = [];
    let apiUrl = url;
    for (let batchIndex = 0; batchIndex < batchSize && remainingMs() > 5000; batchIndex++) {
    try {
    const result = await page.evaluate(
      async ({ apiUrl, body }) => {
        const res = await fetch(apiUrl, {
          signal: AbortSignal.timeout(25000),
          method: 'POST',
          headers: {
            'content-type': 'application/json',
            accept: 'application/json',
            'x-requested-with': 'XMLHttpRequest',
          },
          body: JSON.stringify(body),
        });
        const text = await res.text();
        return { status: res.status, text, ok: res.ok };
      },
      { apiUrl, body }
    );

    if (!result.ok || result.status >= 400) {
      diag.classification = 'http_error';
      diag.error = `API HTTP ${result.status}`;
      writeDiag({ api_status: result.status, preview: String(result.text || '').slice(0, 300) });
      if (pages.length) break;
      return { code: 12 };
    }
    if (!/simCardNumber|totalElements|"content"\s*:/.test(result.text || '')) {
      diag.classification = 'no_listings';
      diag.error = 'API response lacked listing payload';
      writeDiag({ preview: String(result.text || '').slice(0, 300) });
      if (pages.length) break;
      return { code: 14 };
    }

    const payload = JSON.parse(result.text);
    const requested = Number(new URL(apiUrl).searchParams.get('page'));
    if (!Array.isArray(payload.content) || Number(payload.number ?? payload.pageable?.pageNumber) !== requested) {
      throw new Error('API page identity/schema mismatch');
    }
    pages.push({ url: apiUrl, body: result.text });
    if (payload.last === true || (Number.isInteger(payload.totalPages) && requested + 1 >= payload.totalPages) || !payload.content.length) break;
    const next = new URL(apiUrl); next.searchParams.set('page', String(requested + 1));
    apiUrl = next.href;
    await page.waitForTimeout(1000);
    } catch (err) {
      if (!pages.length) throw err;
      diag.prefetch_error = String(err.message || err);
      break; // Persist only successfully fetched pages; the PHP cursor still controls progress.
    }
    }
    if (!pages.length) throw new Error('No API pages fetched within deadline');
    const output = JSON.stringify({ pages });
    if (out) { fs.mkdirSync(path.dirname(out), { recursive: true }); fs.writeFileSync(out, output); }
    else process.stdout.write(output);
    diag.classification = 'success'; diag.error = null;
    writeDiag({ pages_fetched: pages.length });
    return { code: 0 };
  } catch (err) {
    const kind = err && err.kind ? err.kind : 'unexpected_error';
    const code = err && err.code ? err.code : 20;
    diag.classification = kind;
    diag.error = String(err && err.message ? err.message : err);
    writeDiag();
    console.error('browser-api-fetch: ' + diag.error);
    return { code };
  } finally {
    await context.close().catch(() => {});
    await browser.close().catch(() => {});
  }
}

let exitCode = 20;
try {
  const explicit = resolveProxyServer();
  let result = await runWithProxy(explicit);
  if ((result.code === 10 || result.code === 11 || result.code === 20) && process.env.RONDINO_BROWSER_FALLBACK_PROXY) {
    let raw = String(process.env.RONDINO_BROWSER_FALLBACK_PROXY).trim();
    raw = normalizeProxyServer(raw) || raw;
    if (raw && raw !== explicit) {
      console.error('retrying api fetch with fallback proxy');
      result = await runWithProxy(raw);
    }
  }
  exitCode = result.code;
} catch (err) {
  diag.classification = 'unexpected_error';
  diag.error = String(err && err.message ? err.message : err);
  writeDiag();
  exitCode = 20;
}
process.exitCode = exitCode;

