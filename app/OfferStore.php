<?php

declare(strict_types=1);

final class OfferStore
{
    private ?PDOStatement $selOffer = null;
    private ?PDOStatement $updOffer = null;
    private ?PDOStatement $insOffer = null;
    private ?PDOStatement $selSim = null;
    private ?PDOStatement $insSim = null;
    private ?PDOStatement $selPrice = null;
    private ?PDOStatement $insPrice = null;
    private ?PDOStatement $insContact = null;

    /**
     * @param list<array<string,mixed>> $listings
     * @return array{upserted:int, skipped:int}
     */
    public function upsertMany(int $sourceId, array $listings): array
    {
        $t0 = microtime(true);
        $valid = [];
        $skipped = 0;
        foreach ($listings as $listing) {
            $sim = $listing['sim_number'] ?? null;
            if (!$sim || !Digits::isValid0912($sim)) {
                $skipped++;
                continue;
            }
            $valid[] = $listing;
        }
        if ($valid === []) {
            CrawlMetrics::$storeMs += (microtime(true) - $t0) * 1000;
            return ['upserted' => 0, 'skipped' => $skipped];
        }

        $pdo = Database::pdo();
        $this->prepareStatements($pdo);
        $pdo->beginTransaction();
        try {
            $simIds = $this->ensureSims($pdo, array_map(static fn ($r) => (string) $r['sim_number'], $valid));
            $externalIds = array_map(static fn ($r) => (string) $r['external_id'], $valid);
            $existingMap = $this->loadExisting($pdo, $sourceId, $externalIds);
            // Only the single-store Simcartiha migration may reconcile legacy IDs by SIM.
            // Marketplaces can have multiple independent sellers for the same number.
            $sourceSlug = $pdo->prepare('SELECT slug FROM sources WHERE id = ?');
            $sourceSlug->execute([$sourceId]);
            $bySim = $sourceSlug->fetchColumn() === 'simcartiha'
                ? $this->loadExistingBySim($pdo, $sourceId, array_values($simIds)) : [];

            $upserted = 0;
            $now = now_dt();
            foreach ($valid as $listing) {
                $externalId = (string) $listing['external_id'];
                $simNumber = (string) $listing['sim_number'];
                $simId = $simIds[$simNumber];
                $existing = $existingMap[$externalId] ?? null;
                $bySimRow = $bySim[$simId] ?? null;
                if ($existing && $bySimRow && (int) $existing['id'] !== (int) $bySimRow['id']) {
                    // Same SIM under an old extraction id — keep verified identity row, drop the other.
                    $pdo->prepare('DELETE FROM offers WHERE id = ?')->execute([(int) $bySimRow['id']]);
                    CrawlMetrics::$sqlStatements++;
                    unset($bySim[$simId]);
                } elseif ($existing === null) {
                    $existing = $bySimRow;
                }
                $this->upsertPrepared($pdo, $sourceId, $simId, $listing, $existing, $now);
                $upserted++;
            }
            $pdo->commit();
            CrawlMetrics::$sqlStatements += 1; // commit counted loosely; statements tracked in helpers
            CrawlMetrics::$storeMs += (microtime(true) - $t0) * 1000;
            return compact('upserted', 'skipped');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            CrawlMetrics::$storeMs += (microtime(true) - $t0) * 1000;
            throw $e;
        }
    }

    /** @param array<string,mixed> $listing */
    public function upsertOne(int $sourceId, array $listing): void
    {
        $this->upsertMany($sourceId, [$listing]);
    }

