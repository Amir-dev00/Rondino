<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\Dom;

/** WooCommerce Elementor loop — simcartiha.ir (audit 2026-11-03). */
final class SimcartihaAdapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'simcartiha';
    }

    public function name(): string
    {
        return 'simcartiha.ir';
    }

    public function baseUrl(): string
    {
        return 'https://simcartiha.ir';
    }

    public function listingSeedUrls(): array
    {
        return array_merge(
            ['https://simcartiha.ir/'],
            array_map(fn (int $n) => "https://simcartiha.ir/code{$n}/", range(0, 9))
        );
    }

    public function availableFields(): array
    {
        return ['sim_number', 'asking_price', 'advertiser_contacts'];
    }

    public function capabilityNotes(): ?string
    {
        return 'کاروسل صفحهٔ اول محدود است؛ برای پوشش بیشتر /codeN/ لازم است. وضعیت خط روی کارت‌ها ضعیف است.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $dom = Dom::fromHtml($html);
        $cards = $dom->css('div.e-loop-item');
        if ($cards === []) {
            $cards = $dom->query('//*[contains(@class,"product") and contains(@class,"type-product")]');
        }

        $listings = [];
        foreach ($cards as $card) {
            $link = $dom->first('a[href*="/product/"]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $sim = $this->sim($href) ?? $this->sim($dom->text($card));
            if (! $sim) {
                continue;
            }

            $priceNode = $dom->first('span.woocommerce-Price-amount', $card) ?? $dom->first('.price', $card);
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
                    ['phone' => '09122373003', 'type' => 'mobile', 'scope' => 'store', 'label' => 'سیمکارتیها'],
                    ['phone' => '02166677836', 'type' => 'landline', 'scope' => 'store', 'label' => 'سیمکارتیها'],
                ], $sim),
                availability: 'available',
                sellerKey: 'simcartiha-store',
            );
        }

        return $listings;
    }
}
