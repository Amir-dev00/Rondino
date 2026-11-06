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

    /**
     * Accept telephones only when associated with this listing's seller.
     * Priority: Angular SSR transfer-state contactGroup → JSON-LD Product → visible seller card.
     * @return list<string>
     */
    public static function parse(string $html, string $externalId, string $sim): array
    {
        $phones = [];
        $accept = static function (string $raw) use (&$phones, $sim): void {
            $phone = Digits::only(Digits::normalize($raw));
            if (str_starts_with($phone, '98') && strlen($phone) === 12) {
                $phone = '0' . substr($phone, 2);
            }
            if (preg_match('/^0\d{10}$/', $phone) && $phone !== $sim) {
                $phones[$phone] = true;
            }
        };

        foreach (self::parseNgStateContactGroup($html, $externalId, $sim) as $raw) {
            $accept($raw);
        }
        if ($phones !== []) {
            return array_map('strval', array_keys($phones));
        }

        $dom = Dom::fromHtml($html);
        $walk = function (mixed $value) use (&$walk, $accept, $externalId, $sim): void {
            if (!is_array($value)) {
                return;
            }
            $identity = (string) ($value['sku'] ?? $value['productID'] ?? $value['identifier'] ?? '');
            $number = Digits::only(Digits::normalize((string) ($value['name'] ?? '')));
            if ($identity === $externalId && str_contains($number, $sim)) {
                $offers = $value['offers'] ?? [];
                if (isset($offers['seller'])) {
                    $offers = [$offers];
                }
                foreach ($offers as $offer) {
                    $telephone = is_array($offer) ? ($offer['seller']['telephone'] ?? []) : [];
                    foreach ((array) $telephone as $raw) {
                        if (is_string($raw)) {
                            $accept($raw);
                        }
                    }
                }
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        foreach ($dom->xpath('//script[@type="application/ld+json"]') as $script) {
            $walk(json_decode($script->textContent, true));
        }
        if ($phones !== []) {
            return array_map('strval', array_keys($phones));
        }

        // Visible seller card fallback (scripts/styles stripped — label strings appear in bundles).
        $visibleHtml = preg_replace('#<script\b[^>]*>[\s\S]*?</script>#iu', ' ', $html) ?? $html;
        $visibleHtml = preg_replace('#<style\b[^>]*>[\s\S]*?</style>#iu', ' ', $visibleHtml) ?? $visibleHtml;
        $visibleText = html_entity_decode(strip_tags($visibleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visibleText = preg_replace('/\s+/u', ' ', $visibleText) ?? $visibleText;

        $simDigits = Digits::only(Digits::normalize($sim));
        $plainDigits = Digits::only(Digits::normalize($visibleText));
        if ($simDigits === '' || !str_contains($plainDigits, $simDigits)) {
            return [];
        }

        $region = '';
        if (preg_match(
            '/اطلاعات\s*تماس\s*فروشنده([\s\S]{0,6000}?)(?:مشخصات\s*آگهی|<\/app-seller-shop-info-card>)/u',
            $visibleHtml,
            $rm
        )) {
            $region = $rm[1];
        }
        if ($region !== '') {
            if (preg_match_all(
                '/contact-button-advert[^>]{0,160}href=["\']tel:([^"\']+)["\']/iu',
                $region,
                $tm
            )) {
                foreach ($tm[1] as $raw) {
                    $accept(urldecode((string) $raw));
                }
            }
            if ($phones === [] && preg_match_all('/href=["\']tel:([^"\']+)["\']/iu', $region, $tm2)) {
                foreach ($tm2[1] as $raw) {
                    $accept(urldecode((string) $raw));
                }
            }
        }
        if ($phones === [] && preg_match(
            '/اطلاعات\s*تماس\s*فروشنده[\s\S]{0,400}?href=["\']tel:([^"\']+)["\']/u',
            $visibleHtml,
            $m
        )) {
            $accept(urldecode($m[1]));
        }
        if ($phones === [] && preg_match(
            '/اطلاعات\s*تماس\s*فروشنده\s*((?:\d[\d\s-]{8,18}\d))/u',
            $visibleText,
            $m2
        )) {
            $accept($m2[1]);
        }
        return array_map('strval', array_keys($phones));
    }

    /**
     * Public SSR transfer-state on Sims detail pages embeds contactGroup with call numbers.
     * Observed key: single-sim-detail-{sim}. Requires simCardNumber match; advert code match
     * when external_id is not the SIM itself.
     * @return list<string>
     */
    public static function parseNgStateContactGroup(string $html, string $externalId, string $sim): array
    {
        if (!preg_match(
            '/id=["\'](?:serverApp-state|ng-state)["\'][^>]*>([^<]*)</i',
            $html,
            $m
        )) {
            return [];
        }
        $raw = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = str_replace(['&q;', '&a;', '&s;', '&l;', '&g;'], ['"', '&', "'", '<', '>'], $raw);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [];
        }
        $simDigits = Digits::only(Digits::normalize($sim));
        $key = 'single-sim-detail-' . $sim;
        $detail = $json[$key] ?? null;
        if (!is_array($detail)) {
            foreach ($json as $k => $v) {
                if (is_array($v) && is_string($k) && str_contains($k, $simDigits)) {
                    $detail = $v;
                    break;
                }
            }
        }
        if (!is_array($detail)) {
            return [];
        }
        $detailSim = Digits::only(Digits::normalize((string) ($detail['simCardNumber'] ?? '')));
        if ($detailSim === '' || $detailSim !== $simDigits) {
            return [];
        }
        $code = (string) ($detail['code'] ?? '');
        // When we store a real advert code, require it; legacy rows may use SIM as external_id.
        if ($externalId !== '' && $externalId !== $sim && $code !== '' && $code !== $externalId) {
            return [];
        }
        $out = [];
        $cg = $detail['contactGroup'] ?? null;
        if (!is_array($cg)) {
            return [];
        }
        if (isset($cg['mainCallNumber']) && is_string($cg['mainCallNumber'])) {
            $out[] = $cg['mainCallNumber'];
        }
        foreach ($cg['contacts'] ?? [] as $c) {
            if (is_array($c) && isset($c['callNumber']) && is_string($c['callNumber'])) {
                $out[] = $c['callNumber'];
            }
        }
        return $out;
    }

    /** One bounded item, protected from concurrent workers. Failed/unknown pages have a cooldown. */
    public static function advance(): bool
    {
        $pdo = Database::pdo();
        self::ensureSchema($pdo);
        $lock = $pdo->query("SELECT GET_LOCK('rondino-contacts',0)")->fetchColumn();
        if ((int) $lock !== 1) {
            return false;
        }
        try {
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
            if (!$row) {
                return false;
            }
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
                foreach ($phones as $phone) {
                    $insert->execute([$row['id'], $phone, str_starts_with($phone, '09') ? 'mobile' : 'landline']);
                }
                $pdo->prepare("INSERT INTO rond_contact_checks (offer_id,status,attempts,checked_at,retry_at,error)
                    VALUES (?,?,?,UTC_TIMESTAMP(),?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),
                    attempts=VALUES(attempts),checked_at=VALUES(checked_at),retry_at=VALUES(retry_at),error=VALUES(error)")
                    ->execute([$row['id'], $status, $attempt, $retry, $error !== null ? mb_substr($error, 0, 500) : null]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return true;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('rondino-contacts')");
        }
    }

    /**
     * Persist phones for one offer (used by batch/session workers). Preserves existing rows.
     * @param list<string> $phones
     */
    public static function persistResult(
        PDO $pdo,
        int $offerId,
        string $status,
        array $phones,
        int $priorAttempts,
        ?string $error
    ): void {
        self::persistBatch($pdo, [[
            'offer_id' => $offerId,
            'status' => $status,
            'phones' => $phones,
            'prior_attempts' => $priorAttempts,
            'error' => $error,
        ]]);
    }

    /**
     * Transactional bulk upsert for a batch of contact results. Never deletes existing phones.
     *
     * @param list<array{offer_id:int,status:string,phones:list<string>,prior_attempts:int,error:?string}> $items
     */
    public static function persistBatch(PDO $pdo, array $items): void
    {
        if ($items === []) {
            return;
        }
        self::ensureSchema($pdo);
        $insertPhone = $pdo->prepare(
            "INSERT INTO offer_contacts (offer_id,phone,phone_type,scope,label)
             VALUES (?,?,?,'seller','فروشنده') ON DUPLICATE KEY UPDATE scope='seller'"
        );
        $upsertCheck = $pdo->prepare(
            "INSERT INTO rond_contact_checks (offer_id,status,attempts,checked_at,retry_at,error)
             VALUES (?,?,?,UTC_TIMESTAMP(),?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),
             attempts=VALUES(attempts),checked_at=VALUES(checked_at),retry_at=VALUES(retry_at),error=VALUES(error)"
        );
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $offerId = (int) $item['offer_id'];
                $status = (string) $item['status'];
                $attempt = (int) $item['prior_attempts'] + 1;
                $error = $item['error'] ?? null;
                $retry = gmdate(
                    'Y-m-d H:i:s',
                    time() + ($status === 'unresolved' ? 86400 : min(3600, 30 * (2 ** $attempt)))
                );
                foreach ($item['phones'] ?? [] as $phone) {
                    if (!is_string($phone) || $phone === '') {
                        continue;
                    }
                    $insertPhone->execute([
                        $offerId,
                        $phone,
                        str_starts_with($phone, '09') ? 'mobile' : 'landline',
                    ]);
                }
                $upsertCheck->execute([
                    $offerId,
                    $status,
                    $attempt,
                    $retry,
                    $error !== null ? mb_substr((string) $error, 0, 500) : null,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
