<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\Dom;

/** Woodmart/Woo product grid — 0912sim.com (audit 2026-11-03). */
final class Sim0912Adapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'sim0912';
    }

    public function name(): string
    {
        return '0912sim.com';
    }

    public function baseUrl(): string
    {
        return 'https://0912sim.com';
    }

    public function listingSeedUrls(): array
    {
        return array_merge(
            ['https://0912sim.com/', 'https://0912sim.com/shop/'],
            array_map(fn (int $n) => "https://0912sim.com/product-category/code{$n}/", range(0, 9))
        );
    }

    public function availableFields(): array
    {
        return ['sim_number', 'asking_price', 'advertiser_contacts'];
    }

    public function capabilityNotes(): ?string
    {
        return 'در زمان ممیزی برخی آرشیوهای codeN خالی بودند. محصولات ظاهرشده از گرید Woodmart قابل استخراج‌اند.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $dom = Dom::fromHtml($html);
        $cards = $dom->css('div.wd-product');
        if ($cards === []) {
            $cards = $dom->query('//*[contains(@class,"product-grid-item") or contains(@class,"type-product")]');
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
                    ['phone' => '09121404005', 'type' => 'mobile', 'scope' => 'store', 'label' => '۰۹۱۲ سیم'],
                    ['phone' => '02144200265', 'type' => 'landline', 'scope' => 'store', 'label' => '۰۹۱۲ سیم'],
                ], $sim),
                availability: 'available',
                sellerKey: '0912sim-store',
            );
        }

        return $listings;
    }
}
