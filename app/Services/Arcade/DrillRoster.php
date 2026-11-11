<?php

namespace App\Services\Arcade;

use App\Models\Player;
use App\Models\Team;
use App\Services\Simulation\PlayerRatings;

class DrillRoster
{
    public function __construct(private PlayerRatings $ratings) {}

    /** @return array<int, array<string, mixed>> */
    public function runners(?Team $team): array
    {
        return $this->candidates($team, ['RB', 'FB']);
    }

    /** @return array<int, array<string, mixed>> */
    public function quarterbacks(?Team $team): array
    {
        return $this->candidates($team, ['QB']);
    }

    /** @return array<int, array<string, mixed>> */
    public function receivers(?Team $team): array
    {
        return array_slice($this->candidates($team, ['WR', 'TE']), 0, 2);
    }

    /** @return array<int, array<string, mixed>> */
    public function coverageDefenders(?Team $team): array
    {
        return array_slice($this->candidates($team, ['DB', 'CB', 'S', 'FS', 'SS', 'LB', 'ILB', 'OLB', 'MLB']), 0, 2);
    }

    /** @return array<int, array<string, mixed>> */
    public function defenders(?Team $team): array
    {
        return array_slice($this->candidates($team, ['LB', 'ILB', 'OLB', 'MLB', 'DB', 'CB', 'S', 'FS', 'SS']), 0, 2);
    }

    /** @return array<int, array<string, mixed>> */
    private function candidates(?Team $team, array $positions): array
    {
        if (! $team) {
            return [];
        }
        $year = $team->players()->max('team_players.team_year');
        if ($year === null) {
            return [];
        }

        return $team->players()->wherePivot('team_year', $year)->get()
            ->filter(fn (Player $player) => in_array(strtoupper($player->pivot->position ?: $player->position), $positions, true))
            ->sortBy(function (Player $player) use ($positions): array {
                $depth = $player->pivot->depth_chart_position ?? '';
                $rank = preg_match('/(\d+)$/', $depth, $matches) ? (int) $matches[1] : PHP_INT_MAX;

                return [$rank, array_search(strtoupper($player->pivot->position ?: $player->position), $positions, true), $player->id];
            })
            ->unique('id')
            ->map(fn (Player $player) => $this->snapshot($player))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function snapshot(Player $player): array
    {
        return [
            'id' => $player->id,
            'name' => trim($player->firstname.' '.$player->lastname),
            'lastname' => $player->lastname,
            'number' => $player->pivot->jersey_number,
            'position' => strtoupper($player->pivot->position ?: $player->position),
            'depth' => $player->pivot->depth_chart_position,
            'ratings' => $this->ratings->forPlayer($player),
            'appearance' => $player->appearance ?? [],
            'height_inches' => $player->height_inches ?: 72,
            'weight_pounds' => $player->weight_pounds ?: 215,
            'skin_tone' => $player->skin_tone ?: ['#edc5a3', '#c78e61', '#8c5536', '#593b2c'][$player->id % 4],
        ];
    }
}
