<?php

declare(strict_types=1);

final class Sim0912Adapter extends BaseAdapter
{
    public function slug(): string { return 'sim0912'; }
    public function name(): string { return '0912sim.com'; }
    public function baseUrl(): string { return 'https://0912sim.com'; }
    public function seedUrls(): array
    {
        $urls = ['https://0912sim.com/', 'https://0912sim.com/shop/'];
        for ($i = 0; $i <= 9; $i++) {
            $urls[] = "https://0912sim.com/product-category/code{$i}/";
        }
        return $urls;
    }
    public function notes(): string { return 'ووکامرس Woodmart؛ دسته code0 تا code9.'; }

    public function parseListPage(string $html, string $pageUrl): array
    {
        return WooHelpers::parseProductCards(
            $html,
            $pageUrl,
            $this->baseUrl(),
            '0912sim-store',
            [
                ['phone' => '09121404005', 'type' => 'mobile', 'scope' => 'store', 'label' => '۰۹۱۲ سیم'],
                ['phone' => '02144200265', 'type' => 'landline', 'scope' => 'store', 'label' => '۰۹۱۲ سیم'],
            ],
            fn ($x) => $this->sim($x),
            fn ($x) => $this->price($x),
            fn ($c, $s) => $this->contacts($c, $s),
            fn ($d) => $this->listing($d)
        );
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        return WooHelpers::nextPageUrl($html, $pageUrl, $pageIndex);
    }
}
