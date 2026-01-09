<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $guarded = [];

    public function playersOLD()
    {
        return $this->belongsToMany(Player::class, 'team_players')
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
                'tackle_from','tackle_to',
                'kick_from','kick_to',
                'punt_from','punt_to',
            ])
            ->withTimestamps();
    }

    // Team.php
    public function players()
    {
        return $this->belongsToMany(\App\Models\Player::class, 'team_players')
            ->withPivot([
                'id',
                'team_year',
                'position',
                'depth_chart_position',
                'kick_return_depth_chart_position',
                'punt_return_depth_chart_position',
                'catch_from','catch_to','catch_plus_from','catch_plus_to',
                'rush_from','rush_to',
                'sack_from','sack_to',
                'interception_from','interception_to',
                'tackle_from','tackle_to',
                'kick_from','kick_to',
                'punt_from','punt_to',
                'jersey_number',
            ])
            ->withTimestamps();
    }


    // App\Models\Team.php
    public function logoUrl(?string $field): ?string
    {
        $path = $this->{$field} ?? null;
        return $path ? \Storage::disk('public')->url($path) : null;
    }


}
