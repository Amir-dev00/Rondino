/**
 * Optional local browser fetch for pages that need JS / challenge cookies.
 * Used by Rond (rond.ir). Not available on typical cPanel shared hosting.
 *
 * Usage: node scripts/browser-fetch.mjs <url> [out.html]
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const url = process.argv[2];
const out = process.argv[3] || '';
if (!url) {
  console.error('Usage: node scripts/browser-fetch.mjs <url> [out.html]');
  process.exit(2);
}

function looksLikeChallenge(html) {
  return /Transferring to the website|__arcsjs/i.test(html);
}

function hasListings(html) {
  return html.includes('/Sims/') || /<th[^>]*>\s*شماره\s*<\/th>/u.test(html);
}

async function safeContent(page) {
  for (let i = 0; i < 12; i++) {
    try {
      return await page.content();
    } catch (err) {
      const msg = String(err && err.message ? err.message : err);
      if (!/navigating|changing the content|Execution context was destroyed|detached/i.test(msg)) {
        throw err;
      }
      await page.waitForTimeout(800);
    }
  }
  return '';
}

async function extractHtml(page) {
  return page.evaluate(() => {
    const table =
      document.querySelector('table.table-desktop') ||
      document.querySelector('table.table') ||
      Array.from(document.querySelectorAll('table')).find((t) => t.querySelector('a[href*="/Sims/"]'));
    if (table && table.querySelector('a[href*="/Sims/"]')) {
      return table.outerHTML;
    }
    return document.documentElement.outerHTML;
  });
}

async function waitForListings(page, label, maxLoops = 40) {
  for (let i = 0; i < maxLoops; i++) {
    await page.waitForTimeout(1500);
    const body = await safeContent(page);
    if (!body) {
      console.error(`${label}[${i}] empty-content`);
      continue;
    }
    const challenge = looksLikeChallenge(body);
    const listings = hasListings(body);
    if (i % 4 === 0 || listings || (!challenge && i < 3)) {
      console.error(`${label}[${i}] len=${body.length} challenge=${challenge} listings=${listings}`);
    }
    if (challenge) {
      continue;
    }
    if (listings) {
      return true;
    }
    if (i > 0 && i % 6 === 0) {
      try {
        await page.mouse.wheel(0, 1400);
      } catch {}
    }
  }
  return false;
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  userAgent:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
  locale: 'fa-IR',
  viewport: { width: 1400, height: 900 },
});
const page = await context.newPage();

try {
  // Homepage SSR table is the verified listing surface; /buy/simcard often 404s as SPA shell.
  const candidates = [];
  for (const u of [url, 'https://rond.ir/', 'https://rond.ir']) {
    if (u && !candidates.includes(u)) {
      candidates.push(u);
    }
  }

  let ready = false;
  for (let attempt = 0; attempt < candidates.length; attempt++) {
    const target = candidates[attempt];
    console.error(`goto attempt=${attempt} ${target}`);
    try {
      await page.goto(target, { waitUntil: 'load', timeout: 90000 });
    } catch (err) {
      console.error(`goto error: ${err.message || err}`);
      continue;
    }
    ready = await waitForListings(page, `a${attempt}`, attempt === 0 ? 25 : 20);
    if (ready) {
      break;
    }
    // Cookie jar should now hold ArvanCloud clearance; hard refresh once.
    try {
      console.error('soft re-navigation after shell');
      await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 90000 });
      ready = await waitForListings(page, `retry${attempt}`, 20);
      if (ready) {
        break;
      }
    } catch (err) {
      console.error(`retry error: ${err.message || err}`);
    }
  }

  let html = '';
  try {
    html = await extractHtml(page);
  } catch {
    html = await safeContent(page);
  }

  if (out) {
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, html, 'utf8');
  } else {
    process.stdout.write(html);
  }

  if (!ready || looksLikeChallenge(html) || !hasListings(html)) {
    console.error('browser-fetch: no listing table after wait (challenge or SPA shell)');
    process.exit(1);
  }
} finally {
  await browser.close();
}
