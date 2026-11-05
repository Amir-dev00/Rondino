<?php

declare(strict_types=1);

/** In-process crawl counters for benchmarks and worker logs. */
final class CrawlMetrics
{
    public static float $httpMs = 0.0;
    public static int $httpRequests = 0;
    public static int $httpCacheHits = 0;
    public static int $detailRequests = 0;
    public static int $detailAvoided = 0;
    public static float $parseMs = 0.0;
    public static float $storeMs = 0.0;
    public static int $sqlStatements = 0;
    public static int $retries = 0;
    public static int $backoffSkips = 0;

    public static function reset(): void
    {
        self::$httpMs = 0.0;
        self::$httpRequests = 0;
        self::$httpCacheHits = 0;
        self::$detailRequests = 0;
        self::$detailAvoided = 0;
        self::$parseMs = 0.0;
        self::$storeMs = 0.0;
        self::$sqlStatements = 0;
        self::$retries = 0;
        self::$backoffSkips = 0;
    }

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        return [
            'http_requests' => self::$httpRequests,
            'http_cache_hits' => self::$httpCacheHits,
            'http_ms' => round(self::$httpMs, 1),
            'detail_requests' => self::$detailRequests,
            'detail_avoided' => self::$detailAvoided,
            'parse_ms' => round(self::$parseMs, 1),
            'store_ms' => round(self::$storeMs, 1),
            'sql_statements' => self::$sqlStatements,
            'retries' => self::$retries,
            'backoff_skips' => self::$backoffSkips,
        ];
    }
}
