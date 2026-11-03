<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;

/**
 * Home/category SSR inspected 2026-11-03: no listing cards. BitNinja WAF present.
 */
final class DonyayekhatAdapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'donyayekhat';
    }

    public function name(): string
    {
        return 'donyayekhat0912.com';
    }

    public function baseUrl(): string
    {
        return 'https://donyayekhat0912.com';
    }

    public function listingSeedUrls(): array
    {
        return ['https://donyayekhat0912.com/'];
    }

    public function availableFields(): array
    {
        return [];
    }

    public function capabilityNotes(): ?string
    {
        return 'مسدود/ناکامل: در HTML صفحهٔ اصلی و دسته، کارت آگهی شماره یافت نشد. WAF BitNinja. تماس فروشگاه در هدر هست اما به آگهی‌ها قابل انتساب نیست.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        return [];
    }

    public function parseDetailPage(string $html, string $pageUrl, ParsedListing $existing): ParsedListing
    {
        return $existing;
    }
}
