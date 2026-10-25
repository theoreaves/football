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
        if ($phase === 'kickoff' && SpecialTeamsTricks::cpuOnside($state)) {
            return ['call' => 'onside_kick', 'formation' => 'singleback'];
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
        $aggression = $this->preference($state, 'fourth_down') - 50;
        $preferredGo = $aggression > 0 && $state['spot'] >= 40 && $state['distance'] <= 2 + $aggression / 20
            && $this->roll($state, 'fourth-down') < $aggression;
        if ($state['down'] === 4 && ! $mustGo && ! $preferredGo) {
            $distance = 117 - $state['spot'];
            $range = 40 + ($players['K']['ratings']['kicking'] - 50) * .3;
            if ($distance <= $range) {
                return SpecialTeamsTricks::cpuFake($state, 'field_goal') ?? ['call' => 'field_goal', 'formation' => 'singleback'];
            }
            if ($state['spot'] < 60 || $state['distance'] > 2 || ($aggression < 0 && $this->roll($state, 'fourth-down') < -$aggression)) {
                return SpecialTeamsTricks::cpuFake($state, 'punt') ?? ['call' => 'punt', 'formation' => 'singleback'];
            }
        }
        $passSkill = ($players['QB']['ratings']['throwing'] + $players['WR1']['ratings']['catching']) / 2;
        $runSkill = ($players['RB']['ratings']['speed'] + $players['C']['ratings']['blocking']) / 2;
        $passChance = 48 + ($passSkill - $runSkill) * .6 + ($state['distance'] >= 10 ? 12 : 0) + ($state['down'] >= 3 ? 12 : 0);
        $passChance += ($this->preference($state, 'pass') - 50) * .6;
        if ($state['distance'] <= 2) {
            $passChance -= 28;
        }
        if ($endHalf && $state['quarter'] === 2) {
            $passChance += 25;
        }
        if ($late) {
            $passChance += $margin < 0 ? 25 : ($margin > 0 ? -25 : 0);
        }
        $hurry = $this->management($state)['tempo'] === 'hurry';
        if ($hurry) {
            $passChance = max(92, $passChance);
        }
        if ($this->roll($state, 'offense') > max(15, min($hurry ? 96 : 90, $passChance))) {
            $call = $this->roll($state, 'run') < 70 ? 'inside_run' : 'outside_run';
        } else {
            $call = $this->passingCall($state);
        }
        if ($state['spot'] >= 80 && $call === 'deep_pass') {
            $call = 'short_pass';
        }

        $baseCall = $call;
        $variants = match ($baseCall) {
            'inside_run' => ['inside_run', 'inside_zone', 'counter', 'power', 'lead', 'draw'],
            'outside_run' => ['outside_run', 'outside_zone', 'stretch', 'toss'],
            'short_pass' => ['short_pass', 'quick_pass', 'mesh', 'screen', 'hitch', 'curl', 'drag', 'te_stick', 'rb_flat'],
            'medium_pass' => ['medium_pass', 'flood', 'play_action', 'comeback', 'dig', 'out', 'te_seam'],
            'deep_pass' => ['deep_pass', 'post', 'corner', 'fade', 'wheel'],
            default => [$baseCall],
        };
        $call = $variants[$this->roll($state, 'offensive-concept') % count($variants)];
        $qb = $players['QB']['ratings'];
        $qbChance = min(45, max(0, (($qb['speed'] + $qb['acceleration']) / 2 - 45) * .6) * $this->preference($state, 'qb_run') / 50);
        if (in_array($baseCall, ['inside_run', 'outside_run'], true) && $this->preference($state, 'qb_run') > 0
            && $this->roll($state, 'qb-designed-run') < ($state['distance'] <= 1 ? max(15, $qbChance) : $qbChance)) {
            $call = $state['distance'] <= 1 ? 'qb_sneak' : ['qb_draw', 'qb_power', 'qb_sweep'][$this->roll($state, 'qb-run-type') % 3];
        }
        $formations = array_keys(array_filter(OffensiveOptions::FORMATIONS, fn ($calls) => in_array($call, $calls, true)));
        $formation = $formations[$this->roll($state, 'offensive-formation') % count($formations)];
        if ($hurry && HurryUp::available($state)) {
            $formation = $state['on_field']['offense_formation'];
            $available = OffensiveOptions::FORMATIONS[$formation];
            if (! in_array($call, $available, true)) {
                $passing = array_values(array_filter($available, fn ($c) => in_array(OffensiveOptions::canonical($c), ['slant', 'short_pass', 'medium_pass', 'deep_pass'], true)));
                $call = $passing ? $passing[$this->roll($state, 'hurry-call') % count($passing)] : $available[0];
            }
        }
        $package = PersonnelRotation::PACKAGES[$formation] ?? null;
        $motionRoles = $package ? array_diff($package, ['QB']) : ($formation === 'spread' ? ['WR1', 'WR2', 'WR3'] : ['WR1', 'WR2', 'TE', 'RB']);
        $roles = array_values(array_filter($motionRoles, fn ($role) => isset($players[$role])));
        // PFF's 2025 pre-snap motion rate: 64% (not motion at the snap).
        $motion = $roles && $this->roll($state, 'motion') < 64 ? $roles[$this->roll($state, 'motion-player') % count($roles)] : 'none';

        return ['call' => $call, 'formation' => $formation, 'motion' => $motion];
    }

    public function defense(array $state, string $call): array
    {
        $special = match ($call) {
            'punt', 'fake_punt_run', 'fake_punt_pass' => 'punt_return', 'field_goal', 'fake_field_goal_run', 'fake_field_goal_pass', 'extra_point' => 'field_goal_block', 'kickoff' => 'kickoff_return', 'onside_kick' => 'onside_return', default => null,
        };
        if ($special) {
            return ['call' => $special, 'formation' => 'base_4_3'];
        }
        $roll = $this->roll($state, 'defense');
        $call = ($state['phase'] === 'extra_point' || $state['distance'] <= 2) ? ($roll < 70 ? 'run_stop' : 'man_to_man')
            : ($state['distance'] >= 10 && $state['down'] >= 2 ? ($roll < 70 ? 'zone' : ($roll < 85 ? 'blitz' : 'man_to_man'))
                : ($roll < 50 ? 'man_to_man' : ($roll < 75 ? 'zone' : ($roll < 90 ? 'blitz' : 'run_stop'))));

        $other = $state['possession'] === 'home' ? 'away' : 'home';
        $biases = ['run_stop' => $this->preference($state, 'run_stop', $other), 'blitz' => $this->preference($state, 'blitz', $other), 'man' => $this->preference($state, 'man', $other)];
        if ($biases !== ['run_stop' => 50, 'blitz' => 50, 'man' => 50]) {
            $weights = ($state['phase'] === 'extra_point' || $state['distance'] <= 2)
                ? ['run_stop' => 70, 'man_to_man' => 30, 'zone' => 0, 'blitz' => 0]
                : ($state['distance'] >= 10 && $state['down'] >= 2
                    ? ['zone' => 70, 'blitz' => 15, 'man_to_man' => 15, 'run_stop' => 0]
                    : ['man_to_man' => 50, 'zone' => 25, 'blitz' => 15, 'run_stop' => 10]);
            $weights['run_stop'] = max(2, $weights['run_stop']) * $this->factor($biases['run_stop']);
            $weights['blitz'] = max(2, $weights['blitz']) * $this->factor($biases['blitz']);
            $weights['man_to_man'] *= $this->factor($biases['man']);
            $weights['zone'] = max(2, $weights['zone']) * $this->factor(100 - $biases['man']);
            $pick = $roll / 100 * array_sum($weights);
            foreach ($weights as $candidate => $weight) {
                $pick -= $weight;
                if ($pick < 0) {
                    $call = $candidate;
                    break;
                }
            }
        }

        return ['call' => $call, 'formation' => HurryUp::available($state) && (($state['no_huddle'] ?? false) || $this->management($state)['tempo'] === 'hurry') ? $state['on_field']['defense_formation'] : ($call === 'zone' && $state['spot'] < 80 ? 'nickel' : ($call === 'blitz' ? 'single_high' : ($call === 'run_stop' ? 'base_3_5' : 'base_4_3')))];
    }

    public function management(array $state): array
    {
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $margin = $state[$side.'_score'] - $state[$other.'_score'];
        $late = app(GameClock::class)->lateHalf($state);
        $hurry = ($late && $state['quarter'] === 2) || ($state['quarter'] >= 4 && $state['clock'] <= 180 && $margin <= 0);
        $tempo = $hurry ? 'hurry' : ($state['quarter'] >= 4 && $margin > 0 ? 'drain' : 'normal');

        if (! $late && ! $hurry && ! ($state['quarter'] >= 4 && $margin > 0)) {
            $bias = $this->preference($state, 'tempo') - 50;
            if ($this->roll($state, 'tempo') < abs($bias)) {
                $tempo = $bias > 0 ? 'hurry' : 'drain';
            }
        }

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
        if ($state['quarter'] >= 4 && $state['clock'] <= 180 && $margin > 0 && $controls[$other] === 'cpu' && ($state['timeouts'][$other] ?? 3) > 0 && ! ($state['timeout_used_since_snap'][$other] ?? false)) {
            return $other;
        }
        if (app(GameClock::class)->lateHalf($state) && $state['clock'] <= 30 && ($state['quarter'] === 2 || $margin <= 0) && $controls[$side] === 'cpu' && ($state['timeouts'][$side] ?? 3) > 0 && ! ($state['timeout_used_since_snap'][$side] ?? false)) {
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
        $roll = max(0, min(99, $this->roll($state, 'pass') + (int) (($this->preference($state, 'depth') - 50) * .6)));
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

    private function preference(array $state, string $key, ?string $side = null): int
    {
        $side ??= $state['possession'];

        return max(0, min(100, (int) ($state['coaching'][$side][$key] ?? 50)));
    }

    private function factor(int $value): float
    {
        return 2 ** (($value - 50) / 25);
    }

    private function roll(array $state, string $decision): int
    {
        return (int) sprintf('%u', crc32("cpu-v1:{$state['seed']}:{$state['version']}:{$decision}")) % 100;
    }
}
