<?php

declare(strict_types=1);

final class OfferStore
{
    /**
     * @param list<array<string,mixed>> $listings
     * @return array{upserted:int, skipped:int}
     */
    public function upsertMany(int $sourceId, array $listings): array
    {
        $upserted = 0;
        $skipped = 0;
        foreach ($listings as $listing) {
            $sim = $listing['sim_number'] ?? null;
            if (!$sim || !Digits::isValid0912($sim)) {
                $skipped++;
                continue;
            }
            $this->upsertOne($sourceId, $listing);
            $upserted++;
        }
        return compact('upserted', 'skipped');
    }

    /** @param array<string,mixed> $listing */
    public function upsertOne(int $sourceId, array $listing): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $simId = $this->findOrCreateSim((string) $listing['sim_number']);
            $now = now_dt();
            $externalId = (string) $listing['external_id'];

            $stmt = $pdo->prepare('SELECT * FROM offers WHERE source_id = ? AND external_id = ? FOR UPDATE');
            $stmt->execute([$sourceId, $externalId]);
            $existing = $stmt->fetch();

            $priceAmount = $listing['price_amount'] ?? null;
            $priceType = (string) ($listing['price_type'] ?? 'unknown');
            $priceRaw = $listing['price_raw'] ?? null;
            $condNorm = (string) ($listing['condition_normalized'] ?? 'unknown');
            $availability = (($listing['availability'] ?? 'available') === 'sold') ? 'sold' : 'available';

            if ($existing) {
                $priceChanged = ((string) ($existing['price_amount'] ?? '') !== (string) ($priceAmount ?? ''))
                    || ((string) $existing['price_type'] !== $priceType);

                $upd = $pdo->prepare(
                    'UPDATE offers SET sim_number_id=?, canonical_url=?, price_amount=?, price_raw=?, price_type=?,
                     condition_raw=?, condition_normalized=?, city=?, city_raw=?, seller_key=?, availability=?,
                     last_seen_at=?, last_checked_at=? WHERE id=?'
                );
                $upd->execute([
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
                $offerId = (int) $existing['id'];
                if ($priceChanged && $priceAmount !== null) {
                    $this->recordPrice($offerId, $priceAmount, $priceRaw, $priceType, $now);
                }
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO offers (source_id, sim_number_id, external_id, canonical_url, price_amount, price_raw, price_type,
                     condition_raw, condition_normalized, city, city_raw, seller_key, availability, first_seen_at, last_seen_at, last_checked_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $sourceId,
                    $simId,
                    $externalId,
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
                $offerId = (int) $pdo->lastInsertId();
                if ($priceAmount !== null || $priceRaw) {
                    $this->recordPrice($offerId, $priceAmount, $priceRaw, $priceType, $now);
                }
            }

            $this->syncContacts($offerId, $listing['contacts'] ?? [], (string) $listing['sim_number']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function findOrCreateSim(string $number): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM sim_numbers WHERE number = ?');
        $stmt->execute([$number]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $code = Digits::codeDigit($number) ?? '0';
        $ins = $pdo->prepare('INSERT INTO sim_numbers (number, code_digit, created_at) VALUES (?,?,?)');
        $ins->execute([$number, $code, now_dt()]);
        return (int) $pdo->lastInsertId();
    }

    private function recordPrice(int $offerId, ?int $amount, ?string $raw, string $type, string $now): void
    {
        $pdo = Database::pdo();
        $last = $pdo->prepare('SELECT price_amount, price_type FROM price_observations WHERE offer_id = ? ORDER BY observed_at DESC LIMIT 1');
        $last->execute([$offerId]);
        $row = $last->fetch();
        if ($row && (string) ($row['price_amount'] ?? '') === (string) ($amount ?? '') && (string) $row['price_type'] === $type) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO price_observations (offer_id, price_amount, price_raw, price_type, observed_at) VALUES (?,?,?,?,?)'
        );
        $ins->execute([$offerId, $amount, $raw, $type, $now]);
    }

    /** @param list<array<string,mixed>> $contacts */
    private function syncContacts(int $offerId, array $contacts, string $soldSim): void
    {
        $pdo = Database::pdo();
        foreach ($contacts as $c) {
            $phone = Digits::only(Digits::normalize((string) ($c['phone'] ?? '')));
            if ($phone === '' || $phone === $soldSim) {
                continue;
            }
            $scope = (string) ($c['scope'] ?? 'store');
            $type = (string) ($c['type'] ?? (str_starts_with($phone, '09') ? 'mobile' : 'landline'));
            $label = $c['label'] ?? null;
            $stmt = $pdo->prepare(
                'INSERT INTO offer_contacts (offer_id, phone, phone_type, scope, label)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE phone_type = VALUES(phone_type), label = VALUES(label)'
            );
            $stmt->execute([$offerId, $phone, $type, $scope, $label]);
        }
    }
}
