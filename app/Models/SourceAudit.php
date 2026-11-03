<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceAudit extends Model
{
    protected $fillable = [
        'source_id', 'domain', 'audit_date', 'status', 'evidence', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'audit_date' => 'date',
            'evidence' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
