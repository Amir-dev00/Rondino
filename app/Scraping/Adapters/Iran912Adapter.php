<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\Dom;

/**
 * Verified against iran912.com price-list-code-* SSR (audit 2026-11-03).
 */
final class Iran912Adapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'iran912';
    }

    public function name(): string
    {
        return 'iran912.com';
    }

    public function baseUrl(): string
    {
        return 'https://iran912.com';
    }

    public function listingSeedUrls(): array
    {
        return array_map(
            fn (int $n) => "https://iran912.com/simcart-912/price-list-code-{$n}/",
            range(0, 9)
        );
    }

    public function availableFields(): array
    {
        return ['sim_number', 'asking_price', 'line_condition', 'advertiser_contacts'];
    }

    public function capabilityNotes(): ?string
    {
        return 'لیست واقعی روی price-list-code-{0-9} است نه صفحهٔ راهنما. رزرو نیاز به ورود دارد.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $items = $dom->css('article.simcart-item');
        $listings = [];

        foreach ($items as $item) {
            $number = $dom->attr($item, 'data-number');
            $priceAttr = $dom->attr($item, 'data-price');
            $status = $dom->attr($item, 'data-status');
            $sim = $this->sim((string) $number);
            if (! $sim) {
                continue;
            }

            $priceText = null;
            $statusNode = $dom->first('span.simcart-status', $item);
            if ($statusNode && ! $status) {
                $status = $dom->text($statusNode);
            }

            // Prefer visible نقدی text when present
            $bodyText = $dom->text($item);
            if (preg_match('/نقدی\s*:?\s*([\d,]+)\s*تومان/u', $bodyText, $m)) {
                $priceText = 'نقدی: '.$m[1].' تومان';
            } elseif ($priceAttr !== null && $priceAttr !== '') {
                $priceText = number_format((int) $priceAttr).' تومان';
            }

            $price = $this->parsePrice($priceText);
            $condition = $this->parseCondition($status);

            $urlMeta = $dom->first('meta[itemprop="url"]', $item);
            $href = $urlMeta ? (string) $dom->attr($urlMeta, 'content') : '';
            if ($href === '') {
                $href = rtrim($pageUrl, '/').'/'.$sim.'/';
            }

            $listings[] = new ParsedListing(
                externalId: $sim,
                simNumber: $sim,
                canonicalUrl: $this->abs($this->baseUrl(), $href),
                priceAmount: $price['amount'],
                priceRaw: $price['raw'],
                priceType: $price['type'],
                conditionRaw: $condition['raw'],
                conditionNormalized: $condition['normalized'],
                city: null,
                cityRaw: null,
                contacts: $this->normalizeContacts([
                    ['phone' => '09122221900', 'type' => 'mobile', 'scope' => 'store', 'label' => 'ایران ۹۱۲'],
                    ['phone' => '02144288580', 'type' => 'landline', 'scope' => 'store', 'label' => 'ایران ۹۱۲'],
                ], $sim),
                availability: 'available',
                sellerKey: 'iran912-store',
            );
        }

        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        if ($pageIndex >= 5) {
            return null;
        }
        if (! str_contains($html, 'spage=')) {
            return null;
        }

        return $this->pageQueryUrl($pageUrl, 'spage', $pageIndex + 1);
    }
}
