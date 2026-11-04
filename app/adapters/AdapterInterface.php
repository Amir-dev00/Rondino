<?php

declare(strict_types=1);

interface AdapterInterface
{
    public function slug(): string;

    public function name(): string;

    public function baseUrl(): string;

    /** @return list<string> */
    public function seedUrls(): array;

    /** @return list<array<string,mixed>> */
    public function parseListPage(string $html, string $pageUrl): array;

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string;

    public function notes(): string;
}
