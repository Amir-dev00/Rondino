<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferContact extends Model
{
    protected $fillable = ['offer_id', 'phone', 'phone_type', 'scope', 'label'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
