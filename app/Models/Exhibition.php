<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exhibition extends Model
{
    use Concerns\BelongsToWorld;

    protected $guarded = [];

    protected $casts = ['state' => 'array', 'rosters' => 'array', 'history' => 'array', 'box_score_cache' => 'array'];

    private bool $writingHistoryJson = false;

    /** Preserve normal model events and atomic saves without duplicate history casts. */
    public function updateHistoryJson(string $json, array $attributes = []): bool
    {
        if (array_key_exists('history', $attributes)) {
            throw new \InvalidArgumentException('History must be supplied only as JSON.');
        }
        $this->fill($attributes);
        $this->attributes['history'] = $json;
        $this->writingHistoryJson = true;
        try {
            return $this->save();
        } finally {
            $this->writingHistoryJson = false;
        }
    }

    public function originalIsEquivalent($key)
    {
        if ($key === 'history' && $this->writingHistoryJson) {
            return array_key_exists($key, $this->original)
                && $this->attributes[$key] === $this->original[$key];
        }

        return parent::originalIsEquivalent($key);
    }

    public function arcadeFlow()
    {
        return $this->hasOne(ArcadeGameFlow::class);
    }

    public function seasonFixture()
    {
        return $this->hasOne(SeasonFixture::class);
    }

    public function homeTeam()
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam()
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }
}
