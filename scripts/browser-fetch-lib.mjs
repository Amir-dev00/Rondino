/** Pure helpers for browser-fetch (unit-tested). */

/** Ensure proxy URLs keep schemes like socks5:// (digits allowed in scheme). */
export function normalizeProxyServer(raw) {
  let s = String(raw || '').trim();
  if (!s) return null;
  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) {
    s = 'http://' + s;
  }
  return s;
}

export function normalizeUrl(raw) {
  const u = new URL(raw);
  u.hash = '';
  let p = u.pathname || '/';
  if (p !== '/' && p.endsWith('/')) {
    p = p.replace(/\/+$/, '');
  }
  if (p === '') {
    p = '/';
  }
  u.pathname = p;
  if (u.pathname === '/') {
    return `${u.protocol}//${u.host}/`;
  }
  return `${u.protocol}//${u.host}${u.pathname}${u.search}`;
}

export function candidateUrls(primary) {
  const list = [];
  const push = (u) => {
    if (!u) return;
    try {
      const n = normalizeUrl(u);
      if (!list.includes(n)) {
        list.push(n);
      }
    } catch {
      // ignore
    }
  };
  push(primary);
  push('https://rond.ir/');
  return list;
}

export function looksLikeChallenge(html) {
  return /Transferring to the website|__arcsjs/i.test(html || '');
}

export function hasListingAnchors(html) {
  return /<a[^>]+href=["'][^"']*\/Sims\/0912\d{7}/i.test(html || '');
}

export function hasVerifiedListings(html) {
  return hasListingAnchors(html);
}

export function classifyNavError(err) {
  const msg = String(err && err.message ? err.message : err);
  if (/ERR_NAME_NOT_RESOLVED|ENOTFOUND/i.test(msg)) {
    return { code: 10, kind: 'dns_failure', message: msg };
  }
  if (/ERR_CONNECTION_TIMED_OUT|ERR_TIMED_OUT|ETIMEDOUT|ERR_CONNECTION_REFUSED|ERR_ADDRESS_UNREACHABLE|ERR_NETWORK_CHANGED/i.test(msg)) {
    return { code: 10, kind: 'connection_failure', message: msg };
  }
  if (/ERR_SSL|ERR_CERT|SSL|TLS/i.test(msg)) {
    return { code: 10, kind: 'tls_failure', message: msg };
  }
  if (/Timeout|timed out/i.test(msg)) {
    return { code: 11, kind: 'navigation_timeout', message: msg };
  }
  return { code: 20, kind: 'unexpected_navigation_error', message: msg };
}

export function classifyLoadedHtml(html, gotDocument) {
  if (!gotDocument) {
    return { code: 10, kind: 'connection_failure', message: 'no document loaded' };
  }
  if (looksLikeChallenge(html)) {
    return { code: 13, kind: 'challenge_observed', message: 'challenge content observed' };
  }
  if (!hasVerifiedListings(html)) {
    return { code: 14, kind: 'no_listings', message: 'document loaded but listing anchors were not found' };
  }
  return { code: 0, kind: 'success', message: 'ok' };
}
