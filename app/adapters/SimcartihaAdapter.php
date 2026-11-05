<?php

declare(strict_types=1);

final class SimcartihaAdapter extends BaseAdapter
{
    public function slug(): string { return 'simcartiha'; }
    public function name(): string { return 'simcartiha.ir'; }
    public function baseUrl(): string { return 'https://simcartiha.ir'; }

    public function seedUrls(): array
    {
        return ['https://simcartiha.ir/wp-json/wc/store/v1/products?per_page=50&page=1'];
    }

    public function notes(): string
    {
        return 'WooCommerce Store API عمومی؛ موجودی فعلی فروشگاه بسیار کوچک است (~۳ آگهی).';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $data = json_decode($html, true);
        if (!is_array($data)) {
            return WooHelpers::parseProductCards(
                $html,
                $pageUrl,
                $this->baseUrl(),
                'simcartiha-store',
                $this->storeContacts(),
                fn ($x) => $this->sim($x),
                fn ($x) => $this->price($x),
                fn ($c, $s) => $this->contacts($c, $s),
                fn ($d) => $this->listing($d)
            );
        }

        $listings = [];
        foreach ($data as $product) {
            if (!is_array($product)) {
                continue;
            }
            $name = (string) ($product['name'] ?? '');
            $permalink = (string) ($product['permalink'] ?? '');
            $sim = $this->sim($permalink)
                ?: $this->sim($name)
                ?: $this->sim((string) ($product['sku'] ?? ''))
                ?: $this->simFromReversedLabel($name)
                ?: $this->simFromReversedLabel($permalink);
            if (!$sim) {
                continue;
            }
            $priceMinor = $product['prices']['price'] ?? null;
            $suffix = (string) ($product['prices']['currency_suffix'] ?? ' تومان');
            $priceText = is_numeric($priceMinor) ? ((string) $priceMinor) . $suffix : null;
            $price = $this->price($priceText);
            $id = (string) ($product['id'] ?? $sim);
            $listings[] = $this->listing([
                'external_id' => $id,
                'sim_number' => $sim,
                'canonical_url' => $permalink !== '' ? $permalink : $this->baseUrl() . '/product/' . $sim . '/',
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'],
                'price_type' => $price['type'],
                'condition_raw' => null,
                'condition_normalized' => 'unknown',
                'contacts' => $this->contacts($this->storeContacts(), $sim),
                'seller_key' => 'simcartiha-store',
            ]);
        }
        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        if (!str_contains($pageUrl, '/wp-json/wc/store/')) {
            return WooHelpers::nextPageUrl($html, $pageUrl, $pageIndex);
        }
        $data = json_decode($html, true);
        if (!is_array($data) || $data === []) {
            return null;
        }
        // X-WP-TotalPages is not in body; continue while a full page arrived.
        if (count($data) < 50) {
            return null;
        }
        return page_query_url($pageUrl, 'page', $pageIndex + 1);
    }

    /** @return list<array{phone:string,type:string,scope:string,label:string}> */
    private function storeContacts(): array
    {
        return [
            ['phone' => '09122373003', 'type' => 'mobile', 'scope' => 'store', 'label' => 'سیمکارتیها'],
            ['phone' => '02166677836', 'type' => 'landline', 'scope' => 'store', 'label' => 'سیمکارتیها'],
        ];
    }

    /**
     * Shop labels often show groups ending with 0912, e.g. "3003 237 0912" → 09122373003.
     */
    private function simFromReversedLabel(string $raw): ?string
    {
        $path = parse_url($raw, PHP_URL_PATH);
        $focus = is_string($path) && $path !== '' ? basename($path) : $raw;
        $norm = Digits::normalize($focus);
        $parts = preg_split('/[\s\-_\/]+/u', trim($norm)) ?: [];
        $parts = array_values(array_filter(array_map(static fn ($p) => Digits::only(Digits::normalize($p)), $parts)));
        if ($parts === [] || end($parts) !== '0912') {
            return null;
        }
        return $this->sim(implode('', array_reverse($parts)));
    }
}
