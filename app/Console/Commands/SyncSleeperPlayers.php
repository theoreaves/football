<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncSleeperPlayers extends Command
{
    protected $signature = 'football:sync-sleeper
        {--year=2025 : team_year to write into team_players}
        {--include-inactive : include inactive players}
        {--default-age=0 : age to use when Sleeper provides none}
        {--fresh : delete existing team_players rows for this year before syncing}
        {--limit=0 : limit processed players (0 = no limit)}
    ';

    protected $description = 'Sync NFL players from Sleeper into players and team_players tables';

    public function handle(): int
    {
        $year = (string) $this->option('year');
        $includeInactive = (bool) $this->option('include-inactive');
        $defaultAge = (int) $this->option('default-age');
        $fresh = (bool) $this->option('fresh');
        $limit = (int) $this->option('limit');

        $teamMap = Team::query()
            ->select(['id', 'abbr'])
            ->whereNotNull('abbr')
            ->get()
            ->mapWithKeys(fn ($t) => [strtoupper(trim($t->abbr)) => (int) $t->id]);

        if ($teamMap->isEmpty()) {
            $this->error("No teams found with non-null 'abbr'. Seed/update teams first.");
            return self::FAILURE;
        }

        if ($fresh) {
            $deleted = DB::table('team_players')->where('team_year', $year)->delete();
            $this->info("Deleted {$deleted} team_players rows for year {$year} (--fresh).");
        }

        $this->info('Downloading Sleeper players dataset...');
        $resp = Http::timeout(60)->retry(3, 500)->acceptJson()
            ->get('https://api.sleeper.app/v1/players/nfl');

        if (!$resp->ok()) {
            $this->error("Sleeper request failed: HTTP {$resp->status()}");
            return self::FAILURE;
        }

        $payload = $resp->json();
        if (!is_array($payload)) {
            $this->error('Unexpected Sleeper response.');
            return self::FAILURE;
        }

        $processed = $createdPlayers = $updatedPlayers = 0;
        $createdPivots = $updatedPivots = 0;
        $skippedNoTeam = $skippedInactive = 0;

        foreach ($payload as $sleeperId => $p) {
            if ($limit > 0 && $processed >= $limit) break;
            if (!is_array($p)) continue;

            $active = (bool) ($p['active'] ?? false);
            if (!$includeInactive && !$active) {
                $skippedInactive++;
                continue;
            }

            $teamCode = strtoupper(trim((string) ($p['team'] ?? '')));
            if ($teamCode === '' || !$teamMap->has($teamCode)) {
                $skippedNoTeam++;
                continue;
            }

            $first = trim((string) ($p['first_name'] ?? ''));
            $last  = trim((string) ($p['last_name'] ?? ''));
            $pos   = strtoupper(trim((string) ($p['position'] ?? '')));
            if ($first === '' || $last === '' || $pos === '') continue;

            $age = (int) ($p['age'] ?? $defaultAge);
            $jersey = isset($p['number']) ? (int) $p['number'] : null;

            $teamId = (int) $teamMap->get($teamCode);

            DB::beginTransaction();
            try {
                /** @var Player $player */
                $player = Player::query()->where('sleeper_id', (string) $sleeperId)->first();

                if (!$player) {
                    $player = new Player();
                    $player->sleeper_id = (string) $sleeperId;
                    $player->firstname = $first;
                    $player->lastname = $last;
                    $player->position = $pos;
                    $player->age = $age; // required NOT NULL in your schema
                    $player->save();
                    $createdPlayers++;
                } else {
                    $dirty = false;

                    // keep names/pos synced if they ever change in source
                    if ($player->firstname !== $first) { $player->firstname = $first; $dirty = true; }
                    if ($player->lastname !== $last) { $player->lastname = $last; $dirty = true; }
                    if (strtoupper($player->position) !== $pos) { $player->position = $pos; $dirty = true; }

                    // prefer non-zero age when available
                    if (((int) $player->age) === 0 && $age > 0) { $player->age = $age; $dirty = true; }

                    if ($dirty) {
                        $player->save();
                        $updatedPlayers++;
                    }
                }

                $pivotDefaults = [
                    'team_id' => $teamId,
                    'player_id' => $player->id,
                    'team_year' => $year,

                    'position' => $pos,
                    'depth_chart_position' => $pos,
                    'kick_return_depth_chart_position' => 'NA',
                    'punt_return_depth_chart_position' => 'NA',

                    'catch_from' => 0, 'catch_to' => 0,
                    'catch_plus_from' => 0, 'catch_plus_to' => 0,
                    'rush_from' => 0, 'rush_to' => 0,
                    'sack_from' => 0, 'sack_to' => 0,
                    'interception_from' => 0, 'interception_to' => 0,
                    'tackle_from' => 0, 'tackle_to' => 0,
                    'kick_from' => 0, 'kick_to' => 0,
                    'punt_from' => 0, 'punt_to' => 0,
                ];

                $existingPivot = DB::table('team_players')
                    ->where('team_id', $teamId)
                    ->where('player_id', $player->id)
                    ->where('team_year', $year)
                    ->first();

                if (!$existingPivot) {
                    $pivotDefaults['created_at'] = now();
                    $pivotDefaults['updated_at'] = now();
                    $pivotDefaults['jersey_number'] = $jersey;

                    DB::table('team_players')->insert($pivotDefaults);
                    $createdPivots++;
                } else {
                    $updates = ['updated_at' => now()];

                    if ($jersey !== null && (int) ($existingPivot->jersey_number ?? -1) !== $jersey) {
                        $updates['jersey_number'] = $jersey;
                    }

                    if (count($updates) > 1) {
                        DB::table('team_players')->where('id', $existingPivot->id)->update($updates);
                        $updatedPivots++;
                    }
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->warn("Failed {$first} {$last} ({$teamCode}): {$e->getMessage()}");
                continue;
            }

            $processed++;
            if ($processed % 1000 === 0) $this->line("Processed {$processed}...");
        }

        $this->info("Done. Processed {$processed}.");
        $this->line("Players created: {$createdPlayers}, updated: {$updatedPlayers}");
        $this->line("Pivots created: {$createdPivots}, updated: {$updatedPivots}");
        $this->line("Skipped inactive: {$skippedInactive}");
        $this->line("Skipped missing team mapping: {$skippedNoTeam}");

        return self::SUCCESS;
    }
}
