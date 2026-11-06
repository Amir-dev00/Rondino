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
        return 'جدول صفحه اصلی پس از چالش ArvanCloud؛ استخراج محلی اختیاری با Playwright (scripts/browser-fetch.mjs). روی cPanel بدون مرورگر قابل اتکا نیست.';
    }

    public function fetchPage(string $url, int $timeout = 25): ?array
    {
        if (!str_contains($url, 'rond.ir')) {
            return null;
        }
        $browser = $this->fetchViaBrowser($url, $timeout);
        if ($browser !== null) {
            return $browser;
        }
        // Fall through to default Http::get; parseListPage will reject challenge/SPA shells.
        return null;
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        if ($this->isChallengeOrShell($html)) {
            throw new RuntimeException(
                'rond.ir challenge/SPA shell without listing table. Local Playwright helper required; not an empty inventory.'
            );
        }
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
                    $cand = $this->abs((string) $dom->attr($link, 'href'));
                    $host = strtolower((string) parse_url($cand, PHP_URL_HOST));
                    $path = (string) parse_url($cand, PHP_URL_PATH);
                    if (in_array($host, ['rond.ir', 'www.rond.ir', ''], true)
                        && preg_match('#^/Sims/' . $number . '/?$#i', $path)) {
                        $canonical = str_starts_with($cand, 'http') ? $cand : $this->baseUrl() . '/Sims/' . $number;
                        break;
                    }
                }
                if ($canonical === null) {
                    // Table fragment from browser helper may lack host; accept /Sims/{number}
                    foreach ($dom->css('a[href]', $row) as $link) {
                        $href = (string) $dom->attr($link, 'href');
                        if (preg_match('#/Sims/' . $number . '/?$#i', $href)) {
                            $canonical = $this->baseUrl() . '/Sims/' . $number;
                            break;
                        }
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

    private function isChallengeOrShell(string $html): bool
    {
        if (stripos($html, 'Transferring to the website') !== false || stripos($html, '__arcsjs') !== false) {
            return true;
        }
        // Cookied SPA shell: HTML without listing anchors/table headers the parser needs
        if (!str_contains($html, '/Sims/') && !preg_match('/<th[^>]*>\s*شماره\s*<\/th>/u', $html)) {
            return true;
        }
        return false;
    }

    /**
     * @return null|array{ok:bool,status:int,body:string,error:?string,final_url:string,headers:array<string,string>}
     */
    private function fetchViaBrowser(string $url, int $timeout): ?array
    {
        $root = dirname(__DIR__, 2);
        $script = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'browser-fetch.mjs';
        if (!is_file($script)) {
            return null;
        }
        $node = $this->findNode();
        if ($node === null) {
            return null;
        }
        $out = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'rond-browser.html';
        if (!is_dir(dirname($out))) {
            @mkdir(dirname($out), 0775, true);
        }
        if (is_file($out)) {
            @unlink($out);
        }
        $scriptsDir = $root . DIRECTORY_SEPARATOR . 'scripts';
        $cmd = [$node, $script, $url, $out];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = null;
        if (function_exists('getenv')) {
            $env = [];
            foreach (['PATH', 'SystemRoot', 'USERPROFILE', 'HOME', 'LOCALAPPDATA', 'TEMP', 'TMP', 'PATHEXT', 'ComSpec'] as $k) {
                $v = getenv($k);
                if ($v !== false) {
                    $env[$k] = $v;
                }
            }
        }
        $proc = @proc_open($cmd, $descriptors, $pipes, $scriptsDir, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            // Fallback for older PHP / Windows shells.
            $cmdLine = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($out);
            $proc = proc_open($cmdLine, $descriptors, $pipes, $scriptsDir, $env);
        }
        if (!is_resource($proc)) {
            return null;
        }
        $waitSec = max(90, $timeout + 75);
        stream_set_timeout($pipes[1], $waitSec);
        stream_set_timeout($pipes[2], $waitSec);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $body = is_file($out) ? (string) file_get_contents($out) : (string) $stdout;
        if ($code !== 0 || $body === '' || $this->isChallengeOrShell($body)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => $body,
                'error' => 'browser-fetch failed: ' . trim((string) $stderr),
                'final_url' => $url,
                'headers' => [],
            ];
        }
        return [
            'ok' => true,
            'status' => 200,
            'body' => $body,
            'error' => null,
            'final_url' => $url,
            'headers' => ['X-Rondino-Fetch' => 'playwright'],
        ];
    }

    private function findNode(): ?string
    {
        $which = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where node' : 'command -v node';
        $out = [];
        $code = 0;
        @exec($which, $out, $code);
        if ($code === 0 && isset($out[0]) && $out[0] !== '') {
            return trim($out[0]);
        }
        return null;
    }
}
