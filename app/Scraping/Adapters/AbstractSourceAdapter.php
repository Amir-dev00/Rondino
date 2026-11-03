<?php

namespace App\Scraping\Adapters;

use App\Scraping\Contracts\SourceAdapterInterface;
use App\Scraping\DTO\ParsedListing;
use App\Scraping\Support\ConditionNormalizer;
use App\Scraping\Support\DigitNormalizer;
use App\Scraping\Support\Dom;
use App\Scraping\Support\PriceNormalizer;

abstract class AbstractSourceAdapter implements SourceAdapterInterface
{
    public function parseDetailPage(string $html, string $pageUrl, ParsedListing $existing): ParsedListing
    {
        return $existing;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        return null;
    }

    public function isFetchableOnSharedHosting(): bool
    {
        return true;
    }

    /**
     * @param  list<array{phone: string, type?: string, scope?: string, label?: ?string}>  $contacts
     * @return list<array{phone: string, type: string, scope: string, label: ?string}>
     */
    protected function normalizeContacts(array $contacts, ?string $soldSim = null): array
    {
        $out = [];
        $seen = [];
        foreach ($contacts as $c) {
            $phone = DigitNormalizer::digitsOnly(DigitNormalizer::normalizeDigits($c['phone'] ?? ''));
            if ($phone === '') {
                continue;
            }
            // Never treat the SIM being sold as a contact
            if ($soldSim && $phone === $soldSim) {
                continue;
            }
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $type = $c['type'] ?? (str_starts_with($phone, '09') ? 'mobile' : 'landline');
            $out[] = [
                'phone' => $phone,
                'type' => $type,
                'scope' => $c['scope'] ?? 'store',
                'label' => $c['label'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array{amount: ?int, type: string, raw: string, unit: ?string}
     */
    protected function parsePrice(?string $raw): array
    {
        return PriceNormalizer::parse($raw);
    }

    /**
     * @return array{raw: ?string, normalized: string}
     */
    protected function parseCondition(?string $raw): array
    {
        return ConditionNormalizer::normalize($raw);
    }

    protected function sim(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return DigitNormalizer::extractSim0912($raw);
    }

    protected function abs(string $base, string $href): string
    {
        return Dom::absoluteUrl($base, $href);
    }

    protected function pageQueryUrl(string $pageUrl, string $param, int $page): string
    {
        $parts = parse_url($pageUrl);
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query[$param] = $page;
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.$path.'?'.http_build_query($query);
    }
}
