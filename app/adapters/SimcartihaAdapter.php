<?php

declare(strict_types=1);

/**
 * Inventory is the TablePress DataTable (#tablepress-16) embedded on the homepage.
 * Client-side DataTables filters that table ("1906 شماره پیدا شد"); WooCommerce Store API
 * only exposes a handful of shop products and is not the listing source.
 */
final class SimcartihaAdapter extends BaseAdapter
{
    public function slug(): string { return 'simcartiha'; }
    public function name(): string { return 'simcartiha.ir'; }
    public function baseUrl(): string { return 'https://simcartiha.ir'; }

    public function seedUrls(): array
    {
        return ['https://simcartiha.ir/'];
    }

    public function notes(): string
    {
        return 'جدول TablePress روی صفحه اصلی (فیلتر DataTables سمت‌کلاینت). API فروشگاهی ووکامرس موجودی کامل نیست.';
    }

    public function fetchPage(string $url, int $timeout = 25): ?array
    {
        if (!str_contains($url, 'simcartiha.ir')) {
            return null;
        }
        // Homepage HTML is large (~0.6–0.7MB); allow longer timeout and a couple of retries.
        $wait = max($timeout, 60);
        $last = null;
        for ($try = 0; $try < 3; $try++) {
            if ($try > 0) {
                usleep(500000 * $try);
            }
            $last = Http::get($url, $wait, false);
            if (!empty($last['ok']) && strlen((string) ($last['body'] ?? '')) > 1000) {
                return $last;
            }
        }
        return $last;
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $payload = $this->parseListPayload($html, $pageUrl);
        return $payload['listings'];
    }

    /**
     * @return array{
     *   listings: list<array<string,mixed>>,
     *   source_record_ids: list<string>,
     *   source_record_count: int,
     *   excluded: int
     * }
     */
    public function parseListPayload(string $html, string $pageUrl): array
    {
        // Reject WC JSON / error objects before HTML heuristics.
        $trimmed = ltrim($html);
        if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
            $json = json_decode($html, true);
            if (is_array($json)) {
                if (isset($json['code']) || isset($json['error'])) {
                    throw new RuntimeException(
                        'JSON خطا از سیمکارتیها: ' . (string) ($json['code'] ?? $json['error'] ?? 'unknown')
                    );
                }
                throw new RuntimeException(
                    'پاسخ JSON ووکامرس به‌جای جدول TablePress دریافت شد؛ این API موجودی کامل نیست.'
                );
            }
        }

        if ($this->isChallengeOrError($html)) {
            throw new RuntimeException('پاسخ سیمکارتیها چالش/خطا است؛ موجودی خالی محسوب نمی‌شود.');
        }

        if (!preg_match('/id=["\']tablepress-16["\']/i', $html)) {
            throw new RuntimeException('جدول tablepress-16 در صفحه پیدا نشد؛ پاسخ ناشناخته است.');
        }

        $dom = Dom::fromHtml($html);
        $table = null;
        foreach ($dom->css('table') as $candidate) {
            $id = (string) $dom->attr($candidate, 'id');
            if (strcasecmp($id, 'tablepress-16') === 0) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            throw new RuntimeException('جدول tablepress-16 قابل بارگذاری نبود.');
        }

        $listings = [];
        $sourceIds = [];
        $excluded = 0;
        foreach ($dom->xpath('.//tbody/tr', $table) as $row) {
            $cells = $dom->css('td', $row);
            if (count($cells) < 3) {
                $excluded++;
                continue;
            }
            $numberText = $dom->text($cells[0]);
            $priceText = $dom->text($cells[1]);
            $conditionText = $dom->text($cells[2]);
            $priceNumeric = isset($cells[4]) ? $dom->text($cells[4]) : '';

            $sim = $this->sim($numberText);
            $rowKey = $sim ?: ('row:' . Digits::only(Digits::normalize($numberText . '|' . $priceNumeric)));
            $sourceIds[] = $rowKey;

            if ($sim === null) {
                $excluded++;
                continue;
            }

            $price = $this->priceFromCells($priceText, $priceNumeric);
            $condition = $this->condition($conditionText !== '' ? $conditionText : null);

            // Listings live in the homepage inventory table (no per-SIM product pages).
            $canonical = $this->baseUrl() . '/#' . $sim;

            $listings[$sim] = $this->listing([
                'external_id' => $sim,
                'sim_number' => $sim,
                'canonical_url' => $canonical,
                'price_amount' => $price['amount'],
                'price_raw' => $price['raw'],
                'price_type' => $price['type'],
                'condition_raw' => $condition['raw'],
                'condition_normalized' => $condition['normalized'],
                'city' => null,
                'city_raw' => null,
                'contacts' => $this->contacts($this->storeContacts(), $sim),
                'seller_key' => 'simcartiha-store',
            ]);
        }

        if ($sourceIds === []) {
            throw new RuntimeException('هیچ ردیف آگهی در جدول TablePress نبود؛ موجودی خالی تأیید نشد.');
        }

        return [
            'listings' => array_values($listings),
            'source_record_ids' => $sourceIds,
            'source_record_count' => count($sourceIds),
            'excluded' => $excluded,
        ];
    }

    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        // Full inventory is in one SSR TablePress table; DataTables paginates client-side only.
        return null;
    }

    /** @return list<array{phone:string,type:string,scope:string,label:string}> */
    private function storeContacts(): array
    {
        return [
            ['phone' => '09122373003', 'type' => 'mobile', 'scope' => 'store', 'label' => 'سیمکارتیها'],
            ['phone' => '02166677836', 'type' => 'landline', 'scope' => 'store', 'label' => 'سیمکارتیها'],
            ['phone' => '02166677904', 'type' => 'landline', 'scope' => 'store', 'label' => 'سیمکارتیها'],
        ];
    }

    /** @return array{amount:?int,type:string,raw:string,unit:?string} */
    private function priceFromCells(string $priceText, string $priceNumeric): array
    {
        $normText = Digits::normalize(trim($priceText));
        if ($normText === '' || preg_match('/تماس|call/ui', $normText)) {
            return ['amount' => null, 'type' => 'call', 'raw' => $priceText, 'unit' => null];
        }
        $digits = Digits::only(Digits::normalize($priceNumeric));
        if ($digits !== '' && ctype_digit($digits) && (int) $digits > 0) {
            return [
                'amount' => (int) $digits,
                'type' => 'fixed',
                'raw' => $priceText !== '' ? $priceText : ($digits . ' تومان'),
                'unit' => 'toman',
            ];
        }
        $parsed = $this->price($priceText . (preg_match('/تومان/u', $priceText) ? '' : ' تومان'));
        if (($parsed['amount'] ?? null) === null && ($parsed['type'] ?? '') === 'unknown') {
            return ['amount' => null, 'type' => 'call', 'raw' => $priceText, 'unit' => null];
        }
        return $parsed;
    }

    private function isChallengeOrError(string $html): bool
    {
        if ($html === '' || strlen($html) < 200) {
            return true;
        }
        if (stripos($html, 'Transferring to the website') !== false || stripos($html, '__arcsjs') !== false) {
            return true;
        }
        return false;
    }
}
