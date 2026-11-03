<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\DigitNormalizer;
use App\Scraping\Support\Dom;

/**
 * Verified against tehransim.com homepage SSR cards (audit 2026-11-03).
 */
final class TehransimAdapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'tehransim';
    }

    public function name(): string
    {
        return 'tehransim.com';
    }

    public function baseUrl(): string
    {
        return 'https://tehransim.com';
    }

    public function listingSeedUrls(): array
    {
        return [
            'https://tehransim.com/',
            'https://tehransim.com/home/simkart912',
        ];
    }

    public function availableFields(): array
    {
        return ['sim_number', 'asking_price', 'line_condition', 'advertiser_contacts'];
    }

    public function capabilityNotes(): ?string
    {
        return 'صفحهٔ اول از HTML قابل استخراج است. صفحات بعدی از POST /Home/GetHomeSimkart استفاده می‌کنند.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $cards = $dom->css('div.pe-card');
        $listings = [];

        foreach ($cards as $card) {
            $phoneNode = $dom->first('div.phone-number', $card);
            $numberText = $phoneNode ? $dom->text($phoneNode) : '';
            $sim = $this->sim($numberText);
            if (! $sim) {
                // Fallback: detail link containing full number
                $link = $dom->first('a[href*="/sim-cart/"]', $card);
                $href = $link ? (string) $dom->attr($link, 'href') : '';
                $sim = $this->sim($href);
            }
            if (! $sim) {
                continue;
            }

            $priceNode = $dom->first('div.price', $card);
            $priceText = $priceNode ? $dom->text($priceNode) : '';
            $price = $this->parsePrice($priceText);

            $link = $dom->first('a[href*="/sim-cart/"]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $url = $href !== '' ? $this->abs($this->baseUrl(), $href) : $this->baseUrl().'/sim-cart/'.$sim;

            $conditionRaw = null;
            if (preg_match('#/(used|new)/#i', $href, $m)) {
                $conditionRaw = strtolower($m[1]) === 'used' ? 'کارکرده' : 'صفر';
            }
            $condition = $this->parseCondition($conditionRaw);

            $listings[] = new ParsedListing(
                externalId: $sim,
                simNumber: $sim,
                canonicalUrl: $url,
                priceAmount: $price['amount'],
                priceRaw: $price['raw'] !== '' ? $price['raw'] : $priceText,
                priceType: $price['type'],
                conditionRaw: $condition['raw'],
                conditionNormalized: $condition['normalized'],
                city: null,
                cityRaw: null,
                contacts: $this->normalizeContacts([
                    ['phone' => '02140446340', 'type' => 'landline', 'scope' => 'store', 'label' => 'تهران سیم'],
                    ['phone' => '09123000305', 'type' => 'mobile', 'scope' => 'store', 'label' => 'واتساپ فروشگاه'],
                ], $sim),
                availability: 'available',
                needsDetailFetch: false,
                rawEvidence: mb_substr($dom->text($card), 0, 200),
                sellerKey: 'tehransim-store',
            );
        }

        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        // Observed: ?page=N via client history; SSR page 1 only without AJAX POST
        if ($pageIndex >= 3) {
            return null;
        }
        if (! str_contains($html, 'page-end') && ! str_contains($html, 'pagination')) {
            return null;
        }

        return $this->pageQueryUrl(
            str_contains($pageUrl, 'simkart912') ? $pageUrl : 'https://tehransim.com/home/simkart912',
            'page',
            $pageIndex + 1
        );
    }
}
