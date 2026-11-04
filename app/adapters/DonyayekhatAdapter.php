<?php

declare(strict_types=1);

final class DonyayekhatAdapter extends BaseAdapter
{
    public function slug(): string { return 'donyayekhat'; }
    public function name(): string { return 'donyayekhat0912.com'; }
    public function baseUrl(): string { return 'https://donyayekhat0912.com'; }
    public function seedUrls(): array { return ['https://donyayekhat0912.com/']; }
    public function notes(): string
    {
        return 'ممکن است WAF مانع شود؛ کارت آگهی پایدار در SSR دیده نشد.';
    }
    public function parseListPage(string $html, string $pageUrl): array { return []; }
}
