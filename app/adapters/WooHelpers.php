<?php

declare(strict_types=1);

/** Shared WooCommerce / Elementor loop parsing used by several verified sources. */
final class WooHelpers
{
    /**
     * @param callable(string):?string $simFn
     * @param callable(?string):array $priceFn
     * @param callable(list<array>,?string):list $contactsFn
     * @param callable(array):array $listingFn
     * @return list<array<string,mixed>>
     */
    public static function parseProductCards(
        string $html,
        string $pageUrl,
        string $baseUrl,
        string $sellerKey,
        array $storeContacts,
        callable $simFn,
        callable $priceFn,
        callable $contactsFn,
        callable $listingFn
    ): array {
        $dom = Dom::fromHtml($html);
        $cards = $dom->css('div.e-loop-item');
        if ($cards === []) {
            $cards = $dom->css('div.wd-product');
        }
        if ($cards === []) {
            $cards = $dom->xpath('//*[contains(@class,"product") and contains(@class,"type-product")]');
        }
        if ($cards === []) {
            $cards = $dom->xpath('//*[contains(@class,"product-grid-item") or contains(@class,"type-product")]');
        }

        $listings = [];
        foreach ($cards as $card) {
            $link = $dom->first('a[href*="/product/"]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $titleNode = $dom->first('h2.product_title', $card)
                ?? $dom->first('h2', $card)
                ?? $link;
            $title = $titleNode ? $dom->text($titleNode) : $dom->text($card);
            $sim = $simFn($href) ?: $simFn($title) ?: $simFn($dom->text($card));
            if (!$sim) {
                continue;
            }
            $priceNode = $dom->first('span.woocommerce-Price-amount', $card) ?? $dom->first('span.price', $card) ?? $dom->first('div.price', $card);
            $priceText = $priceNode ? $dom->text($priceNode) : '';
            if ($priceText !== '' && !preg_match('/تومان|tooman|toman/ui', Digits::normalize($priceText))) {
                $priceText .= ' تومان';
            }
            $price = $priceFn($priceText);
            $url = $href !== '' ? absolute_url($baseUrl, $href) : $pageUrl;
            $listings[] = $listingFn([
                'external_id' => $sim,
                'sim_number' => $sim,
                'canonical_url' => $url,
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'],
                'price_type' => $price['type'],
                'condition_raw' => null,
                'condition_normalized' => 'unknown',
                'contacts' => $contactsFn($storeContacts, $sim),
                'seller_key' => $sellerKey,
            ]);
        }
        return $listings;
    }

    /**
     * Follow WooCommerce /page/N/ or rel=next when present. No hard page cap.
     */
    public static function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        $dom = Dom::fromHtml($html);
        $nextLink = $dom->first('a.next.page-numbers') ?: $dom->first('a[rel="next"]');
        if ($nextLink) {
            $href = $dom->attr($nextLink, 'href');
            if ($href) {
                return absolute_url($pageUrl, $href);
            }
        }
        foreach ($dom->css('a.next') as $a) {
            $href = $dom->attr($a, 'href');
            if ($href && (str_contains($href, '/page/') || str_contains($href, 'paged='))) {
                return absolute_url($pageUrl, $href);
            }
        }
        if (preg_match('/href=["\']([^"\']*\/page\/' . ($pageIndex + 1) . '\/?[^"\']*)["\']/i', $html, $m)) {
            return absolute_url($pageUrl, html_entity_decode($m[1]));
        }
        return null;
    }
}
