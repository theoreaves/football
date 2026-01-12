<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerSeasonStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
        'season_year' => 'integer',
    ];

    public function player()
    {
        return $this->belongsTo(\App\Models\Player::class);
    }

}

