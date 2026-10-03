<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Support\Football\RatingsFromSeasonStat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FootballGenerateRatingsByYear extends Command
{
    protected $signature = 'football:ratings-year
        {--year=2025 : season_year from player_season_stats}
        {--limit=0 : limit players processed (0 = no limit)}
        {--dry-run : do not write ratings}
        {--only-missing : only update players who currently have all-zero ratings}
    ';

    protected $description = 'Generate player ratings for all players based on season stats for a given year';

    public function handle(RatingsFromSeasonStat $generator): int
    {
        $seasonYear = (int) $this->option('year');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $onlyMissing = (bool) $this->option('only-missing');

        $this->info("Generating ratings from stats: {$seasonYear}" . ($dryRun ? " (dry-run)" : ""));

        $rows = DB::table('player_season_stats')
            ->where('season_year', $seasonYear)
            ->get();

        if ($rows->isEmpty()) {
            $this->warn("No player_season_stats rows found for season_year={$seasonYear}");
            return self::SUCCESS;
        }

        // Group all stat lines by player, then sum each group
        $playerStats = $rows->groupBy('player_id');

        $processed = $updated = $skipped = $failed = 0;

        foreach ($playerStats as $playerId => $statRows) {
            if ($limit > 0 && $processed >= $limit) break;

            /** @var Player|null $player */
            $player = Player::find($playerId);
            if (!$player) { $skipped++; continue; }

            if ($onlyMissing && !$this->ratingsAllZero($player)) {
                $skipped++;
                continue;
            }

            // ✅ SUM all rows for this player/year into one combined totals object
            $stat = $this->sumRows($statRows);

            try {
                $ratings = $generator->build($stat, strtoupper(trim($player->position ?? '')));

                if (empty($ratings)) {
                    $skipped++;
                    continue;
                }

                if (!$dryRun) {
                    $player->fill($ratings);
                    $player->save();
                }

                $updated++;

                if ($processed % 250 === 0) {
                    $this->line("Processed {$processed}...");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Failed Player #{$playerId} {$player->firstname} {$player->lastname}: {$e->getMessage()}");
            }

            $processed++;
        }

        $this->info("Done. processed={$processed} updated={$updated} skipped={$skipped} failed={$failed}");

        return self::SUCCESS;
    }

    /**
     * Sum ESPN per-team split rows into one combined season totals row.
     * $rows is a Collection of stdClass from DB query.
     */
    private function sumRows($rows): object
    {
        // Fields your generator currently reads/uses.
        // (You can add more here anytime.)
        $sumFields = [
            // games / general
            'games',
            'games_started',

            // passing
            'pass_completions',
            'pass_attempts',
            'pass_yards',
            'pass_tds',
            'interceptions_thrown',
            'sacks_taken',
            'sack_yards_lost',

            // rushing / receiving
            'rush_attempts',
            'rush_yards',
            'rush_tds',
            'receptions',
            'targets',
            'receiving_yards',
            'receiving_tds',

            // defense
            'tackles_total',
            'tackles_solo',
            'tackles_assist',
            'sacks',                 // can be float (half sacks)
            'tfl',
            'qb_hits',
            'def_interceptions',
            'passes_defended',
            'forced_fumbles',
            'fumble_recoveries',
            'def_tds',

            // kicking/punting
            'fg_made',
            'fg_attempts',
            'xp_made',
            'xp_attempts',
            'punts',
            'punt_yards',
            'punts_inside_20',
            'punt_touchbacks',
            'punt_blocked',

            // returns + misc
            'kick_returns',
            'kick_return_yards',
            'kick_return_tds',
            'punt_returns',
            'punt_return_yards',
            'punt_return_tds',
            'fumbles',
            'fumbles_lost',
        ];

        $agg = [];

        // initialize zeros
        foreach ($sumFields as $f) {
            $agg[$f] = 0;
        }

        foreach ($rows as $r) {
            foreach ($sumFields as $f) {
                $val = $r->{$f} ?? 0;

                // allow float sacks, but keep other stuff int-ish
                if ($f === 'sacks') {
                    $agg[$f] += (float) $val;
                } else {
                    $agg[$f] += (int) $val;
                }
            }
        }

        // carry required context if you want it available
        // (not needed for ratings, but harmless)
        $agg['player_id'] = (int) ($rows->first()->player_id ?? 0);
        $agg['season_year'] = (int) ($rows->first()->season_year ?? 0);
        $agg['team_id'] = (int) ($rows->first()->team_id ?? 0); // FYI: first team, combined is multi-team

        return (object) $agg;
    }

    private function ratingsAllZero(Player $p): bool
    {
        $fields = [
            'pass_evade','pass_accuracy','pass_deep','pass_control',
            'rush','rush_power','receive','receive_deep',
            'fumble','speed',
            'tackle','sack','cover','interception','strip',
            'kick30','kick39','kick49','kick50',
            'punt_distance','punt_pooch_yard','punt_pooch','punt_block',
            'return_yards','return_speed','return_fumble',
        ];

        foreach ($fields as $f) {
            if ((int) ($p->{$f} ?? 0) !== 0) return false;
        }

        return true;
    }
}
