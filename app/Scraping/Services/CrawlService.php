<?php

namespace App\Scraping\Services;

use App\Models\CrawlCheckpoint;
use App\Models\CrawlRun;
use App\Models\Source;
use App\Scraping\AdapterRegistry;
use App\Scraping\Http\HttpClient;
use Throwable;

final class CrawlService
{
    public function __construct(
        private OfferUpserter $upserter,
        private HttpClient $http,
    ) {}

    /**
     * @return array{run: CrawlRun, message: string}
     */
    public function crawlSource(Source $source, string $trigger = 'cron'): array
    {
        $checkpoint = CrawlCheckpoint::query()->firstOrCreate(
            ['source_id' => $source->id],
            ['cursor' => []]
        );

        if ($checkpoint->locked_until && $checkpoint->locked_until->isFuture()) {
            return [
                'run' => CrawlRun::query()->create([
                    'source_id' => $source->id,
                    'status' => 'failed',
                    'trigger' => $trigger,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'error' => 'منبع در حال اجرای همپوشان است (قفل فعال).',
                ]),
                'message' => 'locked',
            ];
        }

        $maxRuntime = (int) config('rondino.crawl.default_max_runtime_seconds', 110);
        $deadline = time() + $maxRuntime;

        $checkpoint->update(['locked_until' => now()->addSeconds($maxRuntime + 30)]);

        $run = CrawlRun::query()->create([
            'source_id' => $source->id,
            'status' => 'running',
            'trigger' => $trigger,
            'started_at' => now(),
            'progress' => ['seed_index' => 0, 'page' => 1],
        ]);

        $source->update([
            'last_attempt_at' => now(),
            'last_error' => null,
            'last_error_type' => null,
        ]);

        $adapter = AdapterRegistry::make($source->slug);
        $http = $this->http->withRateLimit($source->rate_limit_ms);

        $cursor = $checkpoint->cursor ?? [];
        $seedIndex = (int) ($cursor['seed_index'] ?? 0);
        $page = (int) ($cursor['page'] ?? 1);
        $seeds = $source->listing_urls ?: $adapter->listingSeedUrls();

        $requests = 0;
        $pages = 0;
        $parsed = 0;
        $upserted = 0;
        $seenIds = [];
        $errors = [];
        $hadHttpSuccess = false;
        $hadParseSuccess = false;

        try {
            if (! $adapter->isFetchableOnSharedHosting()) {
                throw new \RuntimeException($adapter->capabilityNotes() ?: 'منبع روی هاست اشتراکی قابل دریافت نیست.');
            }

            while ($seedIndex < count($seeds) && $requests < $source->max_requests_per_run && time() < $deadline) {
                $seedUrl = $seeds[$seedIndex];
                $url = $page <= 1 ? $seedUrl : ($cursor['next_url'] ?? $seedUrl);

                $result = $http->get($url);
                $requests++;
                $pages++;

                if (! $result->ok()) {
                    $errors[] = $url.': '.($result->error ?? 'http error');
                    $source->update([
                        'last_error' => $result->error,
                        'last_error_type' => $result->status === 0 ? 'timeout' : 'http',
                        'next_retry_at' => now()->addMinutes(30),
                        'health_status' => 'error',
                    ]);
                    // Keep previous valid data — do not mark sold
                    $seedIndex++;
                    $page = 1;
                    $cursor = ['seed_index' => $seedIndex, 'page' => 1];
                    continue;
                }

                $hadHttpSuccess = true;
                $listings = $adapter->parseListPage($result->body, $result->finalUrl);
                $parsed += count($listings);

                if ($listings === [] && $pages === 1 && $seedIndex === (int) ($checkpoint->cursor['seed_index'] ?? 0)) {
                    // Successful HTTP with empty parse on first page may be a parsing failure
                    if ($adapter->availableFields() !== []) {
                        $errors[] = 'پاسخ HTTP موفق اما استخراج آگهی خالی بود: '.$url;
                        $source->update([
                            'last_error' => 'parsing failure: empty listings on '.$url,
                            'last_error_type' => 'parse',
                            'health_status' => 'degraded',
                        ]);
                    }
                } else {
                    $hadParseSuccess = $hadParseSuccess || count($listings) > 0;
                }

                $stats = $this->upserter->upsertMany($source, $listings);
                $upserted += $stats['upserted'];
                foreach ($listings as $listing) {
                    $seenIds[] = $listing->externalId;
                }

                $next = null;
                if ($page < $source->max_pages_per_run) {
                    $next = $adapter->nextPageUrl($result->body, $result->finalUrl, $page);
                }

                if ($next) {
                    $page++;
                    $cursor = ['seed_index' => $seedIndex, 'page' => $page, 'next_url' => $next];
                } else {
                    $seedIndex++;
                    $page = 1;
                    $cursor = ['seed_index' => $seedIndex, 'page' => 1];
                }

                $checkpoint->update(['cursor' => $cursor]);
                $run->update([
                    'pages_fetched' => $pages,
                    'requests_made' => $requests,
                    'listings_parsed' => $parsed,
                    'listings_upserted' => $upserted,
                    'progress' => $cursor,
                ]);
            }

            // Only mark missing when we completed a meaningful successful parse
            if ($hadParseSuccess && $seedIndex >= count($seeds)) {
                $this->upserter->markMissingAsNotObserved($source, array_values(array_unique($seenIds)), $run->started_at);
                $checkpoint->update(['cursor' => []]);
            }

            $status = 'success';
            if ($errors !== [] && $upserted > 0) {
                $status = 'partial';
            } elseif ($errors !== [] && $upserted === 0) {
                $status = 'failed';
            }

            $source->update([
                'listings_found_last_run' => $upserted,
                'last_success_at' => $hadHttpSuccess ? now() : $source->last_success_at,
                'last_parse_success_at' => $hadParseSuccess ? now() : $source->last_parse_success_at,
                'fields_available' => $adapter->availableFields(),
                'capability_notes' => $adapter->capabilityNotes(),
                'shared_hosting_ok' => $adapter->isFetchableOnSharedHosting(),
                'health_status' => $status === 'success' ? 'ok' : ($status === 'partial' ? 'degraded' : ($source->health_status === 'blocked' ? 'blocked' : 'error')),
                'last_error' => $errors[0] ?? null,
                'last_error_type' => $errors === [] ? null : ($source->last_error_type ?: 'http'),
                'next_retry_at' => $status === 'failed' ? now()->addMinutes(45) : now()->addMinutes($source->refresh_interval_minutes),
            ]);

            $run->update([
                'status' => $status,
                'finished_at' => now(),
                'pages_fetched' => $pages,
                'requests_made' => $requests,
                'listings_parsed' => $parsed,
                'listings_upserted' => $upserted,
                'error' => $errors === [] ? null : implode("\n", array_slice($errors, 0, 5)),
            ]);
        } catch (Throwable $e) {
            $source->update([
                'last_error' => $e->getMessage(),
                'last_error_type' => str_contains($e->getMessage(), 'تایم') || str_contains($e->getMessage(), 'timeout') ? 'timeout' : 'blocked',
                'health_status' => $adapter->isFetchableOnSharedHosting() ? 'error' : 'blocked',
                'next_retry_at' => now()->addMinutes(60),
                'capability_notes' => $adapter->capabilityNotes(),
            ]);
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
                'pages_fetched' => $pages,
                'requests_made' => $requests,
                'listings_parsed' => $parsed,
                'listings_upserted' => $upserted,
            ]);
        } finally {
            $checkpoint->update(['locked_until' => null]);
        }

        return ['run' => $run->fresh(), 'message' => $run->status];
    }
}
