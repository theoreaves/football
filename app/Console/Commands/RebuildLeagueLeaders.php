<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\Seasons\LeagueLeaders;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;

class RebuildLeagueLeaders extends Command
{
    protected $signature = 'season:rebuild-leaders {world_id? : World ID} {season_id? : Season ID} {--list : List season IDs and published statistics weeks without rebuilding}';

    protected $description = 'Precompute league leaderboards for a season without a web request timeout';

    public function handle(LeagueLeaders $leaders): int
    {
        if ($this->option('list')) {
            $this->table(['World ID', 'Season ID', 'Season', 'Year', 'Current week', 'Stats through week'],
                Season::withoutGlobalScope('world')->orderBy('world_id')->orderBy('id')->get()
                    ->map(fn ($season) => [$season->world_id, $season->id, $season->name, $season->year, $season->current_week, $leaders->publishedThroughWeek($season) ?? 'Not built'])->all());

            return self::SUCCESS;
        }
        $worldId = (int) $this->argument('world_id');
        $seasonId = (int) $this->argument('season_id');
        if ($worldId < 1 || $seasonId < 1) {
            $this->error('Provide positive world and season IDs, or use --list to find them.');

            return self::FAILURE;
        }
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = $worldId;
            $season = Season::findOrFail($seasonId);
            $this->info("Calculating season {$season->id} league leaders...");
            $result = $leaders->rebuild($season);
            $this->info('Leaderboard snapshot saved ('.count($result).' categories), through week '.$leaders->publishedThroughWeek($season).'.');
        } finally {
            $context->id = $previous;
        }

        return self::SUCCESS;
    }
}
