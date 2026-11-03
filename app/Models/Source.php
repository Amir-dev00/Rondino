<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Source extends Model
{
    protected $fillable = [
        'slug', 'name', 'base_url', 'listing_urls', 'adapter_class', 'enabled',
        'refresh_interval_minutes', 'max_pages_per_run', 'max_requests_per_run', 'rate_limit_ms',
        'fields_available', 'capability_notes', 'shared_hosting_ok', 'health_status',
        'last_attempt_at', 'last_success_at', 'last_parse_success_at', 'next_retry_at',
        'listings_found_last_run', 'last_error', 'last_error_type',
    ];

    protected function casts(): array
    {
        return [
            'listing_urls' => 'array',
            'fields_available' => 'array',
            'enabled' => 'boolean',
            'shared_hosting_ok' => 'boolean',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_parse_success_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function crawlRuns(): HasMany
    {
        return $this->hasMany(CrawlRun::class);
    }

    public function checkpoint(): HasOne
    {
        return $this->hasOne(CrawlCheckpoint::class);
    }

    public function isDue(): bool
    {
        if (! $this->enabled) {
            return false;
        }
        if ($this->next_retry_at && $this->next_retry_at->isFuture()) {
            return false;
        }
        if (! $this->last_success_at) {
            return true;
        }

        return $this->last_success_at->lte(now()->subMinutes($this->refresh_interval_minutes));
    }
}
