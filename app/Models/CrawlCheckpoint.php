<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlCheckpoint extends Model
{
    protected $fillable = ['source_id', 'cursor', 'locked_until'];

    protected function casts(): array
    {
        return [
            'cursor' => 'array',
            'locked_until' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
