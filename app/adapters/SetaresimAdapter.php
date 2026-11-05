<?php

declare(strict_types=1);

final class SetaresimAdapter extends BaseAdapter
{
    public function slug(): string { return 'setaresim'; }
    public function name(): string { return 'setaresim.com'; }
    public function baseUrl(): string { return 'https://setaresim.com'; }

    public function seedUrls(): array
    {
        return [
            'https://setaresim.com/',
            'https://setaresim.com/sim-card/0912',
        ];
    }

    public function notes(): string
    {
        return 'بلاکر تأییدشده: تایم‌اوت اتصال از PHP و مرورگر محلی (chrome-error). API/فید عمومی پیدا نشد.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        throw new RuntimeException('پارسر ستاره سیم هنوز پیاده سازی نشده؛ دریافت پاسخ HTTP موفق به معنی استخراج موفق نیست.');
    }
}
