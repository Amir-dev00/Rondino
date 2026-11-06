<?php
declare(strict_types=1);
/**
 * Controlled sample: re-check N unresolved listings with the CURRENT parser.
 * Does not start a long-running worker. Preserves existing offer_contacts rows
 * (ON DUPLICATE KEY UPDATE scope only). Overrides cooldown for the sampled IDs only.
 *
 * Usage: php scripts/sample-contact-enrich.php [limit=30]
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$limit = max(1, min(50, (int) ($argv[1] ?? 30)));
$pdo = Database::pdo();
RondContacts::ensureSchema($pdo);

$proxy = trim((string) (getenv('RONDINO_BROWSER_PROXY') ?: config('rondino_browser_proxy', '')));
if ($proxy !== '') {
    putenv('RONDINO_BROWSER_PROXY=' . $proxy);
    $_ENV['RONDINO_BROWSER_PROXY'] = $proxy;
}

$rows = $pdo->query(
    "SELECT o.id,o.external_id,o.canonical_url,n.number AS sim, c.status AS prior_status, c.error AS prior_error
     FROM rond_contact_checks c
     JOIN offers o ON o.id=c.offer_id
     JOIN sources s ON s.id=o.source_id
     JOIN sim_numbers n ON n.id=o.sim_number_id
     WHERE s.slug='rond-ir' AND c.status='unresolved'
     ORDER BY c.checked_at ASC
     LIMIT {$limit}"
)->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    fwrite(STDERR, "No unresolved rows to sample\n");
    exit(2);
}

$adapter = Registry::get('rond-ir');
$report = [
    'started_at' => gmdate('c'),
    'sample_size' => count($rows),
    'verified' => 0,
    'unresolved' => 0,
    'failed' => 0,
    'items' => [],
];

foreach ($rows as $row) {
    $res = $adapter->fetchDetail((string) $row['canonical_url']);
    $html = (string) ($res['body'] ?? '');
    $phones = $res['ok'] ? RondContacts::parse($html, (string) $row['external_id'], (string) $row['sim']) : [];
    $status = !$res['ok'] ? 'failed' : ($phones ? 'verified' : 'unresolved');
    $error = !$res['ok']
        ? (string) $res['error']
        : ($phones ? null : 'Seller/ad association not verified in detail HTML');

    $simInPage = str_contains(
        Digits::only(Digits::normalize(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
        Digits::only(Digits::normalize((string) $row['sim']))
    );
    $sellerBlock = (bool) preg_match('/اطلاعات\s*تماس\s*فروشنده/u', $html);
    $telNearSeller = (bool) preg_match(
        '/اطلاعات\s*تماس\s*فروشنده[\s\S]{0,240}?href=["\']tel:/u',
        $html
    );

    $attempt = 1 + (int) $pdo->query(
        'SELECT attempts FROM rond_contact_checks WHERE offer_id=' . (int) $row['id']
    )->fetchColumn();
    $retry = gmdate('Y-m-d H:i:s', time() + ($status === 'unresolved' ? 86400 : min(3600, 30 * 2 ** $attempt)));

    $pdo->beginTransaction();
    try {
        $before = (int) $pdo->query(
            'SELECT COUNT(*) FROM offer_contacts WHERE offer_id=' . (int) $row['id']
        )->fetchColumn();
        $insert = $pdo->prepare(
            "INSERT INTO offer_contacts (offer_id,phone,phone_type,scope,label)
             VALUES (?,?,?,'seller','فروشنده') ON DUPLICATE KEY UPDATE scope='seller'"
        );
        foreach ($phones as $phone) {
            $insert->execute([
                $row['id'],
                $phone,
                str_starts_with($phone, '09') ? 'mobile' : 'landline',
            ]);
        }
        $pdo->prepare(
            "UPDATE rond_contact_checks
             SET status=?, attempts=?, checked_at=UTC_TIMESTAMP(), retry_at=?, error=?
             WHERE offer_id=?"
        )->execute([
            $status,
            $attempt,
            $retry,
            $error !== null ? mb_substr($error, 0, 500) : null,
            $row['id'],
        ]);
        $pdo->commit();
        $after = (int) $pdo->query(
            'SELECT COUNT(*) FROM offer_contacts WHERE offer_id=' . (int) $row['id']
        )->fetchColumn();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $report[$status]++;
    $report['items'][] = [
        'offer_id' => (int) $row['id'],
        'external_id' => $row['external_id'],
        'sim' => $row['sim'],
        'status' => $status,
        'phones' => $phones,
        'sim_in_page' => $simInPage,
        'seller_block_present' => $sellerBlock,
        'tel_near_seller' => $telNearSeller,
        'contacts_before' => $before,
        'contacts_after' => $after,
        'fetch_ok' => (bool) $res['ok'],
        'error' => $error,
    ];
    echo sprintf(
        "[%s] offer=%d sim=%s status=%s phones=%s\n",
        gmdate('H:i:s'),
        $row['id'],
        $row['sim'],
        $status,
        $phones === [] ? '-' : implode(',', $phones)
    );
    sleep(1);
}

$report['finished_at'] = gmdate('c');
$report['success_rate'] = $report['sample_size'] > 0
    ? round($report['verified'] / $report['sample_size'], 4)
    : 0;
$out = dirname(__DIR__) . '/storage/logs/sample-contact-enrich.json';
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode([
    'sample_size' => $report['sample_size'],
    'verified' => $report['verified'],
    'unresolved' => $report['unresolved'],
    'failed' => $report['failed'],
    'success_rate' => $report['success_rate'],
    'report' => $out,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($report['verified'] > 0 ? 0 : 2);
