<?php

declare(strict_types=1);

final class TehransimAdapter extends BaseAdapter
{
    public function slug(): string { return 'tehransim'; }
    public function name(): string { return 'tehransim.com'; }
    public function baseUrl(): string { return 'https://tehransim.com'; }
    public function seedUrls(): array
    {
        return ['https://tehransim.com/', 'https://tehransim.com/home/simkart912'];
    }
    public function notes(): string { return 'کارت‌های SSR صفحه اول.'; }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('div.pe-card') as $card) {
            $phoneNode = $dom->first('div.phone-number', $card);
            $sim = $this->sim($phoneNode ? $dom->text($phoneNode) : '');
            $link = $dom->first('a[href*="/sim-cart/"]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            if (!$sim) {
                $sim = $this->sim($href);
            }
            if (!$sim) {
                continue;
            }
            $priceNode = $dom->first('div.price', $card);
            $priceText = $priceNode ? $dom->text($priceNode) : '';
            $price = $this->price($priceText);
            $conditionRaw = null;
            if (preg_match('#/(used|new)/#i', $href, $m)) {
                $conditionRaw = strtolower($m[1]) === 'used' ? 'کارکرده' : 'صفر';
            }
            $condition = $this->condition($conditionRaw);
            $url = $href !== '' ? $this->abs($href) : $this->baseUrl() . '/sim-cart/' . $sim;
            $listings[] = $this->listing([
                'external_id' => $sim,
                'sim_number' => $sim,
                'canonical_url' => $url,
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'] !== '' ? $price['raw'] : $priceText,
                'price_type' => $price['type'],
                'condition_raw' => $condition['raw'],
                'condition_normalized' => $condition['normalized'],
                'contacts' => $this->contacts([
                    ['phone' => '02140446340', 'type' => 'landline', 'scope' => 'store', 'label' => 'تهران سیم'],
                    ['phone' => '09123000305', 'type' => 'mobile', 'scope' => 'store', 'label' => 'واتساپ فروشگاه'],
                ], $sim),
                'seller_key' => 'tehransim-store',
            ]);
        }
        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        if ($pageIndex >= 3) {
            return null;
        }
        if (!str_contains($html, 'page-end') && !str_contains($html, 'pagination')) {
            return null;
        }
        $base = str_contains($pageUrl, 'simkart912') ? $pageUrl : 'https://tehransim.com/home/simkart912';
        return page_query_url($base, 'page', $pageIndex + 1);
    }
}
