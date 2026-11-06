/**
 * Fast contact fetch: one Playwright warm-up, then context.request.get for each Sims URL.
 * Returns HTML bodies so PHP can parse SSR contactGroup (no per-page UI hydration wait).
 *
 * Usage: node scripts/browser-ngstate-session.mjs --urls-file urls.txt --out out.json
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { looksLikeChallenge, normalizeProxyServer } from './browser-fetch-lib.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(path.join(root, 'scripts'));

function arg(name, fallback = null) {
  const i = process.argv.indexOf(name);
  return i === -1 ? fallback : process.argv[i + 1] ?? fallback;
}

const DELAY_MS = Math.max(50, Number(process.env.RONDINO_CONTACT_DELAY_MS || 150));
const RECYCLE_EVERY = Math.max(10, Number(process.env.RONDINO_CONTACT_RECYCLE_EVERY || 80));
const OVERALL_MS = Number(process.env.RONDINO_BROWSER_DEADLINE_MS || 900000);
const proxy = normalizeProxyServer(process.env.RONDINO_BROWSER_PROXY || '');

const urlsFile = arg('--urls-file');
const outPath = arg('--out', path.join(root, 'storage', 'logs', 'ngstate-session-out.json'));
if (!urlsFile) {
  console.error('Usage: --urls-file <path> [--out path]');
  process.exit(2);
}
const urls = fs
  .readFileSync(urlsFile, 'utf8')
  .split(/\r?\n/)
  .map((s) => s.trim())
  .filter(Boolean);

async function warm(page) {
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
  throw new Error('warm-up failed');
}

async function openSession() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    locale: 'fa-IR',
    proxy: proxy ? { server: proxy } : undefined,
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
  });
  const page = await context.newPage();
  const t0 = Date.now();
  await warm(page);
  return { browser, context, page, warmMs: Date.now() - t0 };
}

const started = Date.now();
let session = await openSession();
let since = 0;
const results = [];
const warmups = [session.warmMs];

for (const url of urls) {
  if (Date.now() - started > OVERALL_MS) break;
  if (since >= RECYCLE_EVERY) {
    await session.browser.close().catch(() => {});
    session = await openSession();
    warmups.push(session.warmMs);
    since = 0;
  }
  const t0 = Date.now();
  try {
    const res = await session.context.request.get(url, { timeout: 30000 });
    const html = await res.text();
    const challenge = looksLikeChallenge(html);
    results.push({
      url,
      ok: res.ok() && !challenge,
      status: res.status(),
      ms: Date.now() - t0,
      challenge,
      html: challenge ? '' : html,
      error: challenge ? 'challenge_page' : null,
    });
  } catch (err) {
    results.push({
      url,
      ok: false,
      status: 0,
      ms: Date.now() - t0,
      html: '',
      error: String(err && err.message ? err.message : err),
    });
    await session.browser.close().catch(() => {});
    try {
      session = await openSession();
      warmups.push(session.warmMs);
      since = 0;
    } catch (e2) {
      results.push({ url: '__session__', ok: false, error: String(e2.message || e2), html: '' });
      break;
    }
  }
  since++;
  await session.page.waitForTimeout(DELAY_MS);
}
await session.browser.close().catch(() => {});

const summary = {
  count: results.length,
  ok: results.filter((r) => r.ok).length,
  failed: results.filter((r) => !r.ok).length,
  elapsed_ms: Date.now() - started,
  avg_ms: results.length ? Math.round((Date.now() - started) / results.length) : null,
  per_min: results.length ? Number((results.length / ((Date.now() - started) / 60000)).toFixed(2)) : null,
  warmups_ms: warmups,
};
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify({ summary, results }, null, 2));
console.log(JSON.stringify(summary, null, 2));
process.exit(summary.ok > 0 ? 0 : 2);
