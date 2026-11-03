<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;

/**
 * Live fetch timed out (audit 2026-11-03). No HTML evidence for selectors.
 */
final class SetaresimAdapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'setaresim';
    }

    public function name(): string
    {
        return 'setaresim.com';
    }

    public function baseUrl(): string
    {
        return 'https://setaresim.com';
    }

    public function listingSeedUrls(): array
    {
        return ['https://setaresim.com/'];
    }

    public function availableFields(): array
    {
        return [];
    }

    public function capabilityNotes(): ?string
    {
        return 'مسدود: اتصال در ۲۰۲۶-۱۱-۰۳ تایم‌اوت شد. سلکتوری ثبت نشده. فرمان تست مجدد: php artisan sources:audit --source=setaresim';
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
