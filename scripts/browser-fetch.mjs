/**
 * Optional local browser fetch for pages that need JS / challenge cookies.
 * Used by Rond (rond.ir). Not available on typical cPanel shared hosting.
 *
 * Usage: node scripts/browser-fetch.mjs <url> [out.html] [diag.json]
 *
 * Exit codes:
 *   0 success (listing HTML written)
 *   2 usage / bad args
 *   10 network failure (DNS/TCP/TLS/connection timeout before usable document)
 *   11 navigation/lifecycle timeout after start
 *   12 HTTP error on main document
 *   13 challenge page observed
 *   14 loaded page without recognizable listings
 *   15 overall deadline exceeded
 *   20 unexpected error
 *
 * Proxy: set RONDINO_BROWSER_PROXY or HTTPS_PROXY/HTTP_PROXY (Chromium does not
 * inherit Windows IE proxy settings automatically).
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import {
  candidateUrls,
  classifyLoadedHtml,
  classifyNavError,
  hasVerifiedListings,
  looksLikeChallenge,
  normalizeUrl,
} from './browser-fetch-lib.mjs';

const url = process.argv[2];
const out = process.argv[3] || '';
const diagPath = process.argv[4] || (out ? out.replace(/\.html?$/i, '') + '.diag.json' : '');
if (!url) {
  console.error('Usage: node scripts/browser-fetch.mjs <url> [out.html] [diag.json]');
  process.exit(2);
}

const OVERALL_MS = Number(process.env.RONDINO_BROWSER_DEADLINE_MS || 120000);
const GOTO_MS = Number(process.env.RONDINO_BROWSER_GOTO_MS || 45000);
const startedAt = Date.now();

/** @type {Record<string, unknown>} */
const diag = {
  input_url: url,
  normalized_url: (() => {
    try {
      return normalizeUrl(url);
    } catch {
      return null;
    }
  })(),
  started_at: new Date().toISOString(),
  attempts: [],
  proxy_used: null,
  final_url: null,
  main_status: null,
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
      let s = String(v).trim();
      if (!/^[a-z]+:\/\//i.test(s)) {
        s = 'http://' + s;
      }
      return s;
    }
  }
  return null;
}

function writeDiag(extra = {}) {
  Object.assign(diag, extra, { finished_at: new Date().toISOString(), elapsed_ms: Date.now() - startedAt });
  if (!diagPath) {
    return;
  }
  try {
    fs.mkdirSync(path.dirname(diagPath), { recursive: true });
    const safe = { ...diag };
    if (typeof safe.proxy_used === 'string') {
      safe.proxy_used = safe.proxy_used.replace(/\/\/([^/@]+)@/, '//***@');
    }
    fs.writeFileSync(diagPath, JSON.stringify(safe, null, 2), 'utf8');
  } catch (err) {
    console.error('diag-write-failed: ' + (err.message || err));
  }
}

function writeFailureArtifact(html, label) {
  if (!out) {
    return;
  }
  try {
    const failPath = out.replace(/\.html?$/i, '') + `.fail-${label}.html`;
    fs.mkdirSync(path.dirname(failPath), { recursive: true });
    fs.writeFileSync(failPath, html || '', 'utf8');
  } catch {
    // ignore
  }
}

async function safeContent(page) {
  for (let i = 0; i < 8; i++) {
    try {
      return await page.content();
    } catch (err) {
      const msg = String(err && err.message ? err.message : err);
      if (!/navigating|changing the content|Execution context was destroyed|detached/i.test(msg)) {
        throw err;
      }
      await page.waitForTimeout(500);
    }
  }
  return '';
}

async function extractListingHtml(page) {
  return page.evaluate(() => {
    const tables = Array.from(document.querySelectorAll('table'));
    const withLinks = tables.find((t) => t.querySelector('a[href*="/Sims/"]'));
    if (withLinks && withLinks.querySelector('a[href*="/Sims/0912"]')) {
      return withLinks.outerHTML;
    }
    const desktop = document.querySelector('table.table-desktop');
    if (desktop && desktop.querySelector('a[href*="/Sims/"]')) {
      return desktop.outerHTML;
    }
    return document.documentElement.outerHTML;
  });
}

async function waitForListings(page, label) {
  const deadline = Date.now() + Math.min(55000, remainingMs());
  let i = 0;
  while (Date.now() < deadline) {
    try {
      await page.waitForSelector('a[href*="/Sims/0912"]', { timeout: 2500 });
      const html = await safeContent(page);
      if (hasVerifiedListings(html) && !looksLikeChallenge(html)) {
        console.error(`${label}[${i}] listings-ready len=${html.length}`);
        return { ready: true, html, challenge: false };
      }
    } catch {
      // keep polling
    }
    const body = await safeContent(page);
    const challenge = looksLikeChallenge(body);
    const listings = hasVerifiedListings(body);
    if (i % 3 === 0) {
      console.error(`${label}[${i}] len=${body.length} challenge=${challenge} listings=${listings}`);
    }
    if (!challenge && listings) {
      return { ready: true, html: body, challenge: false };
    }
    i += 1;
    await page.waitForTimeout(1000);
  }
  const html = await safeContent(page);
  return { ready: false, html, challenge: looksLikeChallenge(html) };
}

