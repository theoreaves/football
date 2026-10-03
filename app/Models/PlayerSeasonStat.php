<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerSeasonStat extends Model
{
    protected static function booted(): void
    {
        static::saving(function ($stat) {
            if (! Player::whereKey($stat->player_id)->exists() ||
                ($stat->team_id !== null && ! Team::whereKey($stat->team_id)->exists())) {
                throw new \LogicException('Season stats must reference players and teams in the current world.');
            }
        });
        static::addGlobalScope('world', function ($query) {
            $query->whereIn($query->getModel()->qualifyColumn('player_id'), Player::query()->select('id'));
        });
    }

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
