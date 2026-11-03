<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    protected $fillable = [
        'source_id', 'sim_number_id', 'external_id', 'canonical_url',
        'price_amount', 'price_raw', 'price_type',
        'condition_raw', 'condition_normalized', 'city', 'city_raw', 'seller_key',
        'availability', 'first_seen_at', 'last_seen_at', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function simNumber(): BelongsTo
    {
        return $this->belongsTo(SimNumber::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(OfferContact::class);
    }

    public function priceObservations(): HasMany
    {
        return $this->hasMany(PriceObservation::class)->orderByDesc('observed_at');
    }
}
