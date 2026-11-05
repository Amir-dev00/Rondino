<?php

declare(strict_types=1);

final class Endline912Adapter extends BaseAdapter
{
    public function slug(): string { return 'endline912'; }
    public function name(): string { return 'endline912.com'; }
    public function baseUrl(): string { return 'https://endline912.com'; }
    public function seedUrls(): array { return ['https://endline912.com/']; }
    public function notes(): string { return 'حلقه Elementor در صفحه اصلی.'; }

    public function parseListPage(string $html, string $pageUrl): array
    {
        return WooHelpers::parseProductCards(
            $html,
            $pageUrl,
            $this->baseUrl(),
            'endline912-store',
            [
                ['phone' => '09122226837', 'type' => 'mobile', 'scope' => 'store', 'label' => 'آخر خط'],
                ['phone' => '02155800800', 'type' => 'landline', 'scope' => 'store', 'label' => 'آخر خط'],
            ],
            fn ($x) => $this->sim($x),
            fn ($x) => $this->price($x),
            fn ($c, $s) => $this->contacts($c, $s),
            fn ($d) => $this->listing($d)
        );
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        if (preg_match('/data-max-page=\"(\d+)\"/', $html, $m) && $pageIndex >= (int) $m[1]) {
            return null;
        }
        if (!str_contains($html, '/page/') && !str_contains($html, 'e-load-more-anchor')) {
            return null;
        }
        $next = $pageIndex + 1;
        if (preg_match('/data-max-page=\"(\d+)\"/', $html, $m2) && $next > (int) $m2[1]) {
            return null;
        }
        return 'https://endline912.com/page/' . $next . '/';
    }
}
