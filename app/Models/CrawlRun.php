<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlRun extends Model
{
    protected $fillable = [
        'source_id', 'status', 'trigger', 'started_at', 'finished_at',
        'pages_fetched', 'requests_made', 'listings_upserted', 'listings_parsed',
        'error', 'progress',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'progress' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