    private function prepareStatements(PDO $pdo): void
    {
        if ($this->selSim) {
            return;
        }
        $this->selSim = $pdo->prepare('SELECT id FROM sim_numbers WHERE number = ?');
        $this->insSim = $pdo->prepare('INSERT INTO sim_numbers (number, code_digit, created_at) VALUES (?,?,?)');
        $this->updOffer = $pdo->prepare(
            'UPDATE offers SET external_id=?, sim_number_id=?, canonical_url=?, price_amount=?, price_raw=?, price_type=?,
             condition_raw=?, condition_normalized=?, city=?, city_raw=?, seller_key=?, availability=?,
             last_seen_at=?, last_checked_at=? WHERE id=?'
        );
        $this->insOffer = $pdo->prepare(
            'INSERT INTO offers (source_id, sim_number_id, external_id, canonical_url, price_amount, price_raw, price_type,
             condition_raw, condition_normalized, city, city_raw, seller_key, availability, first_seen_at, last_seen_at, last_checked_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $this->selPrice = $pdo->prepare(
            'SELECT price_amount, price_type FROM price_observations WHERE offer_id = ? ORDER BY observed_at DESC LIMIT 1'
        );
        $this->insPrice = $pdo->prepare(
            'INSERT INTO price_observations (offer_id, price_amount, price_raw, price_type, observed_at) VALUES (?,?,?,?,?)'
        );
        $this->insContact = $pdo->prepare(
            'INSERT INTO offer_contacts (offer_id, phone, phone_type, scope, label)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE phone_type = VALUES(phone_type), label = VALUES(label)'
        );
    }

    /**
     * @param list<string> $numbers
     * @return array<string,int>
     */
    private function ensureSims(PDO $pdo, array $numbers): array
    {
        $numbers = array_values(array_unique($numbers));
        $map = [];
        if ($numbers === []) {
            return $map;
        }
        // Batch SELECT
        $placeholders = implode(',', array_fill(0, count($numbers), '?'));
        $stmt = $pdo->prepare("SELECT id, number FROM sim_numbers WHERE number IN ($placeholders)");
        $stmt->execute($numbers);
        CrawlMetrics::$sqlStatements++;
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['number']] = (int) $row['id'];
        }
        $now = now_dt();
        foreach ($numbers as $n) {
            if (isset($map[$n])) {
                continue;
            }
            $code = Digits::codeDigit($n) ?? '0';
            $this->insSim->execute([$n, $code, $now]);
            CrawlMetrics::$sqlStatements++;
            $map[$n] = (int) $pdo->lastInsertId();
        }
        return $map;
    }

    /**
     * @param list<string> $externalIds
     * @return array<string, array<string,mixed>>
     */
    private function loadExisting(PDO $pdo, int $sourceId, array $externalIds): array
    {
        $externalIds = array_values(array_unique($externalIds));
        $map = [];
        if ($externalIds === []) {
            return $map;
        }
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $params = array_merge([$sourceId], $externalIds);
        $stmt = $pdo->prepare(
            "SELECT id, external_id, price_amount, price_type FROM offers WHERE source_id = ? AND external_id IN ($placeholders)"
        );
        $stmt->execute($params);
        CrawlMetrics::$sqlStatements++;
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['external_id']] = $row;
        }
        return $map;
    }

    /**
     * Reconcile prior extraction IDs by verified SIM identity for this source.
     *
     * @param list<int> $simIds
     * @return array<int, array<string,mixed>>
     */
    private function loadExistingBySim(PDO $pdo, int $sourceId, array $simIds): array
    {
        $simIds = array_values(array_unique(array_map('intval', $simIds)));
        $map = [];
        if ($simIds === []) {
            return $map;
        }
        $placeholders = implode(',', array_fill(0, count($simIds), '?'));
        $params = array_merge([$sourceId], $simIds);
        $stmt = $pdo->prepare(
            "SELECT id, external_id, sim_number_id, price_amount, price_type
             FROM offers WHERE source_id = ? AND sim_number_id IN ($placeholders)"
        );
        $stmt->execute($params);
        CrawlMetrics::$sqlStatements++;
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['sim_number_id']] = $row;
        }
        return $map;
    }

    /** @param array<string,mixed> $listing */
    private function upsertPrepared(PDO $pdo, int $sourceId, int $simId, array $listing, ?array $existing, string $now): void
    {
        $priceAmount = $listing['price_amount'] ?? null;
        $priceType = (string) ($listing['price_type'] ?? 'unknown');
        $priceRaw = $listing['price_raw'] ?? null;
        $condNorm = (string) ($listing['condition_normalized'] ?? 'unknown');
        $availability = (($listing['availability'] ?? 'available') === 'sold') ? 'sold' : 'available';

        if ($existing) {
            $newExternal = (string) $listing['external_id'];
            $oldExternal = (string) ($existing['external_id'] ?? '');
            if ($newExternal !== '' && $newExternal !== $oldExternal) {
                // Drop stale row that already holds the verified listing identity.
                $pdo->prepare('DELETE FROM offers WHERE source_id = ? AND external_id = ? AND id <> ?')
                    ->execute([$sourceId, $newExternal, (int) $existing['id']]);
                CrawlMetrics::$sqlStatements++;
            }
            $priceChanged = ((string) ($existing['price_amount'] ?? '') !== (string) ($priceAmount ?? ''))
                || ((string) $existing['price_type'] !== $priceType);
            $this->updOffer->execute([
                $newExternal,
                $simId,
                (string) $listing['canonical_url'],
                $priceAmount,
                $priceRaw,
                $priceType,
                $listing['condition_raw'] ?? null,
                $condNorm,
                $listing['city'] ?? null,
                $listing['city_raw'] ?? null,
                $listing['seller_key'] ?? null,
                $availability === 'sold' ? 'sold' : 'available',
                $now,
                $now,
                (int) $existing['id'],
            ]);
            CrawlMetrics::$sqlStatements++;
            $offerId = (int) $existing['id'];
            if ($priceChanged && $priceAmount !== null) {
                $this->recordPricePrepared($offerId, $priceAmount, $priceRaw, $priceType, $now);
            }
        } else {
            $this->insOffer->execute([
                $sourceId,
                $simId,
                (string) $listing['external_id'],
                (string) $listing['canonical_url'],
                $priceAmount,
                $priceRaw,
                $priceType,
                $listing['condition_raw'] ?? null,
                $condNorm,
                $listing['city'] ?? null,
                $listing['city_raw'] ?? null,
                $listing['seller_key'] ?? null,
                $availability,
                $now,
                $now,
                $now,
            ]);
            CrawlMetrics::$sqlStatements++;
            $offerId = (int) $pdo->lastInsertId();
            if ($priceAmount !== null || $priceRaw) {
                $this->recordPricePrepared($offerId, $priceAmount, $priceRaw, $priceType, $now);
            }
        }
        $this->syncContactsPrepared($offerId, $listing['contacts'] ?? [], (string) $listing['sim_number']);
    }

    private function recordPricePrepared(int $offerId, ?int $amount, ?string $raw, string $type, string $now): void
    {
        $this->selPrice->execute([$offerId]);
        CrawlMetrics::$sqlStatements++;
        $row = $this->selPrice->fetch();
        if ($row && (string) ($row['price_amount'] ?? '') === (string) ($amount ?? '') && (string) $row['price_type'] === $type) {
            return;
        }
        $this->insPrice->execute([$offerId, $amount, $raw, $type, $now]);
        CrawlMetrics::$sqlStatements++;
    }

    /** @param list<array<string,mixed>> $contacts */
    private function syncContactsPrepared(int $offerId, array $contacts, string $soldSim): void
    {
        foreach ($contacts as $c) {
            $phone = Digits::only(Digits::normalize((string) ($c['phone'] ?? '')));
            if ($phone === '' || $phone === $soldSim) {
                continue;
            }
            $scope = (string) ($c['scope'] ?? 'store');
            $type = (string) ($c['type'] ?? (str_starts_with($phone, '09') ? 'mobile' : 'landline'));
            $label = $c['label'] ?? null;
            $this->insContact->execute([$offerId, $phone, $type, $scope, $label]);
            CrawlMetrics::$sqlStatements++;
        }
    }
}

