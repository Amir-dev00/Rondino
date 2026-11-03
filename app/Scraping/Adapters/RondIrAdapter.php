<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;

/**
 * Live fetch timed out on https/http/www (audit 2026-11-03). No HTML evidence for selectors.
 */
final class RondIrAdapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'rond-ir';
    }

    public function name(): string
    {
        return 'rond.ir';
    }

    public function baseUrl(): string
    {
        return 'https://rond.ir';
    }

    public function listingSeedUrls(): array
    {
        return ['https://rond.ir'];
    }

    public function availableFields(): array
    {
        return [];
    }

    public function capabilityNotes(): ?string
    {
        return 'مسدود: اتصال از محیط توسعه در ۲۰۲۶-۱۱-۰۳ تایم‌اوت شد (https/http/www). سلکتوری ثبت نشده. فرمان تست مجدد: php artisan sources:audit --source=rond-ir';
    }

    public function isFetchableOnSharedHosting(): bool
    {
        return false;
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        return [];
    }
}
