<?php

namespace App\Scraping\Http;

use App\Scraping\DTO\FetchResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpClient
{
    public function __construct(
        private int $timeoutSeconds = 25,
        private int $rateLimitMs = 1500,
        private int $maxRetries = 2,
    ) {}

    public function withRateLimit(int $ms): self
    {
        $clone = clone $this;
        $clone->rateLimitMs = $ms;

        return $clone;
    }

    public function get(string $url): FetchResult
    {
        return $this->request('GET', $url);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $url, array $data = []): FetchResult
    {
        return $this->request('POST', $url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function request(string $method, string $url, array $data = []): FetchResult
    {
        if ($this->rateLimitMs > 0) {
            usleep($this->rateLimitMs * 1000);
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $this->maxRetries) {
            $attempt++;
            $started = (int) (microtime(true) * 1000);

            try {
                $verifySsl = filter_var(env('RONDINO_HTTP_VERIFY_SSL', true), FILTER_VALIDATE_BOOL);

                $pending = Http::withHeaders([
                    'User-Agent' => config('rondino.crawl.user_agent', 'RondinoBot/1.0'),
                    'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.5',
                ])
                    ->timeout($this->timeoutSeconds)
                    ->withOptions([
                        'allow_redirects' => ['max' => 5],
                        'verify' => $verifySsl,
                    ])
                    ->withUserAgent(config('rondino.crawl.user_agent', 'RondinoBot/1.0'));

                $response = strtoupper($method) === 'POST'
                    ? $pending->asForm()->post($url, $data)
                    : $pending->get($url);

                $elapsed = (int) (microtime(true) * 1000) - $started;
                $status = $response->status();

                if (in_array($status, [429, 503], true) && $attempt <= $this->maxRetries) {
                    usleep((int) (pow(2, $attempt) * 500_000));
                    continue;
                }

                return new FetchResult(
                    status: $status,
                    body: $response->body(),
                    finalUrl: (string) ($response->effectiveUri() ?? $url),
                    error: $response->successful() ? null : 'HTTP '.$status,
                    headers: $response->headers(),
                    elapsedMs: $elapsed,
                );
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt <= $this->maxRetries) {
                    usleep((int) (pow(2, $attempt) * 500_000));
                    continue;
                }
            }
        }

        return new FetchResult(
            status: 0,
            body: '',
            finalUrl: $url,
            error: $lastError ?? 'unknown fetch error',
            elapsedMs: 0,
        );
    }
}
