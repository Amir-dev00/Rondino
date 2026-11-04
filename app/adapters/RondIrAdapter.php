<?php

declare(strict_types=1);

final class RondIrAdapter extends BaseAdapter
{
    public function slug(): string { return 'rond-ir'; }
    public function name(): string { return 'rond.ir'; }
    public function baseUrl(): string { return 'https://rond.ir'; }
    public function seedUrls(): array { return ['https://rond.ir']; }
    public function notes(): string
    {
        return 'مسدود/تایم‌اوت در ممیزی قبلی؛ سلکتور تأییدشده ثبت نشده.';
    }
    public function parseListPage(string $html, string $pageUrl): array { return []; }
}
