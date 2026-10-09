<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Support\CurrentWorld;
use App\Services\Seasons\LeagueLeaders;
use Illuminate\Console\Command;

class RebuildLeagueLeaders extends Command
{
    protected $signature = 'season:rebuild-leaders {world_id : World ID} {season_id : Season ID}';

    protected $description = 'Precompute league leaderboards for a season without a web request timeout';

    public function handle(LeagueLeaders $leaders): int
    {
        $worldId = (int) $this->argument('world_id');
        $seasonId = (int) $this->argument('season_id');
        if ($worldId < 1 || $seasonId < 1) {
            $this->error('World and season IDs must be positive integers.');
            return self::FAILURE;
        }
        app(CurrentWorld::class)->id = $worldId;
        $season = Season::findOrFail($seasonId);
        $this->info("Calculating season {$season->id} league leaders...");
        $result = $leaders->rebuild($season);
        $this->info('Leaderboard snapshot saved ('.count($result).' categories).');

        return self::SUCCESS;
    }
}
