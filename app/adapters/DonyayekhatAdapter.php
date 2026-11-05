<?php

declare(strict_types=1);

final class DonyayekhatAdapter extends BaseAdapter
{
    public function slug(): string { return 'donyayekhat'; }
    public function name(): string { return 'donyayekhat0912.com'; }
    public function baseUrl(): string { return 'https://donyayekhat0912.com'; }

    public function seedUrls(): array
    {
        return ['https://donyayekhat0912.com/getajaxcontent_home.php?txtpageno=1'];
    }

    public function notes(): string
    {
        return 'موجودی از POST عمومی getajaxcontent_home.php با فیلد txtpageno؛ SSR صفحه اصلی خالی است.';
    }

    public function fetchPage(string $url, int $timeout = 25): ?array
    {
        if (!str_contains($url, 'getajaxcontent_home.php')) {
            return null;
        }
        $page = 1;
        $q = parse_url($url, PHP_URL_QUERY);
        if (is_string($q)) {
            parse_str($q, $params);
            if (isset($params['txtpageno']) && ctype_digit((string) $params['txtpageno'])) {
                $page = (int) $params['txtpageno'];
            }
        }
        $res = Http::postForm(
            'https://donyayekhat0912.com/getajaxcontent_home.php',
            [
                'txtpageno' => $page,
                'txtsearchnumber' => '',
                'radiosort' => '0',
                'radiostate' => '-1',
            ],
            $timeout,
            [
                'Origin: https://donyayekhat0912.com',
                'Referer: https://donyayekhat0912.com/',
            ]
        );
        $res['final_url'] = $url;
        return $res;
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('div.productblock2') as $card) {
            $link = $dom->first('a[href*="/product/"]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $title = $link ? $dom->text($link) : '';
            $numNode = $dom->first('div.khatnumber', $card);
            $sim = $this->sim($numNode ? $dom->text($numNode) : '')
                ?: $this->sim($href)
                ?: $this->sim($title);
            if (!$sim) {
                continue;
            }
            $priceNode = $dom->first('div.productrealprice', $card);
            $price = $this->price($priceNode ? $dom->text($priceNode) : '');
            $condition = $this->condition($title !== '' ? $title : ($numNode ? $dom->text($numNode) : null));
            $external = $sim;
            if (preg_match('#/product/(\d+)/#', $href, $m)) {
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
                    ['phone' => '09126616638', 'type' => 'mobile', 'scope' => 'store', 'label' => 'دنیای خط'],
                    ['phone' => '02636666466', 'type' => 'landline', 'scope' => 'store', 'label' => 'دنیای خط'],
                    ['phone' => '02636666566', 'type' => 'landline', 'scope' => 'store', 'label' => 'دنیای خط'],
                ], $sim),
                'seller_key' => 'donyayekhat-store',
            ]);
        }
        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        $next = $pageIndex + 1;
        $max = $pageIndex;
        if (preg_match_all('/class=[\'"]pageno[\'"][^>]*>\s*(\d+)/', $html, $m) && $m[1] !== []) {
            $max = max($max, max(array_map('intval', $m[1])));
        }
        if (preg_match('/class=[\'"]selectedpageno[\'"][^>]*>\s*(\d+)/', $html, $sm)) {
            $max = max($max, (int) $sm[1]);
        }
        if ($next > $max) {
            return null;
        }
        return 'https://donyayekhat0912.com/getajaxcontent_home.php?txtpageno=' . $next;
    }
}
