<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use \App\Models\Concerns\BelongsToWorld;

    protected $guarded = [];

    protected $casts = ['simulation_ratings' => 'array', 'appearance' => 'array'];

    public function team()
    {
        return $this->hasOne(TeamPlayer::class);
    }

    public function getCurrentJerseyNumberAttribute()
    {
        $teamPlayer = $this->team;

        return $teamPlayer ? $teamPlayer->jersey_number : null;
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_players')
            ->withPivot(['id', 'team_year', 'position', 'depth_chart_position', 'jersey_number'])
            ->withTimestamps();
    }
}
