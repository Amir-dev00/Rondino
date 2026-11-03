<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\Dom;

/** Multi-seller marketplace .vitrin-sim-card — rondbaz.com (audit 2026-11-03). */
final class RondbazAdapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'rondbaz';
    }

    public function name(): string
    {
        return 'rondbaz.com';
    }

    public function baseUrl(): string
    {
        return 'https://www.rondbaz.com';
    }

    public function listingSeedUrls(): array
    {
        return [
            'https://www.rondbaz.com/',
            'https://www.rondbaz.com/mci/?cell_box_1_4=0912',
        ];
    }

    public function availableFields(): array
    {
        return ['sim_number', 'asking_price', 'line_condition', 'advertiser_contacts'];
    }

    public function capabilityNotes(): ?string
    {
        return 'بازار چندفروشنده‌ای؛ فروشنده از slug فروشگاه در URL استخراج می‌شود. فقط خطوط 0912 نگه داشته می‌شوند.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        // Fixtures may omit opening <div — normalize
        if (! str_contains($html, '<div class="vitrin-sim-card"') && str_contains($html, 'class="vitrin-sim-card"')) {
            $html = str_replace('class="vitrin-sim-card"', '<div class="vitrin-sim-card"', $html);
        }

        $dom = Dom::fromHtml($html);
        $cards = $dom->css('div.vitrin-sim-card');
        $listings = [];

        foreach ($cards as $card) {
            $link = $dom->first('a[href*="/SimCard/"]', $card) ?? $dom->first('a.vitrin-tooltip', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $sim = $this->sim($href) ?? $this->sim($link ? $dom->text($link) : '');
            if (! $sim) {
                continue; // skip non-0912 (e.g. 0919 on homepage)
            }

            $priceNode = $dom->first('div.vitrin-price', $card);
            $priceText = $priceNode ? $dom->text($priceNode) : '';
            if ($priceText !== '' && ! preg_match('/تومان|tooman|toman/ui', $priceText)) {
                $priceText .= ' Tooman';
            }
            $price = $this->parsePrice($priceText);

            $conditionRaw = null;
            foreach ($dom->css('span.vitrin-attr-btn', $card) as $attr) {
                $t = $dom->text($attr);
                if (preg_match('/صفر\s*پک|در\s*حد\s*صفر|در\s*حد\s*نو|کارکرده|^صفر$/u', $t)) {
                    $conditionRaw = $t;
                    break;
                }
            }
            $condition = $this->parseCondition($conditionRaw);

            $storeSlug = null;
            if (preg_match('#(?:https?://[^/]+/)?([^/]+)/SimCard/#i', $href, $m)) {
                $storeSlug = $m[1];
            }

            $url = $href !== '' ? $this->abs($this->baseUrl(), $href) : $this->baseUrl().'/';

            $listings[] = new ParsedListing(
                externalId: ($storeSlug ?: 'store').'-'.$sim,
                simNumber: $sim,
                canonicalUrl: $url,
                priceAmount: $price['amount'],
                priceRaw: $price['raw'],
                priceType: $price['type'],
                conditionRaw: $condition['raw'],
                conditionNormalized: $condition['normalized'],
                city: null,
                cityRaw: null,
                contacts: $storeSlug ? [[
                    'phone' => '', // no phone on card — seller identified by storefront slug
                    'type' => 'mobile',
                    'scope' => 'seller',
                    'label' => 'فروشگاه: '.$storeSlug,
                ]] : [],
                availability: 'available',
                sellerKey: $storeSlug,
            );
        }

        // Drop empty-phone contact placeholders — keep seller label via sellerKey
        foreach ($listings as $i => $listing) {
            $contacts = array_values(array_filter(
                $listing->contacts,
                fn (array $c) => ($c['phone'] ?? '') !== ''
            ));
            $listings[$i] = new ParsedListing(
                externalId: $listing->externalId,
                simNumber: $listing->simNumber,
                canonicalUrl: $listing->canonicalUrl,
                priceAmount: $listing->priceAmount,
                priceRaw: $listing->priceRaw,
                priceType: $listing->priceType,
                conditionRaw: $listing->conditionRaw,
                conditionNormalized: $listing->conditionNormalized,
                city: $listing->city,
                cityRaw: $listing->cityRaw,
                contacts: $contacts,
                availability: $listing->availability,
                needsDetailFetch: true,
                rawEvidence: $listing->sellerKey ? 'seller_slug:'.$listing->sellerKey : null,
                sellerKey: $listing->sellerKey,
            );
        }

        return $listings;
    }
}
