<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;

/**
 * simkhan.ir/simcard912 is a Next.js store-advertise landing, not a SIM grid (audit 2026-11-03).
 * Guessed SIM API hosts returned 404. No verified SIM listing selectors.
 */
final class SimkhanAdapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'simkhan';
    }

    public function name(): string
    {
        return 'simkhan.ir';
    }

    public function baseUrl(): string
    {
        return 'https://simkhan.ir';
    }

    public function listingSeedUrls(): array
    {
        return ['https://simkhan.ir/simcard912'];
    }

    public function availableFields(): array
    {
        return [];
    }

    public function capabilityNotes(): ?string
    {
        return 'SPA/Next.js: صفحهٔ تبلیغ فروشگاه‌ها است نه لیست سیم. API موجودی سیم تأیید نشد (مسیرهای حدسی 404). نیاز به کشف endpoint مجاز عمومی.';
    }

    public function isFetchableOnSharedHosting(): bool
    {
        return true; // HTTP works, but parsing yields no SIM rows
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        return [];
    }
}
