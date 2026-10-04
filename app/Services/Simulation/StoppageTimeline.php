<?php

namespace App\Services\Simulation;

class StoppageTimeline
{
    public function build(array $play, array $rosters): array
    {
        $base = app(PlayTimeline::class)->build(array_merge($play, ['call' => 'inside_run', 'outcome' => 'tackle', 'carrier' => 'RB', 'gain' => 0, 'target' => 0]), $rosters);
        foreach ($base['players'] as &$player) {
            $point = $player['path'][0];
            $player['path'] = [$point, [1, $point[1], $point[2], $point[3]]];
        }
        unset($player);
        $ball = $base['ball'][0];
        $base['ball'] = [$ball, [1, $ball[1], $ball[2], $ball[3]]];
        $base['duration'] = 1;
        $base['reveal_at'] = .8;
        $base['no_snap'] = true;
        $base['ballHolders'] = [[0, null, null]];
        $base['events'] = [[0, 'Between plays'], [.8, $play['summary']]];

        return $base;
    }
}
