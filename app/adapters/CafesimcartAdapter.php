<?php

declare(strict_types=1);

final class CafesimcartAdapter extends BaseAdapter
{
    public function slug(): string { return 'cafesimcart'; }
    public function name(): string { return 'cafesimcart.com'; }
    public function baseUrl(): string { return 'https://cafesimcart.com'; }
    public function seedUrls(): array { return ['https://cafesimcart.com/']; }
    public function notes(): string { return 'کارت‌های .simdetail؛ ممکن است BitNinja مانع شود.'; }

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
            $bold = $dom->first('span.pricebold', $card);
            $lbl = $dom->first('span.pricelbl', $card);
            $priceText = trim(($bold ? $dom->text($bold) : '') . ' ' . ($lbl ? $dom->text($lbl) : ''));
            if ($priceText !== '' && !preg_match('/تومان|tooman|toman/ui', Digits::normalize($priceText))) {
                $priceText = ($bold ? $dom->text($bold) : '') . ' تومان';
            }
            $price = $this->price($priceText);
            $op = $dom->first('div.simoperator', $card);
            $opText = $op ? $dom->text($op) : '';
            $condition = $this->condition($opText !== '' ? $opText : null);
            $link = $dom->first('a[href]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $external = $sim;
            $cardHtml = $dom->html($card);
            if (preg_match('/id=[\'\"]sim(\d+)/', $cardHtml, $m)) {
                $external = $m[1];
            } elseif (preg_match('#cafesimcart\.com/(\d+)/#', $href, $m)) {
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
                    ['phone' => '02177090051', 'scope' => 'store', 'label' => 'کافه سیمکارت'],
                    ['phone' => '02177090061', 'scope' => 'store', 'label' => 'کافه سیمکارت'],
                    ['phone' => '09121346417', 'scope' => 'store', 'label' => 'کافه سیمکارت'],
                ], $sim),
                'seller_key' => 'cafesimcart-store',
            ]);
        }
        return $listings;
    }
}
