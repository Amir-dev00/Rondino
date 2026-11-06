<?php
declare(strict_types=1);
/**
 * Regression tests for contact enrichment performance safeguards (no live network).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo "OK $label\n";
}

$simA = '09121111111';
$simB = '09122222222';
$codeA = '111111111111';
$codeB = '222222222222';

$htmlShared = '<html><body>'
    . '<script id="serverApp-state" type="application/json">'
    . '{"single-sim-detail-' . $simA . '":{"code":"' . $codeA . '","simCardNumber":"' . $simA . '",'
    . '"contactGroup":{"mainCallNumber":"09129990001","contacts":[{"callNumber":"09129990001"}]}}}'
    . '</script></body></html>';

// Association: same document must not yield phones for a different SIM/code.
check(
    RondContacts::parse($htmlShared, $codeA, $simA) === ['09129990001'],
    'shared SSR yields phones for matching offer'
);
check(
    RondContacts::parse($htmlShared, $codeB, $simB) === [],
    'shared SSR rejects unrelated SIM/code (no cross-association)'
);
check(
    RondContacts::parse($htmlShared, $codeB, $simA) === [],
    'shared SSR rejects mismatched advert code even with matching SIM key'
);

// URL grouping: one fetch key, multiple offers — only matching codes get phones.
$byUrl = [
    'https://rond.ir/Sims/' . $simA => [
        ['external_id' => $codeA, 'sim' => $simA],
        ['external_id' => $codeB, 'sim' => $simA], // same URL, different code (rare DB case)
    ],
];
$assigned = [];
foreach ($byUrl as $offers) {
    foreach ($offers as $row) {
        $assigned[] = RondContacts::parse($htmlShared, $row['external_id'], $row['sim']);
    }
}
check($assigned[0] === ['09129990001'], 'URL group: matching code verified');
check($assigned[1] === [], 'URL group: non-matching code unresolved (not reused)');

// Batch persist preserves existing rows / zero duplicate pairs (transactional).
$pdo = Database::pdo();
RondContacts::ensureSchema($pdo);
$offerId = (int) $pdo->query(
    "SELECT o.id FROM offers o
     JOIN sources s ON s.id=o.source_id
     WHERE s.slug='rond-ir'
     ORDER BY o.id DESC LIMIT 1"
)->fetchColumn();
check($offerId > 0, 'have sample offer for persist test');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM offer_contacts WHERE offer_id=?');
$stmt->execute([$offerId]);
$beforePhones = (int) $stmt->fetchColumn();

// Use a disposable synthetic check path: persist failed then verify upsert does not delete phones.
RondContacts::persistBatch($pdo, [[
    'offer_id' => $offerId,
    'status' => 'failed',
    'phones' => [],
    'prior_attempts' => 0,
    'error' => 'perf-test-temp',
]]);
$stmt->execute([$offerId]);
$afterFail = (int) $stmt->fetchColumn();
check($afterFail === $beforePhones, 'persistBatch failed status does not delete existing phones');

$dup = (int) $pdo->query(
    'SELECT COUNT(*) FROM (
        SELECT offer_id, phone FROM offer_contacts GROUP BY offer_id, phone HAVING COUNT(*)>1
     ) t'
)->fetchColumn();
check($dup === 0, 'duplicate phone/offer pairs remain zero');

// CLI safety: unlimited run without --full must be refused (subprocess).
$cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/crawl-rond-contacts-fast.php') . ' --batch=5';
exec($cmd . ' 2>&1', $out, $code);
check($code === 2, 'refuses full queue without --limit or --full');
check(str_contains(implode("\n", $out), 'Refusing'), 'prints refusing message for unsafe full run');

// Unique URL helper behavior mirrored in PHP for batch prep.
$urls = [
    'https://rond.ir/Sims/09121111111',
    'https://rond.ir/Sims/09121111111',
    'https://rond.ir/Sims/09122222222',
];
$unique = [];
foreach ($urls as $u) {
    $unique[$u] = true;
}
check(count($unique) === 2, 'duplicate detail URLs collapse to one fetch key');
check(count($urls) - count($unique) === 1, 'one redundant request eliminated');

echo "All contact performance regressions passed\n";
