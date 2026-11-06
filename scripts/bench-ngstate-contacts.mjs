/**
 * Benchmark: after one homepage warm-up, fetch Sims pages via context.request
 * and parse SSR transfer-state contactGroup (no full page render / tel wait).
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { looksLikeChallenge, normalizeProxyServer } from './browser-fetch-lib.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(path.join(root, 'scripts'));
const proxy = normalizeProxyServer(process.env.RONDINO_BROWSER_PROXY || '');
const sims = (process.argv.slice(2).filter((a) => /^0912\d{7}$/.test(a)));
const list = sims.length
  ? sims
  : ['09121760814', '09120079029', '09123857983', '09122214727', '09122652967',
     '09124676251', '09120940021', '09127837701', '09127983283', '09125451927'];

function decodeNgState(raw) {
  // Angular Universal escapes: &q; "  &a; &  &s; '
  return raw
    .replace(/&q;/g, '"')
    .replace(/&a;/g, '&')
    .replace(/&s;/g, "'")
    .replace(/&l;/g, '<')
    .replace(/&g;/g, '>');
}

function extractContacts(html, sim) {
  const m =
    html.match(/id="serverApp-state"[^>]*>([^<]*)</i) ||
    html.match(/id="ng-state"[^>]*>([^<]*)</i) ||
    html.match(/id='serverApp-state'[^>]*>([^<]*)</i);
  if (!m) return { ok: false, reason: 'no_ng_state' };
  let json;
  try {
    json = JSON.parse(decodeNgState(m[1]));
  } catch (e) {
    return { ok: false, reason: 'json_parse', error: String(e.message || e) };
  }
  const key =
    Object.keys(json).find((k) => k === `single-sim-detail-${sim}`) ||
    Object.keys(json).find((k) => k.includes(sim));
  if (!key) return { ok: false, reason: 'no_detail_key', keys: Object.keys(json).slice(0, 10) };
  const detail = json[key];
  if (!detail || typeof detail !== 'object') return { ok: false, reason: 'bad_detail' };
  const cg = detail.contactGroup || {};
  const phones = [];
  if (typeof cg.mainCallNumber === 'string') phones.push(cg.mainCallNumber);
  for (const c of cg.contacts || []) {
    if (c && typeof c.callNumber === 'string') phones.push(c.callNumber);
  }
  return {
    ok: true,
    code: detail.code ?? null,
    simCardNumber: detail.simCardNumber ?? null,
    customerId: detail.customerId ?? null,
    phones: [...new Set(phones)],
    hasShop: detail.hasShop ?? null,
  };
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  locale: 'fa-IR',
  proxy: proxy ? { server: proxy } : undefined,
  userAgent:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
});
const page = await context.newPage();
const warmT0 = Date.now();
await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 90000 });
for (let i = 0; i < 40; i++) {
  let h = '';
  try {
    h = await page.content();
  } catch {
    await page.waitForTimeout(800);
    continue;
  }
  if (h.includes('/Sims/0912') && !looksLikeChallenge(h)) break;
  await page.waitForTimeout(800);
}
const warmMs = Date.now() - warmT0;

const items = [];
const t0 = Date.now();
for (const sim of list) {
  const s = Date.now();
  try {
    const res = await context.request.get('https://rond.ir/Sims/' + sim, { timeout: 30000 });
    const html = await res.text();
    const extracted = extractContacts(html, sim);
    items.push({
      sim,
      httpStatus: res.status(),
      ms: Date.now() - s,
      htmlBytes: html.length,
      challenge: looksLikeChallenge(html),
      ...extracted,
    });
  } catch (e) {
    items.push({ sim, ok: false, ms: Date.now() - s, error: String(e.message || e) });
  }
  await page.waitForTimeout(150);
}
const totalMs = Date.now() - t0;
const ok = items.filter((i) => i.ok && (i.phones || []).length);
const report = {
  warm_ms: warmMs,
  fetch_total_ms: totalMs,
  count: list.length,
  with_phones: ok.length,
  avg_ms: Math.round(totalMs / list.length),
  per_min: Number((list.length / (totalMs / 60000)).toFixed(2)),
  verified_per_min: Number((ok.length / (totalMs / 60000)).toFixed(2)),
  items,
};
fs.writeFileSync(path.join(root, 'storage', 'logs', 'bench-ngstate-contacts.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
await browser.close();
process.exit(ok.length > 0 ? 0 : 2);
