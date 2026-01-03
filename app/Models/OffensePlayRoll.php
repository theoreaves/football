<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffensePlayRoll extends Model
{
    protected $fillable = [
        'offense_play_id',
        'roll_label',
        'roll_min',
        'roll_max',
        'player',
        'rating',
        'skill_pass',
        'skill_fail',
        'sort_order',
    ];

    public function play(): BelongsTo
    {
        return $this->belongsTo(OffensePlay::class, 'offense_play_id');
    }
}
