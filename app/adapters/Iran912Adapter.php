<?php

declare(strict_types=1);

final class Iran912Adapter extends BaseAdapter
{
    public function slug(): string { return 'iran912'; }
    public function name(): string { return 'iran912.com'; }
    public function baseUrl(): string { return 'https://iran912.com'; }
    public function seedUrls(): array
    {
        $urls = [];
        for ($n = 0; $n <= 9; $n++) {
            $urls[] = "https://iran912.com/simcart-912/price-list-code-{$n}/";
        }
        return $urls;
    }
    public function notes(): string { return 'لیست واقعی روی price-list-code-0 تا 9.'; }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('article.simcart-item') as $item) {
            $number = $dom->attr($item, 'data-number');
            $priceAttr = $dom->attr($item, 'data-price');
            $status = $dom->attr($item, 'data-status');
            $sim = $this->sim((string) $number);
            if (!$sim) {
                continue;
            }
            $statusNode = $dom->first('span.simcart-status', $item);
            if ($statusNode && !$status) {
                $status = $dom->text($statusNode);
            }
            $bodyText = $dom->text($item);
            $priceText = null;
            if (preg_match('/نقدی\s*:?\s*([\d,]+)\s*تومان/u', $bodyText, $m)) {
                $priceText = 'نقدی: ' . $m[1] . ' تومان';
            } elseif ($priceAttr !== null && $priceAttr !== '') {
                $priceText = number_format((int) $priceAttr) . ' تومان';
            }
            $price = $this->price($priceText);
            $condition = $this->condition($status);
            $urlMeta = $dom->first('meta[itemprop="url"]', $item);
            $href = $urlMeta ? (string) $dom->attr($urlMeta, 'content') : '';
            if ($href === '') {
                $href = rtrim($pageUrl, '/') . '/' . $sim . '/';
            }
            $listings[] = $this->listing([
                'external_id' => $sim,
                'sim_number' => $sim,
                'canonical_url' => $this->abs($href),
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'],
                'price_type' => $price['type'],
                'condition_raw' => $condition['raw'],
                'condition_normalized' => $condition['normalized'],
                'contacts' => $this->contacts([
                    ['phone' => '09122221900', 'type' => 'mobile', 'scope' => 'store', 'label' => 'ایران ۹۱۲'],
                    ['phone' => '02144288580', 'type' => 'landline', 'scope' => 'store', 'label' => 'ایران ۹۱۲'],
                ], $sim),
                'seller_key' => 'iran912-store',
            ]);
        }
        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        if ($pageIndex >= 5 || !str_contains($html, 'spage=')) {
            return null;
        }
        return page_query_url($pageUrl, 'spage', $pageIndex + 1);
    }
}
