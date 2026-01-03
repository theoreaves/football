<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DefensePlayRoll extends Model
{
    protected $guarded = [];

    public function play(): BelongsTo
    {
        return $this->belongsTo(DefensePlay::class, 'defense_play_id');
    }
}
