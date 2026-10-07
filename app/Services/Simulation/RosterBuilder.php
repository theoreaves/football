<?php

namespace App\Services\Simulation;

use App\Models\Team;
use Illuminate\Validation\ValidationException;

class RosterBuilder
{
    public const GROUPS = [
        'QB' => ['QB'], 'RB' => ['RB', 'FB'], 'WR1' => ['WR'], 'WR2' => ['WR'], 'WR3' => ['WR'], 'TE' => ['TE'],
        'C' => ['C', 'OL'], 'LG' => ['LG', 'G', 'OL'], 'RG' => ['RG', 'G', 'OL'], 'LT' => ['LT', 'T', 'OL'], 'RT' => ['RT', 'T', 'OL'],
        'DE1' => ['DE', 'DL'], 'DT1' => ['DT', 'NT', 'DL'], 'DT2' => ['DT', 'NT', 'DL'], 'DE2' => ['DE', 'DL'],
        'LB1' => ['LB', 'OLB', 'ILB', 'MLB'], 'LB2' => ['LB', 'ILB', 'MLB', 'OLB'], 'LB3' => ['LB', 'OLB', 'ILB', 'MLB'],
        'CB1' => ['CB', 'DB'], 'CB2' => ['CB', 'DB'], 'S1' => ['S', 'FS', 'SS', 'DB'], 'S2' => ['S', 'SS', 'FS', 'DB'], 'K' => ['K'], 'P' => ['P'], 'LB4' => ['LB', 'OLB', 'ILB', 'MLB'], 'LB5' => ['LB', 'OLB', 'ILB', 'MLB'], 'CB3' => ['CB', 'DB'], 'CB4' => ['CB', 'DB'],
    ];

    public function build(Team $team): array
    {
        $year = $team->players()->max('team_players.team_year');
        $players = $team->players()->wherePivot('team_year', $year)->get()
            ->sortBy(fn ($p) => [preg_replace('/\d+$/', '', $p->pivot->depth_chart_position ?? ''), (int) preg_replace('/\D/', '', $p->pivot->depth_chart_position ?? '99'), $p->id]);
        $groups = self::GROUPS;
        $used = [];
        $roster = [];
        foreach ($groups as $role => $positions) {
            $player = $players->first(fn ($p) => ! in_array($p->id, $used, true) && in_array(strtoupper($p->pivot->position ?: $p->position), $positions, true));
            if (! $player && in_array($role, ['LB4', 'LB5', 'CB3', 'CB4'], true)) {
                continue;
            }
            if (! $player) {
                throw ValidationException::withMessages(['teams' => "{$team->city} {$team->name} needs a {$role} in its latest roster ({$year}). Add players in Teams before starting."]);
            }
            $used[] = $player->id;
            $roster[$role] = $this->snapshot($player);
        }

        return app(GamePersonnel::class)->active(['team' => ['year' => $year, 'players' => $roster, 'pool' => $players->map(fn ($player) => $this->snapshot($player))->values()->all()]], [])['team'];
    }

    private function snapshot(\App\Models\Player $player): array
    {
        $ratings = app(PlayerRatings::class)->forPlayer($player);
        $position = strtoupper($player->pivot->position ?: $player->position);
        $lineman = in_array($position, ['C', 'G', 'T', 'OL', 'LG', 'RG', 'LT', 'RT', 'DT', 'NT']);
        $profile = [
            'height_inches' => $player->height_inches ?: ($lineman ? 76 : 72),
            'weight_pounds' => $player->weight_pounds ?: ($lineman ? 305 : 215),
            'skin_tone' => $player->skin_tone ?: ['#edc5a3', '#c78e61', '#8c5536', '#593b2c'][$player->id % 4],
        ];
        $missing = array_filter($profile, fn ($value, $key) => $player->{$key} === null, ARRAY_FILTER_USE_BOTH);
        if (! $player->simulation_ratings) {
            $missing['simulation_ratings'] = $ratings;
        }
        if ($missing) {
            $player->update($missing);
        }

        return array_merge(['id' => $player->id, 'name' => trim($player->firstname.' '.$player->lastname), 'number' => $player->pivot->jersey_number, 'position' => $position, 'depth' => $player->pivot->depth_chart_position, 'ratings' => $ratings], $profile);
    }
}
