<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $guarded = [];

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
            ->withPivot([
                'team_year',
                'position',
                'jersey_number',
                'depth_chart_position',
                'kick_return_depth_chart_position',
                'punt_return_depth_chart_position',
                'catch_from','catch_to',
                'catch_plus_from','catch_plus_to',
                'rush_from','rush_to',
                'sack_from','sack_to',
                'interception_from','interception_to',
                'kick_from','kick_to',
                'punt_from','punt_to',
            ])
            ->withTimestamps();
    }


}
