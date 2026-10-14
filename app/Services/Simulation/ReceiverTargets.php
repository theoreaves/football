<?php

namespace App\Services\Simulation;

class ReceiverTargets
{
    // Weights describe opportunities among players currently on the field,
    // not probabilities of completing the pass.
    private const WEIGHTS = [
        'play_action' => ['WR1' => 30, 'WR2' => 20, 'WR3' => 5, 'TE' => 35, 'RB' => 10],
        'mesh' => ['WR1' => 30, 'WR2' => 30, 'WR3' => 15, 'TE' => 20, 'RB' => 5],
        'flood' => ['WR1' => 35, 'WR2' => 25, 'WR3' => 10, 'TE' => 25, 'RB' => 5],
        'screen' => ['WR1' => 30, 'WR2' => 20, 'WR3' => 15, 'TE' => 5, 'RB' => 30],
        'slant' => ['WR1' => 34, 'WR2' => 27, 'WR3' => 18, 'TE' => 16, 'RB' => 5],
        'short_pass' => ['WR1' => 29, 'WR2' => 24, 'WR3' => 14, 'TE' => 19, 'RB' => 14],
        'medium_pass' => ['WR1' => 36, 'WR2' => 29, 'WR3' => 14, 'TE' => 17, 'RB' => 4],
        'deep_pass' => ['WR1' => 44, 'WR2' => 34, 'WR3' => 15, 'TE' => 6, 'RB' => 1],
    ];

    public function choose(array $offense, string $design, float $roll): string
    {
        $base = self::WEIGHTS[$design] ?? self::WEIGHTS['short_pass'];
        foreach (['TE2' => 'WR3', 'FB' => 'WR3', 'WR4' => 'RB'] as $role => $replaced) {
            if (isset($offense[$role])) {
                $base[$role] = $base[$replaced] ?? 10;
            }
        }
        $weights = [];
        foreach ($base as $role => $share) {
            if (! isset($offense[$role])) {
                continue;
            }
            $ratings = $offense[$role]['ratings'] ?? [];
            $preference = max(1, min(99, (int) ($ratings['target_preference'] ?? 65)));
            $weights[$role] = $share * (0.25 + $preference / 65);
        }
        if (! $weights) {
            return 'WR1';
        }
        $needle = max(0, min(0.999999, $roll)) * array_sum($weights);
        foreach ($weights as $role => $weight) {
            $needle -= $weight;
            if ($needle < 0) {
                return $role;
            }
        }

        return array_key_last($weights);
    }
}
