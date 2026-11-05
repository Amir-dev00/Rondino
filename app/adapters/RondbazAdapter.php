<?php

declare(strict_types=1);

final class RondbazAdapter extends BaseAdapter
{
    public function slug(): string { return 'rondbaz'; }
    public function name(): string { return 'rondbaz.com'; }
    public function baseUrl(): string { return 'https://www.rondbaz.com'; }

    public function seedUrls(): array
    {
        return [
            'https://www.rondbaz.com/',
            'https://www.rondbaz.com/mci/?cell_box_1_4=0912',
        ];
    }

    public function notes(): string
    {
        return 'بلاکر تأییدشده: کل دامنه HTTP 404 می‌دهد (خانه و مسیرهای MCI). سلکتورهای قدیمی .vitrin-sim-card دیگر قابل راستی‌آزمایی نیستند.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        // Keep parser for historical fixtures only; live host returns 404 before parse.
        if (!str_contains($html, 'vitrin-sim-card') && str_contains($html, 'vitrin-price')) {
            $html = '<div class="vitrin-sim-card">' . $html;
        }
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('div.vitrin-sim-card') as $card) {
            $link = $dom->first('a[href*="/SimCard/"]', $card) ?? $dom->first('a.vitrin-tooltip', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $sim = $this->sim($href) ?: $this->sim($dom->text($card));
            if (!$sim) {
                continue;
            }
            $priceNode = $dom->first('div.vitrin-price', $card);
            $priceText = $priceNode ? $dom->text($priceNode) : '';
            if ($priceText !== '' && !preg_match('/تومان|tooman|toman/ui', Digits::normalize($priceText))) {
                $priceText .= ' Tooman';
            }
            $price = $this->price($priceText);
            $conditionRaw = null;
            foreach ($dom->css('span.vitrin-attr-btn', $card) as $span) {
                $t = $dom->text($span);
                if (preg_match('/صفر\s*پک|در\s*حد\s*صفر|در\s*حد\s*نو|کارکرده|^صفر$/u', $t)) {
                    $conditionRaw = $t;
                    break;
                }
            }
            $condition = $this->condition($conditionRaw);
            $storeSlug = null;
            if (preg_match('#(?:https?://[^/]+/)?([^/]+)/SimCard/#i', $href, $m)) {
                $storeSlug = $m[1];
            }
            $external = ($storeSlug ?: 'store') . '-' . $sim;
            $listings[] = $this->listing([
                'external_id' => $external,
                'sim_number' => $sim,
                'canonical_url' => $href !== '' ? $this->abs($href) : $this->baseUrl(),
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'],
                'price_type' => $price['type'],
                'condition_raw' => $condition['raw'],
                'condition_normalized' => $condition['normalized'],
                'contacts' => [],
                'seller_key' => $storeSlug,
            ]);
        }
        return $listings;
    }
}
