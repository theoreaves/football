<?php

namespace App\Services\Simulation;

class StoppageTimeline
{
    public function build(array $play, array $rosters): array
    {
        $lineup = ($play['timeout_timing'] ?? '') === 'after_lineup';
        $plan = $lineup ? ($play['timeout_plan'] ?? []) : [];
        $preview = array_merge($play, ['call' => 'inside_run', 'outcome' => 'tackle', 'carrier' => 'RB', 'gain' => 0, 'target' => 0], $plan);
        if (in_array($preview['call'], ['field_goal', 'extra_point', 'punt'], true)) {
            $preview['carrier'] = $preview['call'] === 'punt' ? 'P' : 'K';
        }
        $base = $lineup && in_array($preview['call'], ['field_goal', 'extra_point', 'punt'], true)
            ? app(SpecialTeamsTimeline::class)->build($preview + ['landing' => 100, 'return_yards' => 0], $rosters)
            : app(PlayTimeline::class)->build($preview, $rosters);
        $duration = $lineup ? 4.2 : max(1, ($play['timeout_wait_seconds'] ?? 0) + 1);
        $reveal = $lineup ? 3.5 : max(.8, $duration - .2);
        $index = ['offense' => 0, 'defense' => 0];
        $direction = $play['before']['possession'] === 'home' ? 1 : -1;
        foreach ($base['players'] as &$player) {
            $point = $player['path'][0];
            $start = $point;
            $slot = $index[$player['team']]++;
            if ($lineup && ! ($play['before']['no_huddle'] ?? false)) {
                if ($player['team'] === 'offense') {
                    $angle = $slot * 2 * M_PI / 11;
                    $start[1] = max(.5, min(119.5, $base['line'] - $direction * 8 + cos($angle) * 2.4));
                    $start[3] = 26.7 + sin($angle) * 2.4;
                } else {
                    $start[1] = max(.5, min(119.5, $point[1] + $direction * 1.5));
                }
            }
            $player['path'] = $lineup ? [$start, [3, $point[1], $point[2], $point[3]], [$duration, $point[1], $point[2], $point[3]]]
                : [$point, [$duration, $point[1], $point[2], $point[3]]];
        }
        unset($player);
        $ball = $base['ball'][0];
        $base['ball'] = [$ball, [$duration, $ball[1], .25, $ball[3]]];
        $base['duration'] = $duration;
        $base['reveal_at'] = $reveal;
        $base['no_snap'] = true;
        $base['passing'] = $base['dropback'] = false;
        $base['throw_at'] = null;
        $base['call'] = 'clock_event';
        $base['outcome'] = $play['outcome'];
        $base['timeout_at'] = $play['outcome'] === 'timeout' ? $reveal : null;
        $base['ballHolders'] = [[0, null, null]];
        $base['events'] = [[0, $lineup ? 'Both teams lining up · Timeout before the snap' : (($play['timeout_timing'] ?? '') === 'at_one' ? 'Waiting for 1 second · Timeout queued' : 'Between plays')], [$reveal, $play['summary']]];

        return $base;
    }
}
