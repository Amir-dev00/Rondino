<?php

namespace App\Models;

use App\Scraping\Support\DigitNormalizer;
use App\Services\NumberPatternAnalyzer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimNumber extends Model
{
    protected $fillable = ['number', 'code_digit', 'pattern_flags'];

    protected function casts(): array
    {
        return [
            'pattern_flags' => 'array',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(TeamDeal::class);
    }

    public static function findOrCreateFromNumber(string $number): self
    {
        $number = DigitNormalizer::extractSim0912($number) ?? $number;
        if (! DigitNormalizer::isValid0912($number)) {
            throw new \InvalidArgumentException('Invalid 0912 number');
        }

        return self::query()->firstOrCreate(
            ['number' => $number],
            [
                'code_digit' => DigitNormalizer::codeDigit($number),
                'pattern_flags' => NumberPatternAnalyzer::analyze($number),
            ]
        );
    }
}
