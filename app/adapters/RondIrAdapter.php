<?php

declare(strict_types=1);

final class RondIrAdapter extends BaseAdapter
{
    public function slug(): string { return 'rond-ir'; }
    public function name(): string { return 'rond.ir'; }
    public function baseUrl(): string { return 'https://rond.ir'; }

    public function seedUrls(): array
    {
        return [
            'https://rond.ir/',
            'https://www.rond.ir/',
        ];
    }

    public function notes(): string
    {
        return 'بلاکر تأییدشده: تایم‌اوت اتصال از PHP و مرورگر محلی. سلکتور/API عمومی قابل دسترسی نیست.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        return [];
    }
}
