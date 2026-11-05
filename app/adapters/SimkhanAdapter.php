<?php

declare(strict_types=1);

final class SimkhanAdapter extends BaseAdapter
{
    private const API = 'https://api.simkhan.ir/api/Simcard/getsimcardListMainPage';
    private const PAGE_SIZE = 15;

    public function slug(): string { return 'simkhan'; }
    public function name(): string { return 'simkhan.ir'; }
    public function baseUrl(): string { return 'https://simkhan.ir'; }

    public function seedUrls(): array
    {
        $urls = [];
        // Path /search/mci/permanent/0912/empty/{n5} → n5 is leading digit after 0912
        for ($n5 = 0; $n5 <= 9; $n5++) {
            $urls[] = self::API . '?n5=' . $n5 . '&PageNumber=1';
        }
        return $urls;
    }

    public function notes(): string
    {
        return 'API عمومی api.simkhan.ir با PageNumber/NumberOnPage/AreaCode؛ لیست‌های Next SSR خالی‌اند.';
    }

    public function fetchPage(string $url, int $timeout = 25): ?array
    {
        if (!str_contains($url, 'getsimcardListMainPage')) {
            return null;
        }
        $n5 = '0';
        $page = 1;
        $q = parse_url($url, PHP_URL_QUERY);
        if (is_string($q)) {
            parse_str($q, $params);
            if (isset($params['n5']) && $params['n5'] !== '') {
                $n5 = (string) $params['n5'];
            }
            if (isset($params['PageNumber']) && ctype_digit((string) $params['PageNumber'])) {
                $page = (int) $params['PageNumber'];
            }
        }
        $payload = [
            'AllowReservation' => 2,
            'NumberOnPage' => self::PAGE_SIZE,
            'PageNumber' => $page,
            'StatuRounds' => 2,
            'OperatorIDList' => [1],
            'RoundTypeIDList' => [],
            'TypeIDList' => [1],
            'WorkStatusIDList' => [],
            'AreaCode' => '0912',
            'n5' => $n5,
        ];
        $res = Http::postJson(self::API, $payload, $timeout, [
            'Origin: https://simkhan.ir',
            'Referer: https://simkhan.ir/search/mci/permanent/0912/empty/' . rawurlencode($n5),
        ]);
        $res['final_url'] = $url;
        return $res;
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $json = json_decode($html, true);
        $rows = is_array($json['SimCardListRows'] ?? null) ? $json['SimCardListRows'] : [];
        $listings = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sim = $this->sim((string) ($row['SimCardNumber'] ?? $row['SimCardShowNumber'] ?? ''));
            if (!$sim) {
                continue;
            }
            $priceText = (string) ($row['Price'] ?? '');
            if ($priceText !== '' && !preg_match('/تومان|tooman|toman/ui', Digits::normalize($priceText))) {
                $priceText .= ' تومان';
            }
            $price = $this->price($priceText);
            $conditionRaw = (string) ($row['SimCardWorkStatusName'] ?? $row['SimCardDescription'] ?? '');
            $condition = $this->condition($conditionRaw !== '' ? $conditionRaw : null);
            $city = null;
            $cityRaw = null;
            if (!empty($row['CityName'])) {
                $cityRaw = (string) $row['CityName'];
                $city = $cityRaw;
            } elseif (!empty($row['StateName'])) {
                $cityRaw = (string) $row['StateName'];
                $city = $cityRaw;
            } elseif (!empty($row['SimCardLocationName'])) {
                $cityRaw = (string) $row['SimCardLocationName'];
            }
            $contacts = [];
            foreach (['MobileNumber', 'CallInfo'] as $field) {
                $raw = $row[$field] ?? null;
                if (is_string($raw) && $raw !== '') {
                    $contacts[] = ['phone' => $raw, 'type' => 'mobile', 'scope' => 'seller', 'label' => null];
                } elseif (is_array($raw)) {
                    foreach (['Mobile', 'Tel', 'Phone', 'Number'] as $k) {
                        if (!empty($raw[$k])) {
                            $contacts[] = ['phone' => (string) $raw[$k], 'type' => 'mobile', 'scope' => 'seller', 'label' => null];
                        }
                    }
                }
            }
            $store = is_array($row['StoreInfo'] ?? null) ? $row['StoreInfo'] : [];
            foreach (['Mobile', 'Mobile2', 'Tel1', 'Tel2'] as $k) {
                if (!empty($store[$k])) {
                    $contacts[] = [
                        'phone' => (string) $store[$k],
                        'type' => str_starts_with(Digits::only((string) $store[$k]), '09') ? 'mobile' : 'landline',
                        'scope' => 'store',
                        'label' => (string) ($store['StoreName'] ?? 'سیم‌خان'),
                    ];
                }
            }
            $id = (string) ($row['SimCardID'] ?? $sim);
            $seller = (string) ($store['StoreName'] ?? $row['SellerTypeName'] ?? 'simkhan');
            $listings[] = $this->listing([
                'external_id' => $id,
                'sim_number' => $sim,
                'canonical_url' => $this->baseUrl() . '/Simcard/' . $sim,
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'],
                'price_type' => $price['type'],
                'condition_raw' => $condition['raw'],
                'condition_normalized' => $condition['normalized'],
                'city' => $city,
                'city_raw' => $cityRaw,
                'contacts' => $this->contacts($contacts, $sim),
                'seller_key' => 'simkhan-' . md5($seller . '|' . $id),
            ]);
        }
        return $listings;
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        $json = json_decode($html, true);
        if (!is_array($json)) {
            return null;
        }
        $rows = is_array($json['SimCardListRows'] ?? null) ? $json['SimCardListRows'] : [];
        $total = (int) ($json['TotalRecord'] ?? 0);
        if ($rows === [] || $total <= 0) {
            return null;
        }
        $next = $pageIndex + 1;
        if (($next - 1) * self::PAGE_SIZE >= $total) {
            return null;
        }
        $n5 = '0';
        $q = parse_url($pageUrl, PHP_URL_QUERY);
        if (is_string($q)) {
            parse_str($q, $params);
            if (isset($params['n5'])) {
                $n5 = (string) $params['n5'];
            }
        }
        return self::API . '?n5=' . rawurlencode($n5) . '&PageNumber=' . $next;
    }
}
