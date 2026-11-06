<?php

declare(strict_types=1);

final class SetaresimAdapter extends BaseAdapter
{
    public function slug(): string { return 'setaresim'; }
    public function name(): string { return 'setaresim.com'; }
    public function baseUrl(): string { return 'https://setaresim.com'; }

    public function seedUrls(): array
    {
        // Disabled in sources.is_enabled; keep seeds empty so accidental runs do nothing.
        return [];
    }

    public function notes(): string
    {
        return 'غیرفعال از جمع‌آوری فعال (is_enabled=0). سوابق تاریخی حفظ می‌شود.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        throw new RuntimeException('setaresim is disabled from active collection.');
    }
}
