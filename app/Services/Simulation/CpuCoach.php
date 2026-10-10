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
        if ($phase === 'extra_point' && $state['quarter'] >= 4) {
            $side = $state['possession'];
            $margin = $state[$side.'_score'] - $state[($side === 'home' ? 'away' : 'home').'_score'];
            if (in_array($margin, [-2, -5, 1], true)) {
                return ['call' => 'two_point_pass', 'formation' => 'shotgun'];
            }
        }
        if ($phase !== 'scrimmage') {
            return ['call' => $phase === 'kickoff' ? 'kickoff' : 'extra_point', 'formation' => 'singleback'];
        }
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $players = $rosters[$side]['players'];
        $margin = $state[$side.'_score'] - $state[$other.'_score'];
        $late = $state['quarter'] >= 4 && $state['clock'] <= ($state['quarter'] >= 5 ? Overtime::periodLength($state) : $state['quarter_length']) / 3;
        $endHalf = app(GameClock::class)->lateHalf($state);
        $range = 40 + ($players['K']['ratings']['kicking'] - 50) * .3;
        if ($endHalf && $state['clock'] <= 15 && 117 - $state['spot'] <= $range && ($state['quarter'] === 2 || $margin <= 0)) {
            return ['call' => 'field_goal', 'formation' => 'singleback'];
        }
        $timeouts = $state['timeouts'][$other] ?? 3;
        if ($state['quarter'] >= 4 && $margin > 0 && $timeouts === 0 && $state['clock'] <= 38 * (4 - $state['down']) + 2 && $state['spot'] > 1) {
            return ['call' => 'kneel', 'formation' => 'singleback'];
        }
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
        if ($endHalf && $state['quarter'] === 2) {
            $passChance += 25;
        }
        if ($late) {
            $passChance += $margin < 0 ? 25 : ($margin > 0 ? -25 : 0);
        }
        if ($this->roll($state, 'offense') > max(15, min(90, $passChance))) {
            $call = $this->roll($state, 'run') < 70 ? 'inside_run' : 'outside_run';
        } else {
            $call = $this->passingCall($state);
        }
        if ($state['spot'] >= 80 && $call === 'deep_pass') {
            $call = 'short_pass';
        }

        $formation = in_array($call, ['inside_run', 'outside_run'], true) ? 'singleback' : ($call === 'deep_pass' ? 'spread' : 'shotgun');
        $roles = array_values(array_filter($formation === 'spread' ? ['WR1', 'WR2', 'WR3'] : ['WR1', 'WR2', 'TE', 'RB'], fn ($role) => isset($players[$role])));
        // PFF's 2025 pre-snap motion rate: 64% (not motion at the snap).
        $motion = $roles && $this->roll($state, 'motion') < 64 ? $roles[$this->roll($state, 'motion-player') % count($roles)] : 'none';

        return ['call' => $call, 'formation' => $formation, 'motion' => $motion];
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
        $call = ($state['phase'] === 'extra_point' || $state['distance'] <= 2) ? ($roll < 70 ? 'run_stop' : 'man_to_man')
            : ($state['distance'] >= 10 && $state['down'] >= 2 ? ($roll < 70 ? 'zone' : ($roll < 85 ? 'blitz' : 'man_to_man'))
                : ($roll < 50 ? 'man_to_man' : ($roll < 75 ? 'zone' : ($roll < 90 ? 'blitz' : 'run_stop'))));

        return ['call' => $call, 'formation' => $call === 'zone' && $state['spot'] < 80 ? 'nickel' : ($call === 'blitz' ? 'single_high' : ($call === 'run_stop' ? 'base_3_5' : 'base_4_3'))];
    }

    public function management(array $state): array
    {
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $margin = $state[$side.'_score'] - $state[$other.'_score'];
        $late = app(GameClock::class)->lateHalf($state);
        $hurry = ($late && $state['quarter'] === 2) || ($state['quarter'] >= 4 && $state['clock'] <= 180 && $margin <= 0);
        $tempo = $hurry ? 'hurry' : ($state['quarter'] >= 4 && $margin > 0 ? 'drain' : 'normal');

        return ['tempo' => $tempo, 'clock_strategy' => $late && ($state['quarter'] === 2 || $margin <= 0) ? 'sideline' : 'normal'];
    }

    public function timeoutTeam(array $state): ?string
    {
        if (! ($state['clock_running'] ?? false) || ($state['phase'] ?? 'scrimmage') !== 'scrimmage') {
            return null;
        }
        $controls = $this->controls($state);
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $margin = $state[$side.'_score'] - $state[$other.'_score'];
        if ($state['quarter'] >= 4 && $state['clock'] <= 180 && $margin > 0 && $controls[$other] === 'cpu' && ($state['timeouts'][$other] ?? 3) > 0) {
            return $other;
        }
        if (app(GameClock::class)->lateHalf($state) && $state['clock'] <= 30 && ($state['quarter'] === 2 || $margin <= 0) && $controls[$side] === 'cpu' && ($state['timeouts'][$side] ?? 3) > 0) {
            return $side;
        }

        return null;
    }

    public function coinChoice(array $state): string
    {
        return $this->roll($state, 'coin-choice') < 80 ? 'kick' : 'receive';
    }

    private function passingCall(array $state): string
    {
        $roll = $this->roll($state, 'pass');
        // Ten yards to go is also a normal first down. Allow short throws
        // there instead of automatically calling only medium/deep routes.
        // Conversion downs still favor routes that can reach the marker.
        if ($state['spot'] >= 85 || $state['distance'] < 10) {
            return $roll < 70 ? 'short_pass' : 'slant';
        }
        if ($state['distance'] >= 20 && $state['spot'] < 80) {
            return match (true) {
                $roll < 10 => 'short_pass',
                $roll < 20 => 'slant',
                $roll < 55 => 'medium_pass',
                default => 'deep_pass',
            };
        }
        if ($state['down'] >= 3) {
            return match (true) {
                $roll < 10 => 'short_pass',
                $roll < 20 => 'slant',
                $roll < 75 => 'medium_pass',
                default => 'deep_pass',
            };
        }

        return match (true) {
            $roll < 40 => 'short_pass',
            $roll < 60 => 'slant',
            $roll < 90 => 'medium_pass',
            default => 'deep_pass',
        };
    }

    private function roll(array $state, string $decision): int
    {
        return (int) sprintf('%u', crc32("cpu-v1:{$state['seed']}:{$state['version']}:{$decision}")) % 100;
    }
}
