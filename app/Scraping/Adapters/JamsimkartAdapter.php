<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\Dom;

/** Marketplace cards .vitrin-item — jamsimkart.com (audit 2026-11-03). */
final class JamsimkartAdapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'jamsimkart';
    }

    public function name(): string
    {
        return 'jamsimkart.com';
    }

    public function baseUrl(): string
    {
        return 'https://www.jamsimkart.com';
    }

    public function listingSeedUrls(): array
    {
        return [
            'https://www.jamsimkart.com/',
            'https://www.jamsimkart.com/mci/daemi/simcard-pricing',
        ];
    }

    public function availableFields(): array
    {
        return ['sim_number', 'asking_price', 'line_condition', 'advertiser_contacts'];
    }

    public function capabilityNotes(): ?string
    {
        return 'شماره تماس فروشنده روی کارت نیست؛ از طریق مودال showSimInfo و تلفن‌های فروشگاه در فوتر.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $cards = $dom->css('div.vitrin-item');
        $listings = [];

        foreach ($cards as $card) {
            $pre = $dom->first('pre.vitrin-item-top', $card);
            $sim = $this->sim($pre ? $dom->text($pre) : '');
            if (! $sim) {
                continue;
            }

            $priceNode = $dom->first('div.vitrin-item-price', $card);
            $priceText = $priceNode ? $dom->text($priceNode) : '';
            $price = $this->parsePrice($priceText);

            $status = null;
            foreach ($dom->css('span', $card) as $span) {
                $t = $dom->text($span);
                if (preg_match('/در\s*حد\s*صفر|در\s*حد\s*نو|کارکرده|صفر\s*پک|^صفر$/u', $t)) {
                    $status = $t;
                    break;
                }
            }
            $condition = $this->parseCondition($status);

            $externalId = $sim;
            $onclick = $dom->attr($card, 'onclick') ?? '';
            if (preg_match("/showSimInfo\('(\d+)'\)/", $onclick, $m)) {
                $externalId = $m[1];
            }

            $listings[] = new ParsedListing(
                externalId: (string) $externalId,
                simNumber: $sim,
                canonicalUrl: $this->baseUrl().'/sim/'.$externalId,
                priceAmount: $price['amount'],
                priceRaw: $price['raw'],
                priceType: $price['type'],
                conditionRaw: $condition['raw'],
                conditionNormalized: $condition['normalized'],
                city: null,
                cityRaw: null,
                contacts: $this->normalizeContacts([
                    // Footer store phones observed on site — store scope only
                    ['phone' => '02191015881', 'type' => 'landline', 'scope' => 'store', 'label' => 'جم سیم‌کارت'],
                ], $sim),
                availability: 'available',
                needsDetailFetch: true,
                sellerKey: 'jamsimkart-listing-'.$externalId,
            );
        }

        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        if ($pageIndex >= 5) {
            return null;
        }
        if (! str_contains($html, 'page=')) {
            return null;
        }
        $base = str_contains($pageUrl, 'simcard-pricing')
            ? $pageUrl
            : 'https://www.jamsimkart.com/mci/daemi/simcard-pricing';

        return $this->pageQueryUrl($base, 'page', $pageIndex + 1);
    }
}
