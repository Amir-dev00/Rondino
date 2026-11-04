<?php

declare(strict_types=1);

final class SimkhanAdapter extends BaseAdapter
{
    public function slug(): string { return 'simkhan'; }
    public function name(): string { return 'simkhan.ir'; }
    public function baseUrl(): string { return 'https://simkhan.ir'; }
    public function seedUrls(): array { return ['https://simkhan.ir/simcard912']; }
    public function notes(): string
    {
        return 'لندینگ Next.js؛ شبکه آگهی عمومی پایدار تأیید نشده.';
    }
    public function parseListPage(string $html, string $pageUrl): array { return []; }
}
