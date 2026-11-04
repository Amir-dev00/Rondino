<?php

declare(strict_types=1);

final class Rond0912Adapter extends BaseAdapter
{
    public function slug(): string { return 'rond0912'; }
    public function name(): string { return 'rond0912.com'; }
    public function baseUrl(): string { return 'http://rond0912.com'; }
    public function seedUrls(): array { return ['http://rond0912.com/']; }
    public function notes(): string { return 'کارت‌های .simdetail؛ صفحه بزرگ.'; }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('div.simdetail') as $card) {
            $simNode = $dom->first('div.simno', $card);
            $sim = $this->sim($simNode ? $dom->text($simNode) : '');
            if (!$sim) {
                continue;
            }
            $priceNode = $dom->first('span.pricebold', $card);
            $priceText = $priceNode ? ($dom->text($priceNode) . ' تومان') : '';
            $price = $this->price($priceText);
            $date = $dom->first('div.dateblock', $card);
            $op = $dom->first('div.simoperator', $card);
            $condText = trim(($date ? $dom->text($date) : '') . ' ' . ($op ? $dom->text($op) : ''));
            $condition = $this->condition($condText !== '' ? $condText : null);
            $link = $dom->first('a[href]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $external = $sim;
            if ($href && preg_match('#/(\d+)/#', $href, $m)) {
                $external = $m[1];
            }
            $listings[] = $this->listing([
                'external_id' => $external,
                'sim_number' => $sim,
                'canonical_url' => $href !== '' ? $this->abs($href) : $this->baseUrl(),
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'],
                'price_type' => $price['type'],
                'condition_raw' => $condition['raw'],
                'condition_normalized' => $condition['normalized'],
                'contacts' => $this->contacts([
                    ['phone' => '02165106887', 'type' => 'landline', 'scope' => 'store', 'label' => 'رند ۰۹۱۲'],
                    ['phone' => '09126672925', 'type' => 'mobile', 'scope' => 'store', 'label' => 'رند ۰۹۱۲'],
                ], $sim),
                'seller_key' => 'rond0912-store',
            ]);
        }
        return $listings;
    }
}
