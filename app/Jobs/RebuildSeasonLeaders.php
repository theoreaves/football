<?php

namespace App\Jobs;

use App\Models\Season;
use App\Services\Seasons\LeagueLeaders;
use App\Support\CurrentWorld;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RebuildSeasonLeaders implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public int $uniqueFor = 3600;

    public function __construct(public int $worldId, public int $seasonId)
    {
    }

    public function uniqueId(): string
    {
        return $this->worldId.':'.$this->seasonId;
    }

    public function handle(LeagueLeaders $leaders): void
    {
        app(CurrentWorld::class)->id = $this->worldId;
        $season = Season::find($this->seasonId);
        if ($season) {
            $leaders->rebuild($season);
        }
    }
}
