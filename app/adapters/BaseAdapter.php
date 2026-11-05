<?php

declare(strict_types=1);

abstract class BaseAdapter implements AdapterInterface
{
    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        return null;
    }

    public function notes(): string
    {
        return '';
    }

    /**
     * Optional non-GET fetch. Return null to use CrawlEngine's default Http::get.
     *
     * @return null|array{ok:bool,status:int,body:string,error:?string,final_url:string,headers?:array<string,string>}
     */
    public function fetchPage(string $url, int $timeout = 25): ?array
    {
        return null;
    }

    /**
     * @param list<array{phone?:string,type?:string,scope?:string,label?:?string}> $contacts
     * @return list<array{phone:string,type:string,scope:string,label:?string}>
     */
    protected function contacts(array $contacts, ?string $soldSim = null): array
    {
        $out = [];
        $seen = [];
        foreach ($contacts as $c) {
            $phone = Digits::only(Digits::normalize((string) ($c['phone'] ?? '')));
            if ($phone === '' || ($soldSim && $phone === $soldSim) || isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $out[] = [
                'phone' => $phone,
                'type' => $c['type'] ?? (str_starts_with($phone, '09') ? 'mobile' : 'landline'),
                'scope' => $c['scope'] ?? 'store',
                'label' => $c['label'] ?? null,
            ];
        }
        return $out;
    }

    protected function sim(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return Digits::extractSim0912($raw);
    }

    /** @return array{amount:?int,type:string,raw:string,unit:?string} */
    protected function price(?string $raw): array
    {
        return Price::parse($raw);
    }

    /** @return array{raw:?string,normalized:string} */
    protected function condition(?string $raw): array
    {
        return Condition::normalize($raw);
    }

    protected function abs(string $href): string
    {
        return absolute_url($this->baseUrl(), $href);
    }

    /** @param array<string,mixed> $data */
    protected function listing(array $data): array
    {
        return array_merge([
            'external_id' => '',
            'sim_number' => null,
            'canonical_url' => $this->baseUrl(),
            'price_amount' => null,
            'price_raw' => null,
            'price_type' => 'unknown',
            'condition_raw' => null,
            'condition_normalized' => 'unknown',
            'city' => null,
            'city_raw' => null,
            'contacts' => [],
            'availability' => 'available',
            'seller_key' => null,
        ], $data);
    }
}
