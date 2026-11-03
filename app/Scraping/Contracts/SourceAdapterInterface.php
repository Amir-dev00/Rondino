<?php

namespace App\Scraping\Contracts;

use App\Scraping\DTO\ParsedListing;

interface SourceAdapterInterface
{
    public function slug(): string;

    public function name(): string;

    public function baseUrl(): string;

    /**
     * @return list<string>
     */
    public function listingSeedUrls(): array;

    /**
     * @return list<string>
     */
    public function availableFields(): array;

    public function capabilityNotes(): ?string;

    public function isFetchableOnSharedHosting(): bool;

    /**
     * @return list<ParsedListing>
     */
    public function parseListPage(string $html, string $pageUrl): array;

    public function parseDetailPage(string $html, string $pageUrl, ParsedListing $existing): ParsedListing;

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string;
}
