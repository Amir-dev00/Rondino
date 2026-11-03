<?php

namespace App\Scraping\Adapters;

use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\Dom;

/**
 * Same card family as cafesimcart (.simdetail) — verified on rond0912.com (audit 2026-11-03).
 */
final class Rond0912Adapter extends AbstractSourceAdapter
{
    public function slug(): string
    {
        return 'rond0912';
    }

    public function name(): string
    {
        return 'rond0912.com';
    }

    public function baseUrl(): string
    {
        return 'https://rond0912.com';
    }

    public function listingSeedUrls(): array
    {
        return ['https://rond0912.com/'];
    }

    public function availableFields(): array
    {
        return ['sim_number', 'asking_price', 'line_condition', 'advertiser_contacts'];
    }

    public function capabilityNotes(): ?string
    {
        return 'صفحهٔ خانگی بسیار حجیم (~1MB). کارت‌ها با .simdetail قابل استخراج‌اند.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $cards = $dom->css('div.simdetail');
        $listings = [];

        foreach ($cards as $card) {
            $simNode = $dom->first('div.simno', $card);
            $sim = $this->sim($simNode ? $dom->text($simNode) : '');
            if (! $sim) {
                continue;
            }

            $priceNode = $dom->first('span.pricebold', $card);
            $priceText = ($priceNode ? $dom->text($priceNode) : '').' تومان';
            $price = $this->parsePrice($priceText);

            $conditionRaw = null;
            $dateBlock = $dom->first('div.dateblock', $card) ?? $dom->first('.dateblock', $card);
            if ($dateBlock) {
                $conditionRaw = $dom->text($dateBlock);
            }
            $op = $dom->first('div.simoperator', $card);
            if ($op) {
                $conditionRaw = trim(($conditionRaw ? $conditionRaw.' ' : '').$dom->text($op));
            }
            $condition = $this->parseCondition($conditionRaw);

            $link = $dom->first('a[href]', $card);
            $href = $link ? (string) $dom->attr($link, 'href') : '';
            $externalId = $sim;
            if ($href && preg_match('#/(\d+)/#', $href, $m)) {
                $externalId = $m[1];
            }

            $listings[] = new ParsedListing(
                externalId: (string) $externalId,
                simNumber: $sim,
                canonicalUrl: $href !== '' ? $this->abs($this->baseUrl(), $href) : $this->baseUrl().'/',
                priceAmount: $price['amount'],
                priceRaw: $price['raw'],
                priceType: $price['type'],
                conditionRaw: $condition['raw'],
                conditionNormalized: $condition['normalized'],
                city: null,
                cityRaw: null,
                contacts: $this->normalizeContacts([
                    ['phone' => '02165106887', 'type' => 'landline', 'scope' => 'store', 'label' => 'رند ۰۹۱۲'],
                    ['phone' => '09126672925', 'type' => 'mobile', 'scope' => 'store', 'label' => 'رند ۰۹۱۲'],
                ], $sim),
                availability: 'available',
                sellerKey: 'rond0912-store',
            );
        }

        return $listings;
    }
}
