<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeasonFixture extends Model
{
    use Concerns\BelongsToWorld;

    protected $guarded = [];

    public function homeTeam()
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam()
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }
}
