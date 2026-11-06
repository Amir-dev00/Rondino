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
            'https://www.rondbaz.com/mci/?cell_box_1_4=0912&operator_type=' . rawurlencode('دائمی'),
        ];
    }

    public function notes(): string
    {
        return 'خانه: کارت‌های .vitrin-sim-card؛ MCI: جدول /khatcell/SimCard/. صفحه‌بندی ASP.NET __doPostBack هنوز خودکار نشده.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $listings = $this->parseVitrinCards($html);
        if ($listings === []) {
            $listings = $this->parseMciTable($html);
        }
        if ($listings === []) {
            if (stripos($html, 'Transferring') !== false || stripos($html, '__arcsjs') !== false) {
                throw new RuntimeException('Rondbaz challenge/transfer page received; not an empty inventory.');
            }
            if ($html === '' || strlen($html) < 200) {
                throw new RuntimeException('Rondbaz empty/unrecognized response; not treated as completed inventory.');
            }
            // Page reachable but no 0912 rows (e.g. filtered empty) — verified empty for this URL.
            return [];
        }
        return array_values($listings);
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        // MCI uses ASP.NET __doPostBack pagers; no stable public GET page= URL verified.
        return null;
    }

    /** @return array<string,array<string,mixed>> */
    private function parseVitrinCards(string $html): array
    {
        if (!str_contains($html, 'vitrin-sim-card') && str_contains($html, 'vitrin-price')) {
            $html = '<div class="vitrin-sim-card">' . $html;
        }
        if (!str_contains($html, 'vitrin-sim-card')) {
            return [];
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
            if ($priceText !== '' && !preg_match('/تومان|tooman|toman|تماس/ui', Digits::normalize($priceText))) {
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
            $listings[$external] = $this->listing([
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

    /** @return array<string,array<string,mixed>> */
    private function parseMciTable(string $html): array
    {
        if (!str_contains($html, '/SimCard/')) {
            return [];
        }
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('tr') as $row) {
            $link = $dom->first('a[href*="/SimCard/"]', $row);
            if (!$link) {
                continue;
            }
            $href = (string) $dom->attr($link, 'href');
            $sim = $this->sim($href) ?: $this->sim($dom->text($link));
            if (!$sim) {
                continue;
            }
            $cells = $dom->xpath('./td', $row);
            $texts = [];
            foreach ($cells as $cell) {
                $texts[] = $dom->text($cell);
            }
            $priceText = null;
            $conditionRaw = null;
            foreach ($texts as $t) {
                if ($priceText === null && (preg_match('/[\d٬,]+/', $t) || preg_match('/تماس\s*بگیرید/u', $t)) && preg_match('/Tooman|تومان|تماس/ui', $t)) {
                    $priceText = $t;
                }
                if ($conditionRaw === null && preg_match('/صفر\s*پک|در\s*حد\s*صفر|کارکرده|^صفر$/u', $t)) {
                    $conditionRaw = $t;
                }
            }
            if ($priceText === null) {
                foreach ($texts as $t) {
                    if (preg_match('/[\d٬,]{3,}/', $t) || preg_match('/تماس\s*بگیرید/u', $t)) {
                        $priceText = $t;
                        break;
                    }
                }
            }
            if ($priceText !== null && $priceText !== '' && !preg_match('/تومان|tooman|toman|تماس/ui', Digits::normalize($priceText))) {
                $priceText .= ' Tooman';
            }
            $price = $this->price($priceText);
            $condition = $this->condition($conditionRaw);
            $storeSlug = null;
            if (preg_match('#(?:https?://[^/]+/)?([^/]+)/SimCard/#i', $href, $m)) {
                $storeSlug = $m[1];
            }
            $external = ($storeSlug ?: 'mci') . '-' . $sim;
            $listings[$external] = $this->listing([
                'external_id' => $external,
                'sim_number' => $sim,
                'canonical_url' => $this->abs($href),
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
