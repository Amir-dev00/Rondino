import {
  candidateUrls,
  classifyLoadedHtml,
  classifyNavError,
  hasVerifiedListings,
  looksLikeChallenge,
  normalizeProxyServer,
  normalizeUrl,
} from './browser-fetch-lib.mjs';

let failed = 0;
function assert(label, cond) {
  if (!cond) {
    console.log('FAIL ' + label);
    failed += 1;
  } else {
    console.log('OK   ' + label);
  }
}

assert(
  'duplicate root URLs collapse',
  JSON.stringify(candidateUrls('https://rond.ir')) === JSON.stringify(['https://rond.ir/'])
);
assert('normalize trailing slash root', normalizeUrl('https://rond.ir') === 'https://rond.ir/');
assert('normalize keeps path', normalizeUrl('https://rond.ir/Sims/09121111111/') === 'https://rond.ir/Sims/09121111111');
assert('socks5 proxy scheme preserved', normalizeProxyServer('socks5://127.0.0.1:10808') === 'socks5://127.0.0.1:10808');
assert('bare proxy host gets http', normalizeProxyServer('127.0.0.1:10808') === 'http://127.0.0.1:10808');

assert(
  'connection timeout is network, not challenge',
  classifyNavError(new Error('page.goto: net::ERR_CONNECTION_TIMED_OUT at https://rond.ir/')).kind ===
    'connection_failure'
);
assert(
  'ERR_TIMED_OUT is network, not challenge',
  classifyNavError(new Error('page.goto: net::ERR_TIMED_OUT at https://rond.ir/')).kind ===
    'connection_failure'
);
assert(
  'dns failure classified',
  classifyNavError(new Error('net::ERR_NAME_NOT_RESOLVED')).kind === 'dns_failure'
);

const challengeHtml = '<html><body>Transferring to the website <script>__arcsjs</script></body></html>';
assert('challenge observed', looksLikeChallenge(challengeHtml));
assert(
  'challenge classification',
  classifyLoadedHtml(challengeHtml, true).kind === 'challenge_observed'
);

const headerOnly =
  '<html><body><table><thead><tr><th>شماره</th><th>قیمت (تومان)</th></tr></thead><tbody></tbody></table></body></html>';
assert('header alone is not verified listings', !hasVerifiedListings(headerOnly));
assert('header alone => no_listings', classifyLoadedHtml(headerOnly, true).kind === 'no_listings');

const valid =
  '<table><tr><th>شماره</th><th>قیمت (تومان)</th></tr>' +
  '<tr><td>0912 111 11 11</td><td>1000</td><td><a href="/Sims/09121111111">تماس</a></td></tr></table>';
assert('valid listing html', hasVerifiedListings(valid));
assert('valid => success', classifyLoadedHtml(valid, true).kind === 'success');

assert(
  'no document => connection_failure',
  classifyLoadedHtml('', false).kind === 'connection_failure'
);

if (failed > 0) {
  console.log(`\n${failed} failed`);
  process.exit(1);
}
console.log('\nall passed');
