<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamPlayer extends Model
{
    protected static function booted(): void
    {
        static::saving(function ($row) {
            if (! Team::whereKey($row->team_id)->exists() || ! Player::whereKey($row->player_id)->exists()) {
                throw new \LogicException('Roster members must belong to the current world.');
            }
        });
        static::addGlobalScope('world', function ($query) {
            $query->whereIn($query->getModel()->qualifyColumn('team_id'), Team::query()->select('id'));
        });
    }

    protected $guarded = [];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
