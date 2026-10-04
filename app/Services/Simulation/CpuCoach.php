<?php

namespace App\Services\Simulation;

class CpuCoach
{
    public function controls(array $state): array
    {
        return $state['controls'] ?? ['home' => 'human', 'away' => 'human'];
    }

    public function offense(array $state, array $rosters): array
    {
        $phase = $state['phase'] ?? 'scrimmage';
        if ($phase !== 'scrimmage') {
            return ['call' => $phase === 'kickoff' ? 'kickoff' : 'extra_point', 'formation' => 'singleback'];
        }
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $players = $rosters[$side]['players'];
        $margin = $state[$side.'_score'] - $state[$other.'_score'];
        $late = $state['quarter'] === 4 && $state['clock'] <= $state['quarter_length'] / 3;
        $mustGo = $late && $margin < 0 && ($margin < -3 || $state['spot'] < 60);
        if ($state['down'] === 4 && ! $mustGo) {
            $distance = 117 - $state['spot'];
            $range = 40 + ($players['K']['ratings']['kicking'] - 50) * .3;
            if ($distance <= $range) {
                return ['call' => 'field_goal', 'formation' => 'singleback'];
            }
            if ($state['spot'] < 60 || $state['distance'] > 2) {
                return ['call' => 'punt', 'formation' => 'singleback'];
            }
        }
        $passSkill = ($players['QB']['ratings']['throwing'] + $players['WR1']['ratings']['catching']) / 2;
        $runSkill = ($players['RB']['ratings']['speed'] + $players['C']['ratings']['blocking']) / 2;
        $passChance = 48 + ($passSkill - $runSkill) * .6 + ($state['distance'] >= 10 ? 12 : 0) + ($state['down'] >= 3 ? 12 : 0);
        if ($state['distance'] <= 2) {
            $passChance -= 28;
        }
        if ($late) {
            $passChance += $margin < 0 ? 25 : ($margin > 0 ? -25 : 0);
        }
        if ($this->roll($state, 'offense') > max(15, min(90, $passChance))) {
            $call = $this->roll($state, 'run') < 70 ? 'inside_run' : 'outside_run';
        } elseif ($state['distance'] >= 20 && $state['spot'] < 80) {
            $call = 'deep_pass';
        } elseif ($state['distance'] >= 10 && $state['spot'] < 85) {
            $call = $this->roll($state, 'pass') < 75 ? 'medium_pass' : 'deep_pass';
        } else {
            $call = $this->roll($state, 'pass') < 70 ? 'short_pass' : 'slant';
        }
        if ($state['spot'] >= 80 && $call === 'deep_pass') {
            $call = 'short_pass';
        }

        return ['call' => $call, 'formation' => in_array($call, ['inside_run', 'outside_run'], true) ? 'singleback' : ($call === 'deep_pass' ? 'spread' : 'shotgun')];
    }

    public function defense(array $state, string $call): array
    {
        $special = match ($call) {
            'punt' => 'punt_return', 'field_goal', 'extra_point' => 'field_goal_block', 'kickoff' => 'kickoff_return', default => null,
        };
        if ($special) {
            return ['call' => $special, 'formation' => 'base_4_3'];
        }
        $roll = $this->roll($state, 'defense');
        $call = $state['distance'] <= 2 ? ($roll < 70 ? 'run_commit' : 'balanced')
            : ($state['distance'] >= 10 && $state['down'] >= 2 ? ($roll < 70 ? 'coverage' : ($roll < 85 ? 'blitz' : 'balanced'))
                : ($roll < 50 ? 'balanced' : ($roll < 75 ? 'coverage' : ($roll < 90 ? 'blitz' : 'run_commit'))));

        return ['call' => $call, 'formation' => $call === 'coverage' && $state['spot'] < 80 ? 'two_high' : ($call === 'blitz' ? 'single_high' : 'base_4_3')];
    }

    private function roll(array $state, string $decision): int
    {
        return (int) sprintf('%u', crc32("cpu-v1:{$state['seed']}:{$state['version']}:{$decision}")) % 100;
    }
}
