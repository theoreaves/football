<?php

namespace App\Services\Simulation;

use App\Models\Player;

class PlayerRatings
{
    public const FIELDS = ['speed', 'acceleration', 'strength', 'awareness', 'throwing', 'catching', 'target_preference', 'blocking', 'coverage', 'tackling', 'ball_security', 'kicking', 'stamina', 'durability'];

    public function generate(Player $player): array
    {
        $position = strtoupper($player->position ?? '');
        $specialties = match ($position) {
            'QB' => ['throwing', 'awareness', 'ball_security'],
            'RB', 'FB' => ['speed', 'acceleration', 'ball_security', 'target_preference'],
            'WR', 'TE' => ['speed', 'acceleration', 'catching', 'target_preference'],
            'OL', 'C', 'G', 'T', 'LG', 'RG', 'LT', 'RT' => ['strength', 'blocking'],
            'DL', 'DE', 'DT', 'NT' => ['strength', 'tackling'],
            'LB', 'ILB', 'OLB', 'MLB' => ['awareness', 'tackling', 'strength'],
            'CB', 'S', 'FS', 'SS', 'DB' => ['speed', 'coverage', 'tackling'],
            'K', 'P' => ['kicking'],
            default => ['awareness'],
        };
        $ratings = [];
        foreach (self::FIELDS as $field) {
            $ratings[$field] = 35 + (int) sprintf('%u', crc32("football-v1:{$player->id}:{$field}")) % 31 + (in_array($field, $specialties, true) ? 20 : 0);
        }

        return $ratings;
    }

    public function forPlayer(Player $player): array
    {
        $saved = $player->simulation_ratings ?? [];

        return array_merge($this->generate($player), $saved);
    }
}
