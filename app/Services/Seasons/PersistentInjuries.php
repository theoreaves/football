<?php

namespace App\Services\Seasons;

use App\Models\Exhibition;
use App\Models\Season;
use App\Models\SeasonFixture;
use Illuminate\Support\Facades\DB;

/** Carries game-ending injuries across season fixtures without altering exhibition games. */
class PersistentInjuries
{
    public function seedState(Season $season, int $week, array $teams, array $state): array
    {
        $rows = DB::table('season_player_injuries')
            ->where('season_id', $season->id)
            ->where('status', 'active')
            ->where(function ($query) use ($week) {
                $query->whereNull('return_week')->orWhere('return_week', '>', $week);
            })
            ->whereIn('team_id', array_values($teams))
            ->get();

        foreach ($rows as $row) {
            $side = array_search((int) $row->team_id, $teams, true);
            if ($side === false) {
                continue;
            }
            // A null return_snap means the existing personnel engine excludes
            // this player for the entire game (human and CPU alike).
            $state['injuries'][$side][$row->player_id] = [
                'type' => $row->type,
                'return_snap' => null,
                'season_injury_id' => $row->id,
            ];
        }

        return $state;
    }

    public function record(SeasonFixture $fixture, Exhibition $game): void
    {
        $seasonId = (int) $fixture->season_id;
        foreach (['home', 'away'] as $side) {
            $teamId = (int) $fixture->{$side.'_team_id'};
            foreach (($game->state['injuries'][$side] ?? []) as $playerId => $injury) {
                // Temporary injuries don't persist. Existing season injuries
                // are already recorded and must not be duplicated each game.
                if (($injury['return_snap'] ?? null) !== null || isset($injury['season_injury_id'])) {
                    continue;
                }
                if (! collect($game->rosters[$side]['pool'] ?? [])->contains(fn ($p) => (int) $p['id'] === (int) $playerId)) {
                    continue;
                }
                $seed = (int) sprintf('%u', crc32($seasonId.':'.$game->id.':'.$playerId.':season-injury'));
                $roll = $seed % 100;
                $severity = $roll < 55 ? 'moderate' : 'serious';
                $weeksOut = $severity === 'moderate' ? 1 + ($seed % 4) : 5 + ($seed % 8);
                $seasonEnding = $severity === 'serious' && $roll >= 90;
                $returnWeek = $seasonEnding ? null : ((int) $fixture->week + $weeksOut + 1);
                DB::table('season_player_injuries')->updateOrInsert(
                    ['season_id' => $seasonId, 'exhibition_id' => $game->id, 'player_id' => (int) $playerId],
                    ['team_id' => $teamId, 'injured_week' => $fixture->week,
                        'return_week' => $returnWeek, 'type' => $injury['type'] ?? 'Injury',
                        'severity' => $seasonEnding ? 'season-ending' : $severity,
                        'status' => 'active', 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    public function recover(Season $season, int $nextWeek): void
    {
        DB::table('season_player_injuries')
            ->where('season_id', $season->id)->where('status', 'active')
            ->whereNotNull('return_week')->where('return_week', '<=', $nextWeek)
            ->update(['status' => 'recovered', 'updated_at' => now()]);
    }
}
