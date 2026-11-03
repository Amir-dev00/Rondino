<?php

namespace App\Scraping\Services;

use App\Models\Offer;
use App\Models\OfferContact;
use App\Models\PriceObservation;
use App\Models\SimNumber;
use App\Models\Source;
use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\DigitNormalizer;
use Illuminate\Support\Facades\DB;

final class OfferUpserter
{
    /**
     * @param  list<ParsedListing>  $listings
     * @return array{upserted: int, skipped: int}
     */
    public function upsertMany(Source $source, array $listings): array
    {
        $upserted = 0;
        $skipped = 0;

        foreach ($listings as $listing) {
            if (! $listing->simNumber || ! DigitNormalizer::isValid0912($listing->simNumber)) {
                $skipped++;
                continue;
            }
            $this->upsertOne($source, $listing);
            $upserted++;
        }

        return compact('upserted', 'skipped');
    }

    public function upsertOne(Source $source, ParsedListing $listing): Offer
    {
        return DB::transaction(function () use ($source, $listing) {
            $sim = SimNumber::findOrCreateFromNumber($listing->simNumber);
            $now = now();

            /** @var Offer|null $existing */
            $existing = Offer::query()
                ->where('source_id', $source->id)
                ->where('external_id', $listing->externalId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $priceChanged = $existing->price_amount !== $listing->priceAmount
                    || $existing->price_type !== $listing->priceType;

                $existing->fill([
                    'sim_number_id' => $sim->id,
                    'canonical_url' => $listing->canonicalUrl,
                    'price_amount' => $listing->priceAmount,
                    'price_raw' => $listing->priceRaw,
                    'price_type' => $listing->priceType,
                    'condition_raw' => $listing->conditionRaw,
                    'condition_normalized' => $listing->conditionNormalized ?? 'unknown',
                    'city' => $listing->city,
                    'city_raw' => $listing->cityRaw,
                    'seller_key' => $listing->sellerKey,
                    // Never mark sold from a failed/partial crawl — only explicit sold from parser
                    'availability' => $listing->availability === 'sold' ? 'sold' : 'available',
                    'last_seen_at' => $now,
                    'last_checked_at' => $now,
                ]);
                $existing->save();

                if ($priceChanged && $listing->priceAmount !== null) {
                    $this->recordPrice($existing, $listing, $now);
                }

                $this->syncContacts($existing, $listing);

                return $existing->fresh(['contacts']);
            }

            $offer = Offer::query()->create([
                'source_id' => $source->id,
                'sim_number_id' => $sim->id,
                'external_id' => $listing->externalId,
                'canonical_url' => $listing->canonicalUrl,
                'price_amount' => $listing->priceAmount,
                'price_raw' => $listing->priceRaw,
                'price_type' => $listing->priceType,
                'condition_raw' => $listing->conditionRaw,
                'condition_normalized' => $listing->conditionNormalized ?? 'unknown',
                'city' => $listing->city,
                'city_raw' => $listing->cityRaw,
                'seller_key' => $listing->sellerKey,
                'availability' => $listing->availability === 'sold' ? 'sold' : 'available',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_checked_at' => $now,
            ]);

            if ($listing->priceAmount !== null || $listing->priceRaw) {
                $this->recordPrice($offer, $listing, $now);
            }

            $this->syncContacts($offer, $listing);

            return $offer;
        });
    }

    /**
     * Mark offers not seen in this successful run as not_observed (not sold).
     *
     * @param  list<string>  $seenExternalIds
     */
    public function markMissingAsNotObserved(Source $source, array $seenExternalIds, \DateTimeInterface $runStarted): int
    {
        if ($seenExternalIds === []) {
            // Empty parse must never wipe inventory
            return 0;
        }

        return Offer::query()
            ->where('source_id', $source->id)
            ->where('availability', 'available')
            ->where('last_seen_at', '<', $runStarted)
            ->whereNotIn('external_id', $seenExternalIds)
            ->update([
                'availability' => 'not_observed',
                'last_checked_at' => now(),
            ]);
    }

    private function recordPrice(Offer $offer, ParsedListing $listing, $now): void
    {
        $last = PriceObservation::query()
            ->where('offer_id', $offer->id)
            ->orderByDesc('observed_at')
            ->first();

        if ($last && $last->price_amount === $listing->priceAmount && $last->price_type === $listing->priceType) {
            return;
        }

        PriceObservation::query()->create([
            'offer_id' => $offer->id,
            'price_amount' => $listing->priceAmount,
            'price_raw' => $listing->priceRaw,
            'price_type' => $listing->priceType,
            'observed_at' => $now,
        ]);
    }

    private function syncContacts(Offer $offer, ParsedListing $listing): void
    {
        foreach ($listing->contacts as $c) {
            if (($c['phone'] ?? '') === '') {
                continue;
            }
            OfferContact::query()->updateOrCreate(
                [
                    'offer_id' => $offer->id,
                    'phone' => $c['phone'],
                    'scope' => $c['scope'] ?? 'store',
                ],
                [
                    'phone_type' => $c['type'] ?? 'mobile',
                    'label' => $c['label'] ?? null,
                ]
            );
        }
    }
}
