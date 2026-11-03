<?php

namespace App\Scraping\DTO;

final class FetchResult
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public string $finalUrl,
        public ?string $error = null,
        public array $headers = [],
        public int $elapsedMs = 0,
    ) {}

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }
}
