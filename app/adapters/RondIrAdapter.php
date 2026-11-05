<?php

declare(strict_types=1);

final class RondIrAdapter extends BaseAdapter
{
    public function slug(): string { return 'rond-ir'; }
    public function name(): string { return 'rond.ir'; }
    public function baseUrl(): string { return 'https://rond.ir'; }

    public function seedUrls(): array
    {
        return ['https://rond.ir/'];
    }

    public function notes(): string
    {
        return 'استخراج جدول صفحه اصلی؛ پوشش کامل موجودی و دریافت تماس از صفحه جزئیات هنوز تایید نشده است.';
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        $dom = Dom::fromHtml($html);
        $listings = [];
        foreach ($dom->css('table') as $table) {
            $columns = [];
            foreach ($dom->css('tr', $table) as $row) {
                $cells = $dom->xpath('./th | ./td', $row);
                $headers = [];
                foreach ($cells as $index => $cell) {
                    $text = $dom->text($cell);
                    if ($text === 'شماره') {
                        $headers['number'] = $index;
                    } elseif (str_contains($text, 'قیمت') && str_contains($text, 'تومان')) {
                        $headers['price'] = $index;
                    } elseif ($text === 'وضعیت') {
                        $headers['condition'] = $index;
                    }
                }
                if (isset($headers['number'], $headers['price'])) {
                    $columns = $headers;
                    continue;
                }
                if (!isset($columns['number'], $columns['price'])) {
                    continue;
                }
                $numberCell = $cells[$columns['number']] ?? null;
                $priceCell = $cells[$columns['price']] ?? null;
                if (!$numberCell || !$priceCell) {
                    continue;
                }
                $numberText = Digits::normalize($dom->text($numberCell));
                $number = preg_replace('/[\s\x{200c}\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}-]+/u', '', $numberText);
                if (!is_string($number) || !preg_match('/^0912[0-9]{7}$/D', $number)) {
                    continue;
                }
                $canonical = null;
                foreach ($dom->css('a[href]', $row) as $link) {
                    $url = $this->abs((string) $dom->attr($link, 'href'));
                    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    if (in_array($host, ['rond.ir', 'www.rond.ir'], true)
                        && preg_match('#^/Sims/' . $number . '/?$#i', $path)) {
                        $canonical = $url;
                        break;
                    }
                }
                if ($canonical === null) {
                    continue;
                }
                $priceText = $dom->text($priceCell);
                $price = $this->price($priceText === '' ? null : $priceText . ' تومان');
                $conditionCell = isset($columns['condition']) ? ($cells[$columns['condition']] ?? null) : null;
                $condition = $this->condition($conditionCell ? $dom->text($conditionCell) : null);
                $listings[$number] = $this->listing([
                    'external_id' => $number,
                    'sim_number' => $number,
                    'canonical_url' => $canonical,
                    'price_amount' => $price['amount'],
                    'price_raw' => $priceText,
                    'price_type' => $price['type'],
                    'condition_raw' => $condition['raw'],
                    'condition_normalized' => $condition['normalized'],
                ]);
            }
        }
        if ($listings === []) {
            throw new RuntimeException('جدول آگهی رند شناسایی نشد؛ پاسخ خالی یا صفحه انتقال، پایان موجودی محسوب نمی شود.');
        }
        return array_values($listings);
    }
}
