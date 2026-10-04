<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use \App\Models\Concerns\BelongsToWorld;

    protected $guarded = [];

    public function players()
    {
        return $this->belongsToMany(\App\Models\Player::class, 'team_players')
            ->withPivot(['id', 'team_year', 'position', 'depth_chart_position', 'jersey_number'])
            ->withTimestamps();
    }
}
