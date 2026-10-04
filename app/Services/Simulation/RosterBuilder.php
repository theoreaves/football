<?php

namespace App\Services\Simulation;

use App\Models\Team;
use Illuminate\Validation\ValidationException;

class RosterBuilder
{
    public function build(Team $team): array
    {
        $year = $team->players()->max('team_players.team_year');
        $players = $team->players()->wherePivot('team_year', $year)->get()
            ->sortBy(fn ($p) => [preg_replace('/\d+$/', '', $p->pivot->depth_chart_position ?? ''), (int) preg_replace('/\D/', '', $p->pivot->depth_chart_position ?? '99'), $p->id]);
        $groups = [
            'QB' => ['QB'], 'RB' => ['RB', 'FB'], 'WR1' => ['WR'], 'WR2' => ['WR'], 'WR3' => ['WR'], 'TE' => ['TE'],
            'C' => ['C', 'OL'], 'LG' => ['LG', 'G', 'OL'], 'RG' => ['RG', 'G', 'OL'], 'LT' => ['LT', 'T', 'OL'], 'RT' => ['RT', 'T', 'OL'],
            'DE1' => ['DE', 'DL'], 'DT1' => ['DT', 'NT', 'DL'], 'DT2' => ['DT', 'NT', 'DL'], 'DE2' => ['DE', 'DL'],
            'LB1' => ['LB', 'OLB', 'ILB', 'MLB'], 'LB2' => ['LB', 'ILB', 'MLB', 'OLB'], 'LB3' => ['LB', 'OLB', 'ILB', 'MLB'],
            'CB1' => ['CB', 'DB'], 'CB2' => ['CB', 'DB'], 'S1' => ['S', 'FS', 'SS', 'DB'], 'S2' => ['S', 'SS', 'FS', 'DB'], 'K' => ['K'], 'P' => ['P'],
        ];
        $used = [];
        $roster = [];
        foreach ($groups as $role => $positions) {
            $player = $players->first(fn ($p) => ! in_array($p->id, $used, true) && in_array(strtoupper($p->pivot->position ?: $p->position), $positions, true));
            if (! $player) {
                throw ValidationException::withMessages(['teams' => "{$team->city} {$team->name} needs a {$role} in its latest roster ({$year}). Add players in Teams before starting."]);
            }
            $used[] = $player->id;
            $ratings = app(PlayerRatings::class)->forPlayer($player);
            if (! $player->simulation_ratings) {
                $player->update(['simulation_ratings' => $ratings]);
            }
            $roster[$role] = ['id' => $player->id, 'name' => trim($player->firstname.' '.$player->lastname), 'number' => $player->pivot->jersey_number, 'ratings' => $ratings];
        }

        return ['year' => $year, 'players' => $roster];
    }
}
