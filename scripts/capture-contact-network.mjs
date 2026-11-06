/**
 * Capture all network requests while loading one Sims detail page.
 * Records API URLs, methods, status, and response body previews (phones redacted in log summary).
 *
 * Usage: node scripts/capture-contact-network.mjs https://rond.ir/Sims/09120911890
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { looksLikeChallenge, normalizeProxyServer } from './browser-fetch-lib.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(path.join(root, 'scripts'));
const detailUrl = process.argv[2] || 'https://rond.ir/Sims/09120911890';
const proxyRaw = process.env.RONDINO_BROWSER_PROXY || 'socks5://127.0.0.1:10808';
const proxy = normalizeProxyServer(proxyRaw);
const outPath = path.join(root, 'storage', 'logs', 'contact-network-capture.json');

function resolveProxy() {
  return proxy && String(proxy).trim() ? proxy : null;
}

const captures = [];
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  locale: 'fa-IR',
  proxy: resolveProxy() ? { server: resolveProxy() } : undefined,
  userAgent:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
});
const page = await context.newPage();

page.on('request', (req) => {
  const u = req.url();
  if (!/rond\.ir|arvan|graphql/i.test(u)) return;
  if (/\.(png|jpe?g|gif|svg|woff2?|css|ico)(\?|$)/i.test(u)) return;
});

page.on('response', async (res) => {
  const req = res.request();
  const u = res.url();
  if (!/rond\.ir/i.test(u)) return;
  if (/\.(png|jpe?g|gif|svg|woff2?|css|ico|js)(\?|$)/i.test(u) && !/\/api\//i.test(u)) return;
  const ct = (res.headers()['content-type'] || '').toLowerCase();
  const interesting =
    /\/api\//i.test(u) ||
    ct.includes('json') ||
    ct.includes('graphql') ||
    /seller|contact|customer|advert|shop/i.test(u);
  if (!interesting && !/\/Sims\//i.test(u)) return;

  let bodyPreview = '';
  let bodyJson = null;
  try {
    if (ct.includes('json') || /\/api\//i.test(u)) {
      const text = await res.text();
      bodyPreview = text.slice(0, 4000);
      try {
        bodyJson = JSON.parse(text);
      } catch {
        bodyJson = null;
      }
    }
  } catch {
    bodyPreview = '(unreadable)';
  }

  const phonesInBody = [];
  if (bodyPreview) {
    const m = bodyPreview.match(/0\d{10}/g);
    if (m) phonesInBody.push(...new Set(m));
  }

  captures.push({
    url: u,
    method: req.method(),
    resourceType: req.resourceType(),
    status: res.status(),
    contentType: ct,
    postData: (req.postData() || '').slice(0, 2000),
    phonesInBody,
    bodyPreview: bodyPreview.slice(0, 2500),
    bodyKeys: bodyJson && typeof bodyJson === 'object' ? Object.keys(bodyJson).slice(0, 40) : null,
  });
});

async function warm() {
  await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 90000 });
  for (let i = 0; i < 40; i++) {
    const html = await page.content().catch(() => '');
    if (html.includes('/Sims/0912') && !looksLikeChallenge(html)) return;
    await page.waitForTimeout(1000);
  }
}

const report = {
  detailUrl,
  startedAt: new Date().toISOString(),
  proxy: resolveProxy() ? String(resolveProxy()).replace(/\/\/([^/@]+)@/, '//***@') : null,
  captures: [],
  htmlTels: [],
  error: null,
};

try {
  await warm();
  captures.length = 0; // ignore warm-up noise for clarity; keep only detail phase
  await page.goto(detailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  try {
    await page.waitForFunction(
      () => {
        const body = document.body?.innerText || '';
        if (!body.includes('اطلاعات تماس فروشنده')) return false;
        const tel = document.querySelector('a[href^="tel:"]');
        return !!(tel && /tel:0\d{10}/i.test(tel.getAttribute('href') || ''));
      },
      { timeout: 20000 }
    );
  } catch {
    await page.waitForTimeout(3000);
  }
  // Extra settle for late XHR
  await page.waitForTimeout(2000);

  report.htmlTels = await page.evaluate(() =>
    [...document.querySelectorAll('a[href^="tel:"]')].map((a) => ({
      href: a.href,
      className: a.className,
      text: (a.textContent || '').trim().slice(0, 40),
    }))
  );
  report.captures = captures.slice();
} catch (err) {
  report.error = String(err && err.message ? err.message : err);
  report.captures = captures.slice();
} finally {
  await browser.close().catch(() => {});
  report.finishedAt = new Date().toISOString();
  // Summaries
  report.apiUrls = [...new Set(report.captures.filter((c) => /\/api\//i.test(c.url)).map((c) => c.method + ' ' + c.url))];
  report.capturesWithPhones = report.captures.filter((c) => c.phonesInBody.length > 0).map((c) => ({
    url: c.url,
    status: c.status,
    phonesInBody: c.phonesInBody,
  }));
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(report, null, 2));
  console.log(JSON.stringify({
    detailUrl: report.detailUrl,
    error: report.error,
    apiCount: report.apiUrls.length,
    apiUrls: report.apiUrls,
    capturesWithPhones: report.capturesWithPhones,
    htmlTels: report.htmlTels,
    outPath,
  }, null, 2));
  process.exit(report.error ? 2 : 0);
}
