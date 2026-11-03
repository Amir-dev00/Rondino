<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceObservation extends Model
{
    protected $fillable = ['offer_id', 'price_amount', 'price_raw', 'price_type', 'observed_at'];

    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'observed_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
