<?php

namespace App\Services\Simulation;

class Overtime
{
    public static function playoff(array $state): bool
    {
        return str_ends_with($state['rules']['overtime'] ?? '', '_playoff');
    }

    public static function periodLength(array $state): int
    {
        return self::playoff($state) ? 900 : 600;
    }

    public function begin(array $state): array
    {
        $state['quarter'] = 5;
        $state['clock'] = self::periodLength($state);
        $state['clock_running'] = false;
        $state['untimed_down'] = false;
        $state['timeouts'] = self::playoff($state) ? ['home' => 3, 'away' => 3] : ['home' => 2, 'away' => 2];
        $state['warnings']['5'] = false;
        $state['phase'] = 'kickoff';
        $state['spot'] = 35;
        $state['down'] = 1;
        $state['distance'] = 10;
        $state['overtime'] = [
            'completed' => ['home' => false, 'away' => false],
            'baseline' => ['home' => $state['home_score'], 'away' => $state['away_score']],
            'sudden_death' => str_starts_with($state['rules']['overtime'] ?? '', 'traditional'),
            'toss' => ['result' => ((int) sprintf('%u', crc32($state['seed'].':ot-toss')) % 2) === 0 ? 'heads' : 'tails', 'call_pending' => true, 'pending' => false],
        ];
        if (app(CpuCoach::class)->controls($state)['away'] === 'cpu') {
            $state = $this->call($state, 'heads');
        }

        return $state;
    }

    public function nextPeriod(array $state): array
    {
        $state['quarter']++;
        $state['clock'] = self::periodLength($state);
        $state['warnings'][$state['quarter']] = false;
        if ($state['quarter'] % 2 === 1) {
            $state['timeouts'] = ['home' => 3, 'away' => 3];
        }

        return $state;
    }

    public function pending(array $state): bool
    {
        return ($state['overtime']['toss']['call_pending'] ?? false) || ($state['overtime']['toss']['pending'] ?? false);
    }

    public function call(array $state, string $call): array
    {
        $winner = $state['overtime']['toss']['result'] === $call ? 'away' : 'home';
        $state['overtime']['toss'] += ['call' => $call, 'winner' => $winner];
        $state['overtime']['toss']['call_pending'] = false;
        $state['overtime']['toss']['pending'] = true;
        if (app(CpuCoach::class)->controls($state)[$winner] === 'cpu') {
            return $this->choose($state, app(CpuCoach::class)->coinChoice($state));
        }

        return $state;
    }

    public function choose(array $state, string $choice): array
    {
        $winner = $state['overtime']['toss']['winner'];
        $receiver = $choice === 'receive' ? $winner : ($winner === 'home' ? 'away' : 'home');
        $state['overtime']['toss']['choice'] = $choice;
        $state['overtime']['toss']['pending'] = false;
        $state['overtime']['receiver'] = $receiver;
        $state['possession'] = $receiver === 'home' ? 'away' : 'home';

        return $state;
    }

    public function afterPlay(array $state, array $before, array &$play): array
    {
        $ot = $state['overtime'];
        $side = $before['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $phase = $before['phase'] ?? 'scrimmage';
        $scored = $state['home_score'] > $before['home_score'] || $state['away_score'] > $before['away_score'];
        $lead = $state['home_score'] === $state['away_score'] ? null : ($state['home_score'] > $state['away_score'] ? 'home' : 'away');
        $live = ! ($play['no_snap'] ?? false) && ($play['outcome'] ?? '') !== 'penalty';
        if ($live && $phase === 'scrimmage' && ($state['possession'] !== $side || $state['phase'] !== 'scrimmage')) {
            $ot['completed'][$side] = true;
        }
        // A return touchdown ends the receiving team's initial opportunity too.
        if ($live && $phase === 'kickoff' && $state['phase'] === 'extra_point') {
            $ot['completed'][$state['possession']] = true;
        }
        $first = $ot['receiver'];
        if ($ot['completed'][$first] && $state[$first.'_score'] === $ot['baseline'][$first]) {
            $ot['sudden_death'] = true;
        }
        $both = $ot['completed']['home'] && $ot['completed']['away'];
        $tryPending = $state['phase'] === 'extra_point';
        $defensiveScore = $live && $phase === 'scrimmage' && $state[$other.'_score'] > $before[$other.'_score'];
        $win = ($scored && ($ot['sudden_death'] || $defensiveScore))
            || ($both && $lead !== null && (! $tryPending || $lead === $state['possession']));
        if ($both && ! $tryPending && $lead === null) {
            $ot['sudden_death'] = true;
        }
        $state['overtime'] = $ot;
        if ($win) {
            $state['status'] = 'final';
            $state['clock_running'] = false;
            $state['untimed_down'] = false;
            $state['phase'] = 'scrimmage';
            $play['summary'] .= ' · OVERTIME WIN · FINAL';
        }

        return $state;
    }
}
