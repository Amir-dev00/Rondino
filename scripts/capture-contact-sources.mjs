/**
 * Deeper contact-source probe:
 * - All XHR/fetch/document responses (including those with phones)
 * - Inline script / transfer-state phone hits in final HTML
 * - page.evaluate of performance resource entries
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { looksLikeChallenge, normalizeProxyServer } from './browser-fetch-lib.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(path.join(root, 'scripts'));
const detailUrl = process.argv[2] || 'https://rond.ir/Sims/09121760814';
const proxy = normalizeProxyServer(process.env.RONDINO_BROWSER_PROXY || 'socks5://127.0.0.1:10808');
const outPath = path.join(root, 'storage', 'logs', 'contact-sources-probe.json');

const net = [];
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  locale: 'fa-IR',
  proxy: proxy ? { server: proxy } : undefined,
});
const page = await context.newPage();

page.on('response', async (res) => {
  const req = res.request();
  const u = res.url();
  if (!u.includes('rond.ir')) return;
  const type = req.resourceType();
  if (!['xhr', 'fetch', 'document', 'script', 'other'].includes(type)) return;
  let text = '';
  try {
    if (type === 'document' || type === 'xhr' || type === 'fetch' || /\/api\//.test(u)) {
      text = await res.text();
    }
  } catch {
    text = '';
  }
  const phones = text ? [...new Set((text.match(/0\d{10}/g) || []))] : [];
  const hasSeller = /اطلاعات\s*تماس\s*فروشنده|contact-button-advert|mobileNumber|sellerPhone|shopInfo/i.test(text);
  if (type === 'document' || type === 'xhr' || type === 'fetch' || /\/api\//.test(u) || phones.length || hasSeller) {
    net.push({
      url: u.split('?')[0],
      fullUrl: u.slice(0, 300),
      method: req.method(),
      type,
      status: res.status(),
      phoneCount: phones.length,
      phonesSample: phones.slice(0, 8),
      hasSellerHint: hasSeller,
      preview: text.slice(0, 500),
    });
  }
});

await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 90000 });
for (let i = 0; i < 35; i++) {
  const html = await page.content().catch(() => '');
  if (html.includes('/Sims/0912') && !looksLikeChallenge(html)) break;
  await page.waitForTimeout(1000);
}

net.length = 0;
await page.goto(detailUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(4000);
try {
  await page.waitForSelector('a.contact-button-advert[href^="tel:"]', { timeout: 15000 });
} catch {
  /* continue */
}

const html = await page.content();
const inlineHits = [];
// Look for transfer state / JSON blobs containing the seller phone near advert structures
const telMatches = [...html.matchAll(/tel:(0\d{10})/gi)].map((m) => m[1]);
const scriptPhoneBlocks = [];
for (const m of html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/gi)) {
  const body = m[1];
  if (/0\d{10}/.test(body) && /seller|contact|advert|shop|mobile/i.test(body)) {
    scriptPhoneBlocks.push(body.slice(0, 800));
  }
}

const perf = await page.evaluate(() =>
  performance.getEntriesByType('resource').map((e) => ({
    name: String(e.name).slice(0, 250),
    initiatorType: e.initiatorType,
    duration: Math.round(e.duration),
  })).filter((e) => /api|advert|seller|contact|customer|shop|graphql/i.test(e.name))
);

const ngState = await page.evaluate(() => {
  const el = document.getElementById('serverApp-state') || document.querySelector('script#ng-state');
  if (!el) return { present: false };
  const raw = el.textContent || '';
  const phones = [...new Set(raw.match(/0\d{10}/g) || [])];
  return { present: true, bytes: raw.length, phones: phones.slice(0, 20), preview: raw.slice(0, 1000) };
});

// Try in-page fetch of previously guessed endpoints using advert code from URL state if available
const pageProbe = await page.evaluate(async () => {
  const sim = location.pathname.split('/').pop();
  const candidates = [];
  // Discover links/data attributes that might expose codes
  const codeHints = [...document.body.innerHTML.matchAll(/\b(12\d{10})\b/g)].map((m) => m[1]);
  const code = codeHints[0] || null;
  const paths = [];
  if (code) {
    paths.push(
      `/api/ns/sim-card/web/sim-card-advert/${code}`,
      `/api/ns/sim-card/web/sim-card-advert/${code}/customer`,
      `/api/ns/sim-card/web/sim-card-advert/by-sim-card-number/${sim}`,
      `/api/ns/sim-card/web/sim-card-advert/sim-card-number/${sim}`,
      `/api/ns/sim-card/web/customer/by-advert/${code}`,
      `/api/ns/sim-card/web/seller/by-advert/${code}`
    );
  }
  paths.push(
    `/api/ns/sim-card/web/sim-card-advert/by-sim/${sim}`,
    `/api/ns/sim-card/web/sim-card-advert/sim/${sim}`
  );
  for (const p of [...new Set(paths)]) {
    try {
      const r = await fetch(p, { headers: { accept: 'application/json' } });
      const t = await r.text();
      candidates.push({
        path: p,
        status: r.status,
        phones: [...new Set(t.match(/0\d{10}/g) || [])].slice(0, 10),
        preview: t.slice(0, 400),
      });
    } catch (e) {
      candidates.push({ path: p, error: String(e) });
    }
  }
  return { sim, code, candidates };
});

const report = {
  detailUrl,
  finishedAt: new Date().toISOString(),
  htmlTelHrefs: [...new Set(telMatches)],
  networkInteresting: net,
  perfApiLike: perf,
  ngState,
  scriptPhoneBlockCount: scriptPhoneBlocks.length,
  scriptPhoneBlocksPreview: scriptPhoneBlocks.slice(0, 3),
  inPageEndpointProbe: pageProbe,
};
fs.writeFileSync(outPath, JSON.stringify(report, null, 2));
console.log(JSON.stringify({
  htmlTelHrefs: report.htmlTelHrefs,
  networkCount: net.length,
  networkWithPhones: net.filter((n) => n.phoneCount > 0).map((n) => ({ url: n.url, type: n.type, status: n.status, phones: n.phonesSample })),
  documentsWithPhones: net.filter((n) => n.type === 'document' && n.phoneCount > 0).length,
  ngState,
  perfApiLike: perf,
  endpointProbe: pageProbe.candidates.map((c) => ({ path: c.path, status: c.status, phones: c.phones, preview: (c.preview || '').slice(0, 120) })),
  outPath,
}, null, 2));
await browser.close();
