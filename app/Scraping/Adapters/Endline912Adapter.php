<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\Dom;

/**
 * WooCommerce + Elementor loop — verified endline912.com (audit 2026-11-03).
 */
final class Endline912Adapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'endline912';
    }

    public function name(): string
    {
        return 'endline912.com';
    }

    public function baseUrl(): string
    {
        return 'https://endline912.com';
    }

    public function listingSeedUrls(): array
    {
        return ['https://endline912.com/'];
    }

    public function availableFields(): array
    {
        return ['sim_number', 'asking_price', 'advertiser_contacts'];
    }

    public function capabilityNotes(): ?string
    {
        return 'حلقهٔ محصول Elementor روی صفحهٔ اول. جزئیات محصول ممکن است noindex باشد.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $dom = Dom::fromHtml($html);
        $cards = $dom->css('div.e-loop-item');
        if ($cards === []) {
            $cards = $dom->query('//*[contains(concat(" ", normalize-space(@class), " "), " product ") and contains(@class, "type-product")]');
        }

        $listings = [];
        foreach ($cards as $card) {
            $title = $dom->first('h2.product_title', $card)
                ?? $dom->first('.product_title', $card)
                ?? $dom->first('h2', $card)
                ?? $dom->first('a[href*="/product/"]', $card);

            $titleText = $title ? $dom->text($title) : '';
            $link = $dom->first('a[href*="/product/"]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $sim = $this->sim($titleText) ?? $this->sim($href);
            if (! $sim) {
                continue;
            }

            $priceNode = $dom->first('span.woocommerce-Price-amount', $card)
                ?? $dom->first('.price', $card);
            $priceText = $priceNode ? $dom->text($priceNode) : '';
            if ($priceText !== '' && ! str_contains($priceText, 'تومان')) {
                $priceText .= ' تومان';
            }
            $price = $this->parsePrice($priceText);

            $listings[] = new ParsedListing(
                externalId: $sim,
                simNumber: $sim,
                canonicalUrl: $href !== '' ? $this->abs($this->baseUrl(), $href) : $this->baseUrl().'/product/'.$sim.'/',
                priceAmount: $price['amount'],
                priceRaw: $price['raw'],
                priceType: $price['type'],
                conditionRaw: null,
                conditionNormalized: 'unknown',
                city: null,
                cityRaw: null,
                contacts: $this->normalizeContacts([
                    ['phone' => '09122226837', 'type' => 'mobile', 'scope' => 'store', 'label' => 'آخر خط'],
                    ['phone' => '02155800800', 'type' => 'landline', 'scope' => 'store', 'label' => 'آخر خط'],
                ], $sim),
                availability: 'available',
                sellerKey: 'endline912-store',
            );
        }

        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        if ($pageIndex >= 6) {
            return null;
        }
        if (preg_match('/data-max-page="(\d+)"/', $html, $m) && $pageIndex >= (int) $m[1]) {
            return null;
        }
        if (! str_contains($html, '/page/') && ! str_contains($html, 'e-load-more-anchor')) {
            return null;
        }

        return 'https://endline912.com/page/'.($pageIndex + 1).'/';
    }
}
