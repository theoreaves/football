<?php

namespace App\Console\Commands;

use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncEspnSeasonStats extends Command
{
    protected $signature = 'football:sync-espn-season-stats
        {--year=2025 : season year (regular season)}
        {--limit=0 : limit number of players processed (0 = no limit)}
        {--only-missing : only create records that do not exist yet for that year}
        {--timeout=30 : HTTP timeout seconds}
        {--sleep=0 : sleep milliseconds between requests (throttling)}
        {--debug-one= : set to a player_id to debug only that player}
    ';

    protected $description = 'Sync ESPN regular-season totals for all players with espn_id into player_season_stats';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $limit = (int) $this->option('limit');
        $onlyMissing = (bool) $this->option('only-missing');
        $timeout = (int) $this->option('timeout');
        $sleepMs = (int) $this->option('sleep');
        $debugOne = $this->option('debug-one');

        $playersQ = Player::query()
            ->whereNotNull('espn_id')
            ->where('espn_id', '!=', '');

        if ($debugOne !== null && $debugOne !== '') {
            $playersQ->where('id', (int) $debugOne);
        }

        if ($limit > 0) {
            $playersQ->limit($limit);
        }

        $players = $playersQ->get(['id', 'espn_id']);

        if ($players->isEmpty()) {
            $this->info('No players with espn_id found for this run.');
            return self::SUCCESS;
        }

        $processed = 0;
        $created = 0;
        $updated = 0;
        $skippedExisting = 0;
        $failed = 0;

        foreach ($players as $player) {
            $processed++;

            if ($onlyMissing) {
                $exists = DB::table('player_season_stats')
                    ->where('player_id', $player->id)
                    ->where('season_year', $year)
                    ->exists();

                if ($exists) {
                    $skippedExisting++;
                    continue;
                }
            }

            // ESPN regular season = types/2
            $url = "https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/{$year}/types/2/athletes/{$player->espn_id}/statistics/0";

            try {
                $json = Http::timeout($timeout)
                    ->retry(2, 400)
                    ->acceptJson()
                    ->get($url)
                    ->throw()
                    ->json();
            } catch (\Throwable $e) {
                $failed++;
//                $this->warn("Failed player_id={$player->id}, espn_id={$player->espn_id}: {$e->getMessage()}");
                continue;
            }

            $mapped = $this->mapEspnTotalsToColumns($json);

            // IMPORTANT: DB::table insert/update needs raw as a STRING (SQLite cannot bind arrays)
            $mapped['raw'] = json_encode($json, JSON_UNESCAPED_SLASHES);

            $mapped['espn_id'] = (string) $player->espn_id;
            $mapped['team_id'] = $mapped['team_id'] ?? null;

            $now = now();

            $base = [
                'player_id' => $player->id,
                'season_year' => $year,
                'updated_at' => $now,
            ];

            $existing = DB::table('player_season_stats')
                ->where('player_id', $player->id)
                ->where('season_year', $year)
                ->first();

            if (!$existing) {
                DB::table('player_season_stats')->insert(array_merge($base, [
                    'created_at' => $now,
                ], $mapped));
                $created++;
            } else {
                DB::table('player_season_stats')
                    ->where('id', $existing->id)
                    ->update(array_merge($base, $mapped));
                $updated++;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            if ($processed % 250 === 0) {
                $this->line("Processed {$processed}... created={$created} updated={$updated} failed={$failed}");
            }
        }

        $this->info("Done. processed={$processed} created={$created} updated={$updated} skipped_existing={$skippedExisting} failed={$failed}");

        return self::SUCCESS;
    }

    /**
     * Map ESPN totals JSON into our player_season_stats columns.
     *
     * ESPN structure for this endpoint (as in your sample) is:
     *   splits -> categories[] -> stats[]
     *
     * We index by category + stat name to avoid collisions (e.g., "interceptions").
     */
    private function mapEspnTotalsToColumns(array $json): array
    {
        // Defaults
        $out = [
            'games' => 0,
            'games_started' => 0,

            // Passing
            'pass_completions' => 0,
            'pass_attempts' => 0,
            'pass_yards' => 0,
            'pass_tds' => 0,
            'interceptions_thrown' => 0,
            'sacks_taken' => 0,
            'sack_yards_lost' => 0,

            // Rushing
            'rush_attempts' => 0,
            'rush_yards' => 0,
            'rush_tds' => 0,

            // Receiving
            'receptions' => 0,
            'receiving_yards' => 0,
            'receiving_tds' => 0,
            'targets' => 0,

            // Defense
            'tackles_total' => 0,
            'tackles_solo' => 0,
            'tackles_assist' => 0,
            'sacks' => 0.0,
            'tfl' => 0.0,
            'qb_hits' => 0,

            'def_interceptions' => 0,
            'passes_defended' => 0,
            'forced_fumbles' => 0,
            'fumble_recoveries' => 0,
            'def_tds' => 0,

            // Special teams
            'fg_made' => 0,
            'fg_attempts' => 0,
            'xp_made' => 0,
            'xp_attempts' => 0,

            'punts' => 0,
            'punt_yards' => 0,
            'punts_inside_20' => 0,
            'punt_touchbacks' => 0,
            'punt_blocked' => 0,

            'kick_returns' => 0,
            'kick_return_yards' => 0,
            'kick_return_tds' => 0,

            'punt_returns' => 0,
            'punt_return_yards' => 0,
            'punt_return_tds' => 0,

            'fumbles' => 0,
            'fumbles_lost' => 0,

            // optional
            'team_id' => null,
        ];

        // Build category-scoped index: "categoryName.statName" => value
        $idx = [];
        $categories = $json['splits']['categories'] ?? [];

        foreach ($categories as $cat) {
            $catName = $cat['name'] ?? 'unknown';
            foreach (($cat['stats'] ?? []) as $stat) {
                $name = $stat['name'] ?? null;
                if (!$name) continue;

                $idx["{$catName}.{$name}"] = $stat['value'] ?? null;
            }
        }

        $int = function (string $k) use ($idx): int {
            $v = $idx[$k] ?? null;
            return ($v === null || $v === '') ? 0 : (int) $v;
        };

        $flt = function (string $k) use ($idx): float {
            $v = $idx[$k] ?? null;
            return ($v === null || $v === '') ? 0.0 : (float) $v;
        };

        // =========
        // General
        // =========
        $out['games'] = $int('general.gamesPlayed');

        // Passing
        $out['pass_completions'] = $int('passing.completions');
        $out['pass_attempts'] = $int('passing.passingAttempts');
        $out['pass_yards'] = $int('passing.passingYards');
        $out['pass_tds'] = $int('passing.passingTouchdowns');
        $out['interceptions_thrown'] = $int('passing.interceptions');
        $out['sacks_taken'] = $int('passing.sacks');
        $out['sack_yards_lost'] = $int('passing.sackYardsLost');

        // Rushing
        $out['rush_attempts'] = $int('rushing.rushingAttempts');
        $out['rush_yards'] = $int('rushing.rushingYards');
        $out['rush_tds'] = $int('rushing.rushingTouchdowns');

        // Receiving
        $out['targets'] = $int('receiving.receivingTargets');
        $out['receiving_tds'] = $int('receiving.receivingTouchdowns');
        $out['receiving_yards'] = $int('receiving.receivingYards');
        $out['receptions'] = $int('receiving.receptions');

        // Fumbles (ESPN puts forced/recovered in "general" in your sample)
        $out['forced_fumbles'] = $int('general.fumblesForced');
        $out['fumble_recoveries'] = $int('general.fumblesRecovered');

        // Some ESPN totals endpoints include player fumbles/fumblesLost in offense categories.
        // Leave defaults unless you map them from your specific payload later.

        // =========
        // Defense (your sample uses "defensive")
        // =========
        $out['tackles_total'] = $int('defensive.totalTackles');
        $out['tackles_solo'] = $int('defensive.soloTackles');
        $out['tackles_assist'] = $int('defensive.assistTackles');

        $out['sacks'] = $flt('defensive.sacks');
        $out['tfl'] = $flt('defensive.tacklesForLoss');
        $out['qb_hits'] = $int('defensive.QBHits');

        $out['passes_defended'] = $int('defensive.passesDefended');

        // Defensive INTs are in a separate category in your sample
        $out['def_interceptions'] = $int('defensiveInterceptions.interceptions');

        // Defensive TDs: sum the categories we can see in your sample
        $out['def_tds'] =
            $int('defensiveInterceptions.interceptionTouchdowns')
            + $int('general.defensiveFumblesTouchdowns');

        // =========
        // NOTE:
        // Offense + Special Teams categories vary by player/position.
        // Once you paste a sample offensive player response, I can extend this
        // mapping for rushing/receiving/passing/kicking/punting/returns.
        // =========

        return $out;
    }
}
