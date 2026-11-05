<?php

declare(strict_types=1);

final class JamsimkartAdapter extends BaseAdapter
{
    public function slug(): string { return 'jamsimkart'; }
    public function name(): string { return 'jamsimkart.com'; }
    public function baseUrl(): string { return 'https://www.jamsimkart.com'; }

    public function seedUrls(): array
    {
        return ['https://www.jamsimkart.com/search'];
    }

    public function notes(): string
    {
        return 'جدول SSR در /search با صفحه‌بندی ?page=؛ کارت‌های ویترین صفحهٔ اصلی ناقص‌اند.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('tr') as $row) {
            $num = $dom->first('h2.sim-list-table-number', $row);
            if (!$num) {
                continue;
            }
            $sim = $this->sim($dom->text($num));
            if (!$sim) {
                continue;
            }
            $external = $sim;
            $onclick = (string) ($dom->attr($row, 'onclick') ?? '');
            if (preg_match("/showSimInfo\\('(\\d+)'\\)/", $onclick, $m)) {
                $external = $m[1];
            }
            $priceNode = $dom->first('h5.top-table-price', $row);
            $priceText = $priceNode ? $dom->text($priceNode) : '';
            if ($priceText !== '' && !preg_match('/تومان|tooman|toman/ui', Digits::normalize($priceText))) {
                $priceText .= ' تومان';
            }
            $price = $this->price($priceText);
            $conditionRaw = null;
            foreach ($dom->css('span', $row) as $span) {
                $t = $dom->text($span);
                if (preg_match('/در\s*حد\s*صفر|در\s*حد\s*نو|کارکرده|صفر\s*پک|^صفر$/u', $t)) {
                    $conditionRaw = $t;
                    break;
                }
            }
            $condition = $this->condition($conditionRaw);
            $detail = $dom->first('a[href*="/sim/"]', $row);
            $href = $detail ? (string) $dom->attr($detail, 'href') : '';
            $url = $href !== '' ? $this->abs($href) : $this->baseUrl() . '/sim/' . $external;
            $listings[] = $this->listing([
                'external_id' => $external,
                'sim_number' => $sim,
                'canonical_url' => $url,
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'],
                'price_type' => $price['type'],
                'condition_raw' => $condition['raw'],
                'condition_normalized' => $condition['normalized'],
                'contacts' => $this->contacts([
                    ['phone' => '02191015881', 'type' => 'landline', 'scope' => 'store', 'label' => 'جم سیم‌کارت'],
                ], $sim),
                'seller_key' => 'jamsimkart-listing-' . $external,
            ]);
        }
        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        if (!preg_match_all('/[?&]page=(\d+)/', $html, $m) || $m[1] === []) {
            return null;
        }
        $next = $pageIndex + 1;
        $max = max(array_map('intval', $m[1]));
        if ($pageIndex >= $max) {
            return null;
        }
        return page_query_url('https://www.jamsimkart.com/search', 'page', $next);
    }
}
