/**
 * Live verification that advertSpecialTypeIn filters VIP/FREE listing streams.
 * Requires proxy + Playwright. Do not run while another browser crawl holds the proxy.
 *
 * Usage (from repo root):
 *   node scripts/verify-special-type-filter.mjs
 */
import { chromium } from './node_modules/playwright/index.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(root);
const proxy = process.env.RONDINO_BROWSER_PROXY || 'socks5://127.0.0.1:10808';
const outPath = path.join(root, 'storage', 'logs', 'verify-special-type-filter.json');

const SORT =
  'sort=advertSpecialType,desc&sort=lastUpdateDateMillis,desc&sort=createDateMillis,desc';

function baseBody() {
  return {
    advertSellTypeIn: null,
    advertSellTypeNotIn: null,
    advertSpecialTypeIn: null,
    advertSpecialTypeNotIn: null,
    createDateMillisMax: null,
    createDateMillisMin: null,
    deleteDateMillisMax: null,
    deleteDateMillisMin: null,
    lastUpdateDateMillisMax: null,
    lastUpdateDateMillisMin: null,
    priceMax: null,
    priceMin: null,
    searchBox: null,
    simCardNumber: null,
    simCardNumberPrefix: '0912',
    simCardStatusIn: null,
    simCardStatusNotIn: null,
    simCardTypeIn: null,
    simCardTypeNotIn: null,
  };
}

async function waitHome(page) {
  await page.goto('https://rond.ir/', { waitUntil: 'domcontentloaded', timeout: 90000 });
  for (let i = 0; i < 40; i++) {
    const html = await page.content().catch(() => '');
    if (html.includes('/Sims/0912') && !/__arcsjs|Transferring/i.test(html)) return;
    await page.waitForTimeout(1000);
  }
  throw new Error('homepage not ready');
}

async function fetchPage(page, special, pageNum) {
  const body = baseBody();
  if (special) body.advertSpecialTypeIn = [special];
  const url = `/api/ns/sim-card/web/sim-card-advert/page?page=${pageNum}&size=40&${SORT}`;
  return page.evaluate(
    async ({ url, body }) => {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'content-type': 'application/json', accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const text = await res.text();
      let json = null;
      try {
        json = JSON.parse(text);
      } catch {
        json = null;
      }
      return { status: res.status, json, textPreview: text.slice(0, 200) };
    },
    { url, body }
  );
}

function summarize(label, payload) {
  const content = Array.isArray(payload?.json?.content) ? payload.json.content : [];
  const types = {};
  for (const row of content) {
    const t = String(row?.advertSpecialType ?? '');
    types[t] = (types[t] || 0) + 1;
  }
  return {
    label,
    httpStatus: payload.status,
    page: payload.json?.number ?? null,
    totalPages: payload.json?.totalPages ?? null,
    totalElements: payload.json?.totalElements ?? null,
    last: payload.json?.last ?? null,
    count: content.length,
    typeCounts: types,
    sampleCodes: content.slice(0, 5).map((r) => ({
      code: r.code,
      sim: r.simCardNumber,
      type: r.advertSpecialType,
    })),
    allMatchRequested: null,
  };
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ locale: 'fa-IR', proxy: { server: proxy } });
const page = await context.newPage();

const report = {
  startedAt: new Date().toISOString(),
  proxy,
  checks: [],
  conclusions: [],
};

try {
  await waitHome(page);

  const unfiltered0 = summarize('unfiltered_page0', await fetchPage(page, null, 0));
  const unfilteredDeep = summarize('unfiltered_page_deep', await fetchPage(page, null, 50));
  const vip0 = summarize('VIP_page0', await fetchPage(page, 'VIP', 0));
  const vip1 = summarize('VIP_page1', await fetchPage(page, 'VIP', 1));
  const free0 = summarize('FREE_page0', await fetchPage(page, 'FREE', 0));
  const free1 = summarize('FREE_page1', await fetchPage(page, 'FREE', 1));

  for (const s of [unfiltered0, unfilteredDeep, vip0, vip1, free0, free1]) {
    report.checks.push(s);
  }

  const vipOk =
    vip0.count > 0 &&
    Object.keys(vip0.typeCounts).every((t) => t === 'VIP') &&
    Object.keys(vip1.typeCounts).every((t) => t === 'VIP' || vip1.count === 0);
  const freeOk =
    free0.count > 0 &&
    Object.keys(free0.typeCounts).every((t) => t === 'FREE') &&
    Object.keys(free1.typeCounts).every((t) => t === 'FREE' || free1.count === 0);

  vip0.allMatchRequested = vipOk;
  free0.allMatchRequested = freeOk;

  // Discover other special types from unfiltered pages + a mid page.
  const otherTypes = new Set();
  for (const s of [unfiltered0, unfilteredDeep]) {
    for (const t of Object.keys(s.typeCounts)) if (t && t !== 'VIP' && t !== 'FREE') otherTypes.add(t);
  }
  // Probe a few candidate type names if present in unfiltered deep mix.
  const mid = summarize('unfiltered_page_200', await fetchPage(page, null, 200));
  report.checks.push(mid);
  for (const t of Object.keys(mid.typeCounts)) if (t && t !== 'VIP' && t !== 'FREE') otherTypes.add(t);

  report.otherSpecialTypesObserved = [...otherTypes];
  report.filterHonored = { VIP: vipOk, FREE: freeOk };

  if (vipOk && freeOk) {
    report.conclusions.push(
      'advertSpecialTypeIn appears to filter streams correctly for VIP and FREE on sampled pages.'
    );
  } else {
    report.conclusions.push(
      'FILTER NOT RELIABLE: advertSpecialTypeIn did not return pure VIP/FREE pages. Safer alternative required.'
    );
  }
  if (otherTypes.size) {
    report.conclusions.push(
      `Other advertSpecialType values observed: ${[...otherTypes].join(', ')}. Add to rondino_incremental_special_types.`
    );
  } else {
    report.conclusions.push('Only VIP and FREE observed in sampled unfiltered pages.');
  }

  // Pagination independence: VIP totalPages should differ from FREE if filter works.
  report.paginationIndependent =
    vipOk &&
    freeOk &&
    Number(vip0.totalElements) > 0 &&
    Number(free0.totalElements) > 0 &&
    Number(vip0.totalElements) !== Number(free0.totalElements);

  report.ok = vipOk && freeOk && report.paginationIndependent;
} catch (err) {
  report.ok = false;
  report.error = String(err && err.message ? err.message : err);
  report.conclusions.push('Verification failed with error; do not claim live filter compatibility.');
} finally {
  await browser.close().catch(() => {});
  report.finishedAt = new Date().toISOString();
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  process.exit(report.ok ? 0 : 2);
}
