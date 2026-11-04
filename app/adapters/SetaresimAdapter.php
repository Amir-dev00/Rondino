<?php

declare(strict_types=1);

final class SetaresimAdapter extends BaseAdapter
{
    public function slug(): string { return 'setaresim'; }
    public function name(): string { return 'setaresim.com'; }
    public function baseUrl(): string { return 'https://setaresim.com'; }
    public function seedUrls(): array { return ['https://setaresim.com/']; }
    public function notes(): string
    {
        return 'تایم‌اوت در ممیزی قبلی؛ سلکتور تأییدشده ثبت نشده.';
    }
    public function parseListPage(string $html, string $pageUrl): array { return []; }
}
