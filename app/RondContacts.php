<?php

declare(strict_types=1);

/** Conservative enrichment: never assign global/footer phones to an advertisement. */
final class RondContacts
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rond_contact_checks (
            offer_id INT UNSIGNED NOT NULL PRIMARY KEY,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            checked_at DATETIME NULL, retry_at DATETIME NULL, error VARCHAR(500) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Only accept telephone values explicitly associated with this ad's seller in structured data.
     * Unrecognized layouts remain unresolved for later selector verification, never guessed.
     * @return list<string>
     */
    public static function parse(string $html, string $externalId, string $sim): array
    {
        $dom = Dom::fromHtml($html);
        $phones = [];
        $walk = function (mixed $value) use (&$walk, &$phones, $externalId, $sim): void {
            if (!is_array($value)) return;
            $identity = (string) ($value['sku'] ?? $value['productID'] ?? $value['identifier'] ?? '');
            $number = Digits::only(Digits::normalize((string) ($value['name'] ?? '')));
            if ($identity === $externalId && str_contains($number, $sim)) {
                $offers = $value['offers'] ?? [];
                if (isset($offers['seller'])) $offers = [$offers];
                foreach ($offers as $offer) {
                    $telephone = is_array($offer) ? ($offer['seller']['telephone'] ?? []) : [];
                    foreach ((array) $telephone as $raw) {
                        if (!is_string($raw)) continue;
                        $phone = Digits::only(Digits::normalize($raw));
                        if (str_starts_with($phone, '98') && strlen($phone) === 12) $phone = '0' . substr($phone, 2);
                        if (preg_match('/^0\d{10}$/', $phone) && $phone !== $sim) $phones[$phone] = true;
                    }
                }
            }
            foreach ($value as $child) if (is_array($child)) $walk($child);
        };
        foreach ($dom->xpath('//script[@type="application/ld+json"]') as $script) {
            $walk(json_decode($script->textContent, true));
        }
        return array_map('strval', array_keys($phones));
    }

    /** One bounded item, protected from concurrent workers. Failed/unknown pages have a cooldown. */
    public static function advance(): bool
    {
        $pdo = Database::pdo();
        self::ensureSchema($pdo);
        $lock = $pdo->query("SELECT GET_LOCK('rondino-contacts',0)")->fetchColumn();
        if ((int) $lock !== 1) return false;
        try {
            // Prefer never-checked offers. Skip offers that already have verified seller phones.
            // Revisit unresolved/failed only after retry_at; optional expiry can re-queue verified rows.
            $recheckDays = max(0, (int) config('rondino_contact_recheck_days', 0));
            $recheckSql = $recheckDays > 0
                ? "OR (c.status='verified' AND c.checked_at IS NOT NULL AND c.checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$recheckDays} DAY))"
                : '';
            $row = $pdo->query("SELECT o.id,o.external_id,o.canonical_url,n.number, COALESCE(c.attempts,0) attempts
                FROM offers o JOIN sources s ON s.id=o.source_id JOIN sim_numbers n ON n.id=o.sim_number_id
                LEFT JOIN rond_contact_checks c ON c.offer_id=o.id
                WHERE s.slug='rond-ir' AND s.is_enabled=1
                AND (
                    (NOT EXISTS (SELECT 1 FROM offer_contacts oc WHERE oc.offer_id=o.id)
                     AND (c.offer_id IS NULL OR (c.retry_at <= UTC_TIMESTAMP() AND c.attempts < 5)))
                    {$recheckSql}
                )
                ORDER BY CASE WHEN c.offer_id IS NULL THEN 0 WHEN c.status='failed' THEN 1 ELSE 2 END, o.id
                LIMIT 1")->fetch();
            if (!$row) return false;
            $adapter = Registry::get('rond-ir');
            $res = $adapter->fetchDetail((string) $row['canonical_url']);
            $phones = $res['ok'] ? self::parse($res['body'], (string) $row['external_id'], $row['number']) : [];
            $status = !$res['ok'] ? 'failed' : ($phones ? 'verified' : 'unresolved');
            $error = !$res['ok'] ? (string) $res['error'] : ($phones ? null : 'Seller/ad association not verified in detail HTML');
            $attempt = (int) $row['attempts'] + 1;
            $retry = gmdate('Y-m-d H:i:s', time() + ($status === 'unresolved' ? 86400 : min(3600, 30 * 2 ** $attempt)));
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare("INSERT INTO offer_contacts (offer_id,phone,phone_type,scope,label)
                    VALUES (?,?,?,'seller','فروشنده') ON DUPLICATE KEY UPDATE scope='seller'");
                foreach ($phones as $phone) $insert->execute([$row['id'], $phone, str_starts_with($phone, '09') ? 'mobile' : 'landline']);
                $pdo->prepare("INSERT INTO rond_contact_checks (offer_id,status,attempts,checked_at,retry_at,error)
                    VALUES (?,?,?,UTC_TIMESTAMP(),?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),
                    attempts=VALUES(attempts),checked_at=VALUES(checked_at),retry_at=VALUES(retry_at),error=VALUES(error)")
                    ->execute([$row['id'], $status, $attempt, $retry, $error !== null ? mb_substr($error,0,500) : null]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            return true;
        } finally { $pdo->query("SELECT RELEASE_LOCK('rondino-contacts')"); }
    }
}
