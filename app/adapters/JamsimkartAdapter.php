<?php

declare(strict_types=1);

final class JamsimkartAdapter extends BaseAdapter
{
    public function slug(): string { return 'jamsimkart'; }
    public function name(): string { return 'jamsimkart.com'; }
    public function baseUrl(): string { return 'https://www.jamsimkart.com'; }
    public function seedUrls(): array
    {
        return [
            'https://www.jamsimkart.com/',
            'https://www.jamsimkart.com/mci/daemi/simcard-pricing',
        ];
    }
    public function notes(): string { return 'کارت‌های .vitrin-item؛ تلفن فروشنده روی کارت نیست.'; }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('div.vitrin-item') as $card) {
            $top = $dom->first('pre.vitrin-item-top', $card);
            $sim = $this->sim($top ? $dom->text($top) : '');
            if (!$sim) {
                continue;
            }
            $priceNode = $dom->first('div.vitrin-item-price', $card);
            $price = $this->price($priceNode ? $dom->text($priceNode) : '');
            $conditionRaw = null;
            foreach ($dom->css('span', $card) as $span) {
                $t = $dom->text($span);
                if (preg_match('/در\s*حد\s*صفر|در\s*حد\s*نو|کارکرده|صفر\s*پک|^صفر$/u', $t)) {
                    $conditionRaw = $t;
                    break;
                }
            }
            $condition = $this->condition($conditionRaw);
            $external = $sim;
            $onclick = (string) ($dom->attr($card, 'onclick') ?? '');
            if (preg_match("/showSimInfo\\('(\\d+)'\\)/", $onclick, $m)) {
                $external = $m[1];
            }
            $listings[] = $this->listing([
                'external_id' => $external,
                'sim_number' => $sim,
                'canonical_url' => $pageUrl,
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
        if ($pageIndex >= 5 || !str_contains($html, 'page=')) {
            return null;
        }
        $base = str_contains($pageUrl, 'simcard-pricing')
            ? $pageUrl
            : 'https://www.jamsimkart.com/mci/daemi/simcard-pricing';
        return page_query_url($base, 'page', $pageIndex + 1);
    }
}
