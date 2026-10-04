<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exhibition extends Model
{
    use Concerns\BelongsToWorld;

    protected $guarded = [];

    protected $casts = ['state' => 'array', 'rosters' => 'array', 'history' => 'array'];

    public function homeTeam()
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam()
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }
}
