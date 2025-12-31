<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $guarded = [];
    protected $casts = [
        'home_q' => 'array',
        'away_q' => 'array',
    ];


    public function plays(): HasMany
    {
        return $this->hasMany(Play::class)->orderBy('seq');
    }

    public function getAbsAttribute(): int
    {
        // yards from offense goal line toward opponent goal line
        $yardline = (int) $this->pos_yardline;
        return $this->pos_side === 'OWN' ? $yardline : (100 - $yardline);
    }

    public function getBallPctAttribute(): float
    {
        return max(0, min(100, $this->abs));
    }

    public function getSpotLabelAttribute(): string
    {
        return "{$this->pos_side} {$this->pos_yardline}";
    }
}
