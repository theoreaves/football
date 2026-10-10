<?php

namespace App\Services\Seasons;

use App\Models\Player;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/** Active game-ending injuries only, scoped to one season and its roster. */
class ActiveSeasonInjuries
{
    public function forSeason(Season $season, ?int $teamId = null): array
    {
        $members = $season->settings['members'] ?? [];
        $query = DB::table('season_player_injuries')
            ->where('season_id', $season->id)
            ->where('status', 'active')
            ->where(function ($query) use ($season) {
                $query->whereNull('return_week')->orWhere('return_week', '>', $season->current_week);
            })
            ->whereIn('team_id', array_keys($members));
        if ($teamId !== null) {
            $query->where('team_id', $teamId);
        }
        $injuries = $query->orderBy('team_id')->orderBy('injured_week')->get();
        if ($injuries->isEmpty()) {
            return [];
        }

        $players = Player::whereIn('id', $injuries->pluck('player_id')->unique())->get()->keyBy('id');
        $roster = DB::table('team_players')
            ->where('team_year', (string) $season->year)
            ->whereIn('team_id', $injuries->pluck('team_id')->unique())
            ->whereIn('player_id', $injuries->pluck('player_id')->unique())
            ->get()->keyBy(fn ($row) => $row->team_id.':'.$row->player_id);

        return $injuries->map(function ($row) use ($members, $players, $roster) {
            $player = $players->get($row->player_id);
            $assignment = $roster->get($row->team_id.':'.$row->player_id);
            return [
                'team' => $members[$row->team_id]['name'] ?? 'Unknown team',
                'team_id' => (int) $row->team_id,
                'player' => $player ? trim($player->firstname.' '.$player->lastname) : 'Unknown player',
                'number' => $assignment->jersey_number ?? null,
                'position' => $assignment->position ?? '—',
                'type' => $row->type,
                'severity' => $row->severity,
                'injured_week' => (int) $row->injured_week,
                'return_week' => $row->return_week === null ? null : (int) $row->return_week,
            ];
        })->all();
    }
}
