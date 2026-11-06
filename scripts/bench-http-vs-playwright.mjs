/**
 * Phase 5: after Playwright warm-up, compare context.request vs raw fetch with same cookies.
 * Usage: node scripts/bench-http-vs-playwright.mjs [sim...]
 */
import { chromium } from 'playwright';
import { looksLikeChallenge, normalizeProxyServer } from './browser-fetch-lib.mjs';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(path.join(root, 'scripts'));
const proxy = normalizeProxyServer(process.env.RONDINO_BROWSER_PROXY || '');
const sims = process.argv.slice(2).filter((a) => /^0912\d{7}$/.test(a));
const list = sims.length ? sims.slice(0, 8) : ['09121760814', '09120079029', '09123857983', '09122214727'];

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  locale: 'fa-IR',
  proxy: proxy ? { server: proxy } : undefined,
  userAgent:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
});
const page = await context.newPage();
await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 90000 });
for (let i = 0; i < 40; i++) {
  const h = await page.content().catch(() => '');
  if (h.includes('/Sims/0912') && !looksLikeChallenge(h)) break;
  await page.waitForTimeout(700);
}
const cookies = await context.cookies();
const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');

async function viaPlaywright(sim) {
  const t0 = Date.now();
  const res = await context.request.get('https://rond.ir/Sims/' + sim, { timeout: 30000 });
  const html = await res.text();
  return {
    transport: 'playwright_request',
    sim,
    status: res.status(),
    ms: Date.now() - t0,
    bytes: html.length,
    challenge: looksLikeChallenge(html),
    hasNg: /serverApp-state|ng-state/i.test(html),
  };
}

async function viaFetch(sim) {
  const t0 = Date.now();
  try {
    const res = await fetch('https://rond.ir/Sims/' + sim, {
      headers: {
        'User-Agent':
          'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        Cookie: cookieHeader,
        Accept: 'text/html',
      },
    });
    const html = await res.text();
    return {
      transport: 'fetch_with_cookies',
      sim,
      status: res.status(),
      ms: Date.now() - t0,
      bytes: html.length,
      challenge: looksLikeChallenge(html),
      hasNg: /serverApp-state|ng-state/i.test(html),
    };
  } catch (e) {
    return {
      transport: 'fetch_with_cookies',
      sim,
      status: 0,
      ms: Date.now() - t0,
      error: String(e.message || e),
      challenge: false,
      hasNg: false,
    };
  }
}

const rows = [];
for (const sim of list) {
  rows.push(await viaPlaywright(sim));
  rows.push(await viaFetch(sim));
}
await browser.close();

const report = {
  note: 'Native fetch may not use SOCKS5 proxy; Playwright request does. Compare hasNg+challenge.',
  proxy_configured: !!proxy,
  cookie_count: cookies.length,
  rows,
};
fs.writeFileSync(path.join(root, 'storage', 'logs', 'bench-http-vs-playwright.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
