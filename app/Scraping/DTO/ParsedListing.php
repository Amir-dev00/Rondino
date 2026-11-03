<?php

namespace App\Scraping\DTO;

final class ParsedListing
{
    /**
     * @param  list<array{phone: string, type: string, scope: string, label: ?string}>  $contacts
     */
    public function __construct(
        public string $externalId,
        public ?string $simNumber,
        public string $canonicalUrl,
        public ?int $priceAmount,
        public ?string $priceRaw,
        public string $priceType,
        public ?string $conditionRaw,
        public ?string $conditionNormalized,
        public ?string $city,
        public ?string $cityRaw,
        public array $contacts = [],
        public string $availability = 'available',
        public bool $needsDetailFetch = false,
        public ?string $rawEvidence = null,
        public ?string $sellerKey = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) $data['external_id'],
            simNumber: $data['sim_number'] ?? null,
            canonicalUrl: (string) $data['canonical_url'],
            priceAmount: $data['price_amount'] ?? null,
            priceRaw: $data['price_raw'] ?? null,
            priceType: $data['price_type'] ?? 'unknown',
            conditionRaw: $data['condition_raw'] ?? null,
            conditionNormalized: $data['condition_normalized'] ?? 'unknown',
            city: $data['city'] ?? null,
            cityRaw: $data['city_raw'] ?? null,
            contacts: $data['contacts'] ?? [],
            availability: $data['availability'] ?? 'available',
            needsDetailFetch: (bool) ($data['needs_detail_fetch'] ?? false),
            rawEvidence: $data['raw_evidence'] ?? null,
            sellerKey: $data['seller_key'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'sim_number' => $this->simNumber,
            'canonical_url' => $this->canonicalUrl,
            'price_amount' => $this->priceAmount,
            'price_raw' => $this->priceRaw,
            'price_type' => $this->priceType,
            'condition_raw' => $this->conditionRaw,
            'condition_normalized' => $this->conditionNormalized,
            'city' => $this->city,
            'city_raw' => $this->cityRaw,
            'contacts' => $this->contacts,
            'availability' => $this->availability,
            'needs_detail_fetch' => $this->needsDetailFetch,
            'raw_evidence' => $this->rawEvidence,
            'seller_key' => $this->sellerKey,
        ];
    }

    public function withDetail(self $detail): self
    {
        return new self(
            externalId: $this->externalId,
            simNumber: $detail->simNumber ?? $this->simNumber,
            canonicalUrl: $detail->canonicalUrl ?: $this->canonicalUrl,
            priceAmount: $detail->priceAmount ?? $this->priceAmount,
            priceRaw: $detail->priceRaw ?? $this->priceRaw,
            priceType: $detail->priceType !== 'unknown' ? $detail->priceType : $this->priceType,
            conditionRaw: $detail->conditionRaw ?? $this->conditionRaw,
            conditionNormalized: ($detail->conditionNormalized && $detail->conditionNormalized !== 'unknown')
                ? $detail->conditionNormalized
                : $this->conditionNormalized,
            city: $detail->city ?? $this->city,
            cityRaw: $detail->cityRaw ?? $this->cityRaw,
            contacts: $detail->contacts !== [] ? $detail->contacts : $this->contacts,
            availability: $detail->availability,
            needsDetailFetch: false,
            rawEvidence: $detail->rawEvidence ?? $this->rawEvidence,
            sellerKey: $detail->sellerKey ?? $this->sellerKey,
        );
    }
}
