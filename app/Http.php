<?php

declare(strict_types=1);

final class Http
{
    /** @var array<string, array{ok:bool,status:int,body:string,error:?string,final_url:string,headers:array<string,string>,fetched_at:float}> */
    private static array $memoryCache = [];

    /** @var array<string, float> host => earliest next-request unix time */
    private static array $hostBackoffUntil = [];

    /**
     * @return array{ok: bool, status: int, body: string, error: ?string, final_url: string, headers?: array<string,string>}
     */
    public static function get(string $url, int $timeout = 25, bool $useCache = true): array
    {
        $results = self::getMany([$url], $timeout, $useCache);
        return $results[$url] ?? ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'empty', 'final_url' => $url, 'headers' => []];
    }

    /**
     * POST application/x-www-form-urlencoded. Not cached.
     *
     * @param array<string, scalar|null> $fields
     * @param list<string> $extraHeaders
     * @return array{ok:bool,status:int,body:string,error:?string,final_url:string,headers:array<string,string>}
     */
    public static function postForm(string $url, array $fields, int $timeout = 25, array $extraHeaders = []): array
    {
        return self::singleRequest($url, $timeout, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
                'X-Requested-With: XMLHttpRequest',
            ], $extraHeaders),
        ]);
    }

    /**
     * POST JSON body. Not cached.
     *
     * @param array<string,mixed>|object $payload
     * @param list<string> $extraHeaders
     * @return array{ok:bool,status:int,body:string,error:?string,final_url:string,headers:array<string,string>}
     */
    public static function postJson(string $url, $payload, int $timeout = 25, array $extraHeaders = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'json_encode failed', 'final_url' => $url, 'headers' => []];
        }
        return self::singleRequest($url, $timeout, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
            ], $extraHeaders),
        ]);
    }

    /**
     * Concurrent GET for independent URLs. Respects global and per-host budgets.
     *
     * @param list<string> $urls
     * @return array<string, array{ok:bool,status:int,body:string,error:?string,final_url:string,headers:array<string,string>}>
     */
    public static function getMany(array $urls, int $timeout = 25, bool $useCache = true): array
    {
        $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
        $out = [];
        if ($urls === []) {
            return $out;
        }

        if (!function_exists('curl_multi_init') || !function_exists('curl_init')) {
            foreach ($urls as $u) {
                $out[$u] = self::singleGet($u, $timeout);
            }
            return $out;
        }

        $maxTotal = max(1, (int) config('crawl_concurrency', 8));
        $maxPerHost = max(1, (int) config('crawl_per_host', 2));
        $pending = $urls;
        $active = []; // handle id => url
        $handles = []; // url => CurlHandle
        $mh = curl_multi_init();

        $now = microtime(true);
        while ($pending !== [] || $active !== []) {
            // Fill slots
            while ($pending !== [] && count($active) < $maxTotal) {
                $url = array_shift($pending);
                if ($url === null) {
                    break;
                }
                if ($useCache && isset(self::$memoryCache[$url])) {
                    CrawlMetrics::$httpCacheHits++;
                    $cached = self::$memoryCache[$url];
                    unset($cached['fetched_at']);
                    $out[$url] = $cached;
                    continue;
                }
                $host = self::hostOf($url);
                if (isset(self::$hostBackoffUntil[$host]) && self::$hostBackoffUntil[$host] > microtime(true)) {
                    CrawlMetrics::$backoffSkips++;
                    $out[$url] = [
                        'ok' => false,
                        'status' => 429,
                        'body' => '',
                        'error' => 'backoff: host temporarily skipped',
                        'final_url' => $url,
                        'headers' => [],
                    ];
                    continue;
                }
                $hostActive = 0;
                foreach ($active as $u) {
                    if (self::hostOf($u) === $host) {
                        $hostActive++;
                    }
                }
                if ($hostActive >= $maxPerHost) {
                    // re-queue at end
                    $pending[] = $url;
                    // avoid tight spin when all pending share a saturated host
                    if (self::allPendingBlockedByHost($pending, $active, $maxPerHost)) {
                        break;
                    }
                    continue;
                }

                $ch = self::makeHandle($url, $timeout);
                curl_multi_add_handle($mh, $ch);
                $id = spl_object_id($ch);
                $active[$id] = $url;
                $handles[$url] = $ch;
            }

            if ($active === []) {
                break;
            }

            $t0 = microtime(true);
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            curl_multi_select($mh, 0.5);
            CrawlMetrics::$httpMs += (microtime(true) - $t0) * 1000;

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                $url = $active[$id] ?? '';
                unset($active[$id]);
                $parsed = self::parseHandle($ch, $url);
                curl_multi_remove_handle($mh, $ch);
                unset($ch);

                CrawlMetrics::$httpRequests++;
                if ($parsed['ok'] && $useCache) {
                    self::$memoryCache[$url] = $parsed + ['fetched_at' => microtime(true)];
                }
                if (!$parsed['ok'] && ((int) $parsed['status'] === 429 || str_contains((string) $parsed['error'], '429'))) {
                    $wait = self::retryAfterSeconds($parsed['headers'] ?? []) ?? 5.0;
                    self::$hostBackoffUntil[self::hostOf($url)] = microtime(true) + $wait;
                }
                $out[$url] = $parsed;
            }

            // If pending URLs wait only on host slots, sleep briefly
            if ($pending !== [] && $active === [] && self::allPendingBlockedByHost($pending, [], $maxPerHost)) {
                usleep(50000);
            }
            unset($now);
        }

        curl_multi_close($mh);

        // Ensure every requested URL has an entry
        foreach ($urls as $u) {
            if (!isset($out[$u])) {
                $out[$u] = ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'not fetched', 'final_url' => $u, 'headers' => []];
            }
        }
        return $out;
    }

    public static function clearMemoryCache(): void
    {
        self::$memoryCache = [];
    }

    /** @param array<string,string> $headers */
    private static function retryAfterSeconds(array $headers): ?float
    {
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'retry-after') {
                if (is_numeric($v)) {
                    return max(1.0, (float) $v);
                }
                $ts = strtotime($v);
                if ($ts !== false) {
                    return max(1.0, $ts - time());
                }
            }
        }
        return null;
    }

    private static function hostOf(string $url): string
    {
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) ? strtolower($h) : 'unknown';
    }

    /**
     * @param list<string> $pending
     * @param array<int,string> $active
     */
    private static function allPendingBlockedByHost(array $pending, array $active, int $maxPerHost): bool
    {
        if ($pending === []) {
            return false;
        }
        foreach ($pending as $url) {
            $host = self::hostOf($url);
            $n = 0;
            foreach ($active as $u) {
                if (self::hostOf($u) === $host) {
                    $n++;
                }
            }
            if ($n < $maxPerHost) {
                return false;
            }
        }
        return true;
    }

    /** @return \CurlHandle|resource */
    private static function makeHandle(string $url, int $timeout)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => (string) config('http_user_agent', 'RondinoSimple/1.0'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
            ],
        ]);
        return $ch;
    }

    /**
     * @param \CurlHandle|resource $ch
     * @return array{ok:bool,status:int,body:string,error:?string,final_url:string,headers:array<string,string>}
     */
    private static function parseHandle($ch, string $url): array
    {
        $raw = curl_multi_getcontent($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $raw = is_string($raw) ? $raw : '';
        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = self::parseHeaders($headerBlob);

        if ($errno !== 0) {
            $msg = $err !== '' ? $err : ('cURL error ' . $errno);
            if ($errno === CURLE_OPERATION_TIMEDOUT || $errno === 28) {
                $msg = 'زمان انتظار تمام شد (timeout).';
            }
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $msg, 'final_url' => $final ?: $url, 'headers' => $headers];
        }
        if ($status >= 400) {
            return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => 'HTTP ' . $status, 'final_url' => $final ?: $url, 'headers' => $headers];
        }
        return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => null, 'final_url' => $final ?: $url, 'headers' => $headers];
    }

    /**
     * @return array{ok:bool,status:int,body:string,error:?string,final_url:string,headers:array<string,string>}
     */
    private static function singleGet(string $url, int $timeout): array
    {
        return self::singleRequest($url, $timeout, []);
    }

    /**
     * @param array<int,mixed> $extraOpts
     * @return array{ok:bool,status:int,body:string,error:?string,final_url:string,headers:array<string,string>}
     */
    private static function singleRequest(string $url, int $timeout, array $extraOpts): array
    {
        $t0 = microtime(true);
        $ch = self::makeHandle($url, $timeout);
        if ($extraOpts !== []) {
            curl_setopt_array($ch, $extraOpts);
        }
        $raw = curl_exec($ch);
        if ($raw !== false && is_string($raw)) {
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = self::parseHeaders(substr($raw, 0, $headerSize));
            $body = substr($raw, $headerSize);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            CrawlMetrics::$httpMs += (microtime(true) - $t0) * 1000;
            CrawlMetrics::$httpRequests++;
            if ($errno !== 0) {
                $msg = $err !== '' ? $err : ('cURL error ' . $errno);
                if ($errno === CURLE_OPERATION_TIMEDOUT || $errno === 28) {
                    $msg = 'زمان انتظار تمام شد (timeout).';
                }
                return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $msg, 'final_url' => $final ?: $url, 'headers' => $headers];
            }
            if ($status >= 400) {
                return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => 'HTTP ' . $status, 'final_url' => $final ?: $url, 'headers' => $headers];
            }
            return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => null, 'final_url' => $final ?: $url, 'headers' => $headers];
        }
        CrawlMetrics::$httpMs += (microtime(true) - $t0) * 1000;
        CrawlMetrics::$httpRequests++;
        return self::parseHandle($ch, $url);
    }

    /** @return array<string,string> */
    private static function parseHeaders(string $blob): array
    {
        $headers = [];
        foreach (explode("\r\n", $blob) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[trim($k)] = trim($v);
            }
        }
        return $headers;
    }
}
