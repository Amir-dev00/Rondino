<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamDeal extends Model
{
    protected $fillable = [
        'user_id', 'sim_number_id', 'deal_type', 'amount_toman', 'deal_date', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount_toman' => 'integer',
            'deal_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function simNumber(): BelongsTo
    {
        return $this->belongsTo(SimNumber::class);
    }
}
