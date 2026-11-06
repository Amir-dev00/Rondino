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
        if ($browser !== null && !empty($browser['ok'])) {
            return $browser;
        }

        // Optional HTTP fallback only when the body is already a usable listing table.
        // Challenge/error pages must never be treated as inventory.
        $http = Http::get($url, max(30, $timeout), false);
        if (!empty($http['ok'])) {
            $body = (string) ($http['body'] ?? '');
            if (!$this->isChallengePage($body) && !$this->isUnrecognizedShell($body)) {
                $http['headers'] = array_merge($http['headers'] ?? [], [
                    'X-Rondino-Fetch' => 'http-fallback',
                    'X-Rondino-Fetch-Class' => 'success',
                ]);
                return $http;
            }
            if ($browser !== null) {
                $extra = $this->isChallengePage($body)
                    ? 'HTTP fallback reached challenge page only'
                    : 'HTTP fallback HTML had no recognizable listings';
                $browser['error'] = trim((string) ($browser['error'] ?? 'browser-fetch failed')) . '; ' . $extra;
                return $browser;
            }
            return [
                'ok' => false,
                'status' => (int) ($http['status'] ?? 0),
                'body' => '',
                'error' => $this->isChallengePage($body)
                    ? 'rond.ir HTTP challenge page without listings (browser helper unavailable/failed)'
                    : 'rond.ir HTTP response had no recognizable listings',
                'final_url' => (string) ($http['final_url'] ?? $url),
                'headers' => ['X-Rondino-Fetch' => 'http-fallback', 'X-Rondino-Fetch-Class' => 'challenge_or_shell'],
            ];
        }

        if ($browser !== null) {
            $browser['error'] = trim((string) ($browser['error'] ?? 'browser-fetch failed'))
                . '; HTTP fallback also failed: ' . (string) ($http['error'] ?? 'http fail');
            return $browser;
        }
        return null;
    }

    public function parseListPage(string $html, string $pageUrl): array
    {
        if ($this->isChallengePage($html)) {
            throw new RuntimeException(
                'rond.ir challenge page observed without listing table. Local Playwright helper required; not an empty inventory.'
            );
        }
        if ($this->isUnrecognizedShell($html)) {
            throw new RuntimeException(
                'rond.ir HTML loaded but listing table was not recognized; not an empty inventory.'
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
                // Dedupe by SIM / canonical URL identity.
                if (isset($listings[$number])) {
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
            throw new RuntimeException('rond.ir listing table produced zero verified offers; not treated as completed empty inventory.');
        }
        return array_values($listings);
    }

    private function isChallengePage(string $html): bool
    {
        return stripos($html, 'Transferring to the website') !== false
            || stripos($html, '__arcsjs') !== false;
    }

    private function isUnrecognizedShell(string $html): bool
    {
        if ($html === '' || strlen($html) < 200) {
            return true;
        }
        if ($this->isChallengePage($html)) {
            return false;
        }
        // Loaded HTML without listing anchors is an unrecognized shell, not a challenge.
        return !preg_match('#/Sims/0912[0-9]{7}#', $html);
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

        $cacheDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $token = bin2hex(random_bytes(8));
        $out = $cacheDir . DIRECTORY_SEPARATOR . 'rond-browser-' . $token . '.html';
        $diag = $cacheDir . DIRECTORY_SEPARATOR . 'rond-browser-' . $token . '.diag.json';

        $scriptsDir = $root . DIRECTORY_SEPARATOR . 'scripts';
        $cmd = [$node, $script, $url, $out, $diag];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $this->browserEnv();
        $proc = @proc_open($cmd, $descriptors, $pipes, $scriptsDir, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            $cmdLine = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' '
                . escapeshellarg($url) . ' ' . escapeshellarg($out) . ' ' . escapeshellarg($diag);
            $proc = proc_open($cmdLine, $descriptors, $pipes, $scriptsDir, $env);
        }
        if (!is_resource($proc)) {
            return null;
        }

        fclose($pipes[0]);
        $deadlineSec = max(100, $timeout + 90);
        $drain = $this->drainProcessPipes($proc, $pipes[1], $pipes[2], $deadlineSec);
        $stdout = $drain['stdout'];
        $stderr = $drain['stderr'];
        $code = $drain['code'];
        $timedOut = $drain['timed_out'];

        $body = '';
        if (is_file($out)) {
            $body = (string) file_get_contents($out);
        } elseif ($stdout !== '') {
            // Only accept stdout as success body when process succeeded.
            if ($code === 0 && !$timedOut) {
                $body = $stdout;
            }
        }

        $diagData = is_file($diag) ? json_decode((string) file_get_contents($diag), true) : null;
        $class = is_array($diagData) ? (string) ($diagData['classification'] ?? '') : '';
        $diagErr = is_array($diagData) ? (string) ($diagData['error'] ?? '') : '';

        // Always remove success path temp files; keep *.fail-*.html / diag for inspection.
        if (is_file($out)) {
            @unlink($out);
        }

        if ($timedOut) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'browser-fetch process deadline exceeded (' . $deadlineSec . 's)',
                'final_url' => $url,
                'headers' => ['X-Rondino-Fetch' => 'playwright', 'X-Rondino-Fetch-Class' => 'process_timeout'],
            ];
        }

        if ($code === 0 && $body !== '' && !$this->isChallengePage($body) && !$this->isUnrecognizedShell($body)) {
            return [
                'ok' => true,
                'status' => 200,
                'body' => $body,
                'error' => null,
                'final_url' => $url,
                'headers' => ['X-Rondino-Fetch' => 'playwright', 'X-Rondino-Fetch-Class' => 'success'],
            ];
        }

        $error = $this->mapBrowserFailure($code, $class, $diagErr, trim((string) $stderr), $body);
        return [
            'ok' => false,
            'status' => 0,
            'body' => '', // never feed failure HTML into the success parse path
            'error' => $error,
            'final_url' => $url,
            'headers' => [
                'X-Rondino-Fetch' => 'playwright',
                'X-Rondino-Fetch-Class' => $class !== '' ? $class : 'browser_failure',
                'X-Rondino-Fetch-Exit' => (string) $code,
            ],
        ];
    }

    /**
     * @param resource $proc
     * @param resource $stdout
     * @param resource $stderr
     * @return array{stdout:string,stderr:string,code:int,timed_out:bool}
     */
    private function drainProcessPipes($proc, $stdout, $stderr, int $deadlineSec): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        $out = '';
        $err = '';
        $timedOut = false;
        $start = microtime(true);
        while (true) {
            $status = proc_get_status($proc);
            $read = [];
            if (is_resource($stdout)) {
                $read[] = $stdout;
            }
            if (is_resource($stderr)) {
                $read[] = $stderr;
            }
            if ($read !== []) {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $r) {
                    $chunk = fread($r, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        if ($r === $stdout) {
                            $out .= $chunk;
                        } else {
                            $err .= $chunk;
                        }
                    }
                }
            }
            if (!($status['running'] ?? false)) {
                // Final drain
                if (is_resource($stdout)) {
                    $rest = stream_get_contents($stdout);
                    if (is_string($rest)) {
                        $out .= $rest;
                    }
                    fclose($stdout);
                }
                if (is_resource($stderr)) {
                    $rest = stream_get_contents($stderr);
                    if (is_string($rest)) {
                        $err .= $rest;
                    }
                    fclose($stderr);
                }
                $code = proc_close($proc);
                if ($code === -1 && isset($status['exitcode']) && $status['exitcode'] >= 0) {
                    $code = (int) $status['exitcode'];
                }
                return ['stdout' => $out, 'stderr' => $err, 'code' => (int) $code, 'timed_out' => false];
            }
            if ((microtime(true) - $start) >= $deadlineSec) {
                $timedOut = true;
                // Terminate child tree on Windows/Linux best-effort.
                if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
                    $pid = (int) ($status['pid'] ?? 0);
                    if ($pid > 0) {
                        @exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
                    }
                }
                @proc_terminate($proc, 9);
                if (is_resource($stdout)) {
                    fclose($stdout);
                }
                if (is_resource($stderr)) {
                    fclose($stderr);
                }
                proc_close($proc);
                return ['stdout' => $out, 'stderr' => $err, 'code' => 15, 'timed_out' => true];
            }
            usleep(50000);
        }
    }

    private function mapBrowserFailure(int $code, string $class, string $diagErr, string $stderr, string $body): string
    {
        if ($class === 'connection_failure' || $class === 'dns_failure' || $class === 'tls_failure' || $code === 10) {
            return 'browser-fetch network failure'
                . ($diagErr !== '' ? (': ' . $diagErr) : ($stderr !== '' ? (': ' . $this->clip($stderr)) : ''));
        }
        if ($class === 'navigation_timeout' || $code === 11) {
            return 'browser-fetch navigation timeout'
                . ($diagErr !== '' ? (': ' . $diagErr) : '');
        }
        if ($class === 'http_error' || $code === 12) {
            return 'browser-fetch HTTP error'
                . ($diagErr !== '' ? (': ' . $diagErr) : '');
        }
        if ($class === 'challenge_observed' || $code === 13 || $this->isChallengePage($body)) {
            return 'browser-fetch challenge page observed without listings'
                . ($diagErr !== '' ? (': ' . $diagErr) : '');
        }
        if ($class === 'no_listings' || $code === 14) {
            return 'browser-fetch page loaded without recognizable listings'
                . ($diagErr !== '' ? (': ' . $diagErr) : '');
        }
        if ($class === 'deadline_exceeded' || $code === 15) {
            return 'browser-fetch overall deadline exceeded';
        }
        if ($stderr !== '') {
            // Preserve specific stderr (e.g. net::ERR_CONNECTION_TIMED_OUT) instead of generic challenge text.
            return 'browser-fetch failed: ' . $this->clip($stderr);
        }
        return 'browser-fetch failed (exit ' . $code . ')'
            . ($diagErr !== '' ? (': ' . $diagErr) : '');
    }

    private function clip(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        return mb_substr($s, 0, 500);
    }

    /** @return array<string,string> */
    private function browserEnv(): array
    {
        $env = [];
        foreach ([
            'PATH', 'SystemRoot', 'USERPROFILE', 'HOME', 'LOCALAPPDATA', 'TEMP', 'TMP',
            'PATHEXT', 'ComSpec', 'RONDINO_BROWSER_PROXY', 'RONDINO_BROWSER_FALLBACK_PROXY',
            'RONDINO_BROWSER_DEADLINE_MS', 'RONDINO_BROWSER_GOTO_MS',
            'HTTPS_PROXY', 'HTTP_PROXY', 'https_proxy', 'http_proxy', 'NO_PROXY', 'no_proxy',
        ] as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                $env[$k] = $v;
            }
        }
        // Chromium does not automatically use Windows IE proxy settings.
        if (empty($env['RONDINO_BROWSER_PROXY']) && empty($env['HTTPS_PROXY']) && empty($env['HTTP_PROXY'])) {
            $sys = $this->windowsSystemProxy();
            if ($sys !== null) {
                // Prefer direct first; expose system proxy as fallback for connection timeouts.
                $env['RONDINO_BROWSER_FALLBACK_PROXY'] = $sys;
            }
        }
        return $env;
    }

    private function windowsSystemProxy(): ?string
    {
        if (stripos(PHP_OS_FAMILY, 'Windows') !== 0) {
            return null;
        }
        $enableOut = [];
        @exec('reg query "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Internet Settings" /v ProxyEnable 2>NUL', $enableOut);
        $enabled = false;
        foreach ($enableOut as $line) {
            if (preg_match('/ProxyEnable\s+REG_DWORD\s+0x1\b/i', $line)) {
                $enabled = true;
                break;
            }
        }
        if (!$enabled) {
            return null;
        }
        $serverOut = [];
        @exec('reg query "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Internet Settings" /v ProxyServer 2>NUL', $serverOut);
        foreach ($serverOut as $line) {
            if (preg_match('/ProxyServer\s+REG_SZ\s+(.+)\s*$/i', $line, $m)) {
                $server = trim($m[1]);
                if ($server === '') {
                    return null;
                }
                if (str_contains($server, '=')) {
                    // Per-protocol list, e.g. http=127.0.0.1:10808;https=127.0.0.1:10808
                    if (preg_match('/(?:https|http)=([^;]+)/i', $server, $mm)) {
                        $server = trim($mm[1]);
                    }
                }
                return str_contains($server, '://') ? $server : ('http://' . $server);
            }
        }
        return null;
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