async function runWithProxy(proxyServer) {
  const launchOpts = { headless: true };
  const contextOpts = {
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    locale: 'fa-IR',
    viewport: { width: 1400, height: 900 },
  };
  if (proxyServer) {
    contextOpts.proxy = { server: proxyServer };
    diag.proxy_used = proxyServer;
    console.error(`proxy=${proxyServer.replace(/\/\/([^/@]+)@/, '//***@')}`);
  } else {
    diag.proxy_used = null;
    console.error('proxy=none');
  }

  const browser = await chromium.launch(launchOpts);
  const context = await browser.newContext(contextOpts);
  const page = await context.newPage();

  let mainDoc = null;
  page.on('response', (res) => {
    try {
      if (res.request().resourceType() === 'document') {
        mainDoc = { status: res.status(), url: res.url(), ok: res.ok() };
      }
    } catch {
      // ignore
    }
  });

  let lastClass = null;
  let lastHtml = '';
  let gotDocument = false;

  try {
    const targets = candidateUrls(url);
    let attemptNo = 0;
    for (const target of targets) {
      if (remainingMs() < 3000) {
        lastClass = { code: 15, kind: 'deadline_exceeded', message: 'overall deadline exceeded' };
        break;
      }
      const backoffMs = Math.min(8000, 500 * 2 ** attemptNo);
      if (attemptNo > 0) {
        console.error(`backoff ${backoffMs}ms before attempt=${attemptNo}`);
        await page.waitForTimeout(backoffMs);
      }
      attemptNo += 1;
      console.error(`goto attempt=${attemptNo - 1} ${target}`);
      const attempt = { target, started_at: new Date().toISOString() };
      diag.attempts.push(attempt);

      try {
        const resp = await page.goto(target, {
          waitUntil: 'domcontentloaded',
          timeout: Math.min(GOTO_MS, remainingMs()),
        });
        gotDocument = true;
        const status = resp ? resp.status() : (mainDoc && mainDoc.status) || 0;
        const finalUrl = page.url();
        attempt.status = status;
        attempt.final_url = finalUrl;
        diag.main_status = status;
        diag.final_url = finalUrl;
        if (status >= 400) {
          lastHtml = await safeContent(page);
          lastClass = {
            code: 12,
            kind: 'http_error',
            message: `main document HTTP ${status} at ${finalUrl}`,
          };
          attempt.classification = lastClass.kind;
          writeFailureArtifact(lastHtml, `http-${status}`);
          continue;
        }

        const wait = await waitForListings(page, `a${attemptNo - 1}`);
        const extracted = await extractListingHtml(page);
        lastHtml = hasVerifiedListings(extracted) ? extracted : wait.html || extracted;

        if (wait.ready && hasVerifiedListings(lastHtml)) {
          if (out) {
            fs.mkdirSync(path.dirname(out), { recursive: true });
            fs.writeFileSync(out, lastHtml, 'utf8');
          } else {
            process.stdout.write(lastHtml);
          }
          diag.classification = 'success';
          diag.error = null;
          writeDiag({ listings: true, error: null });
          return { code: 0 };
        }

        lastClass = classifyLoadedHtml(lastHtml, true);
        attempt.classification = lastClass.kind;
      } catch (err) {
        const classified = classifyNavError(err);
        lastClass = classified;
        attempt.classification = classified.kind;
        attempt.error = classified.message;
        console.error(`goto error (${classified.kind}): ${classified.message}`);
      }
    }

    if (!lastClass) {
      lastClass = classifyLoadedHtml(lastHtml, gotDocument);
    }
    if (!gotDocument && lastClass.code !== 10 && lastClass.code !== 15) {
      lastClass = { code: 10, kind: 'connection_failure', message: lastClass.message };
    }

    writeFailureArtifact(lastHtml, lastClass.kind);
    if (out && fs.existsSync(out)) {
      try {
        fs.unlinkSync(out);
      } catch {
        // ignore
      }
    }
    diag.classification = lastClass.kind;
    diag.error = lastClass.message;
    writeDiag();
    console.error(`browser-fetch: ${lastClass.kind}: ${lastClass.message}`);
    return { code: lastClass.code };
  } finally {
    await context.close().catch(() => {});
    await browser.close().catch(() => {});
  }
}

let exitCode = 20;
try {
  const explicitProxy = resolveProxyServer();
  let result = await runWithProxy(explicitProxy);

  if (result.code === 10 || result.code === 11) {
    const fallback = process.env.RONDINO_BROWSER_FALLBACK_PROXY;
    if (fallback && String(fallback).trim()) {
      let raw = String(fallback).trim();
      const variants = [];
      if (/^[a-z]+:\/\//i.test(raw)) {
        variants.push(raw);
        // Also try alternate scheme for host:port proxies commonly used as SOCKS.
        try {
          const u = new URL(raw);
          if (u.protocol === 'http:' || u.protocol === 'https:') {
            variants.push(`socks5://${u.host}`);
          } else if (u.protocol.startsWith('socks')) {
            variants.push(`http://${u.host}`);
          }
        } catch {
          // ignore
        }
      } else {
        variants.push('http://' + raw, 'socks5://' + raw);
      }
      for (const s of variants) {
        if (s === explicitProxy) {
          continue;
        }
        console.error(`retrying with fallback proxy after connection/navigation failure: ${s}`);
        result = await runWithProxy(s);
        if (result.code === 0) {
          break;
        }
      }
    }
  }
  exitCode = result.code;
} catch (err) {
  diag.classification = 'unexpected_error';
  diag.error = String(err && err.message ? err.message : err);
  writeDiag();
  console.error('browser-fetch unexpected: ' + diag.error);
  exitCode = 20;
}

process.exitCode = exitCode;
