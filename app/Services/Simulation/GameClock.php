<?php

namespace App\Services\Simulation;

class GameClock
{
    public function normalize(array $state): array
    {
        $state += ['timeouts' => ['home' => 3, 'away' => 3], 'clock_running' => false, 'warnings' => ['2' => false, '4' => false], 'untimed_down' => false, 'rules' => ['penalties' => true]];
        if (($state['phase'] ?? '') === 'extra_point') {
            $state['clock_running'] = false;
        }
        foreach (['home', 'away'] as $side) {
            $state['stats'][$side] += ['penalties' => 0, 'penalty_yards' => 0];
        }

        return $state;
    }

    public function lateHalf(array $state): bool
    {
        return ($state['quarter'] === 2 && $state['clock'] <= 120)
            || ($state['quarter'] === 4 && $state['clock'] <= 300)
            || ($state['quarter'] >= 5 && $state['clock'] <= 120);
    }

    public function runoff(array $state, string $tempo): int
    {
        if (! $state['clock_running'] || $state['untimed_down'] || ($state['phase'] ?? 'scrimmage') !== 'scrimmage') {
            return 0;
        }

        return match ($tempo) {
            'hurry' => 3, 'drain' => 38, default => 34
        };
    }

    public function warningDue(array $state, int $seconds): bool
    {
        return ($state['quarter'] === 2 || $state['quarter'] >= 4) && ! ($state['warnings'][$state['quarter']] ?? false)
            && $state['clock'] > 120 && $state['clock'] - $seconds <= 120;
    }

    public function advance(array $state, array $before, array &$play, int $seconds): array
    {
        $state = $this->normalize($state);
        if (($before['phase'] ?? '') === 'extra_point') {
            $seconds = 0;
            $play['runoff_seconds'] = 0;
        }
        $warning = $this->warningDue($before, $seconds);
        $state['clock'] = max(0, $state['clock'] - $seconds);
        $state['clock_running'] = ! ($play['no_snap'] ?? false) && ($state['phase'] ?? 'scrimmage') === 'scrimmage'
            && $state['possession'] === $before['possession'] && ! in_array($play['outcome'], ['incomplete', 'interception', 'fumble', 'spike', 'penalty'], true)
            && ! ($play['out_of_bounds'] ?? false);
        if ($warning || ($play['two_minute_warning'] ?? false)) {
            $state['warnings'][$before['quarter']] = true;
            $state['clock_running'] = false;
            $play['two_minute_warning'] = true;
            $play['summary'] .= ' · TWO-MINUTE WARNING';
        }
        $state['untimed_down'] = $state['clock'] === 0 && ((($before['untimed_down'] ?? false) && ($play['no_snap'] ?? false)) || (($play['penalty']['accepted'] ?? false) && ($play['penalty']['team'] ?? '') !== $before['possession'] && ! ($play['no_snap'] ?? false)));
        if ($state['clock'] === 0 && ($state['quarter'] === 4 || ($state['quarter'] >= 5 && ! Overtime::playoff($state))) && ($state['phase'] ?? '') === 'extra_point' && ($before['phase'] ?? '') !== 'extra_point' && abs($state['home_score'] - $state['away_score']) > 2) {
            $state['phase'] = 'kickoff';
        }
        if ($before['quarter'] >= 5 && isset($state['overtime'])) {
            $state = app(Overtime::class)->afterPlay($state, $before, $play);
        }
        if ($state['status'] === 'final') {
            $play['clock_seconds'] = ($play['runoff_seconds'] ?? 0) + $seconds;

            return $state;
        }
        if ($state['clock'] === 0 && ($state['phase'] ?? '') !== 'extra_point' && ! $state['untimed_down']) {
            $state['clock_running'] = false;
            if ($state['quarter'] >= 4) {
                if ($state['quarter'] === 4 && $state['home_score'] === $state['away_score'] && ($state['rules']['overtime'] ?? 'none') !== 'none') {
                    $state = app(Overtime::class)->begin($state);
                    $play['summary'] .= ' · OVERTIME';
                } elseif ($state['quarter'] >= 5 && Overtime::playoff($state)) {
                    $state = app(Overtime::class)->nextPeriod($state);
                    $play['summary'] .= ' · NEXT OVERTIME PERIOD';
                } else {
                    $state['status'] = 'final';
                    $play['summary'] .= ' · FINAL'.($state['home_score'] === $state['away_score'] ? ' · TIE' : '');
                }
            } else {
                $state['quarter']++;
                $state['clock'] = $state['quarter_length'];
                if ($state['quarter'] === 3) {
                    $state['possession'] = $state['opening_receiver'] ?? 'home';
                    $state['spot'] = 35;
                    $state['down'] = 1;
                    $state['distance'] = 10;
                    $state['phase'] = 'kickoff';
                    $state['timeouts'] = ['home' => 3, 'away' => 3];
                    $play['summary'] .= ' · halftime, '.($state['possession'] === 'home' ? 'away' : 'home').' receives';
                }
            }
        }
        $play['clock_seconds'] = ($play['runoff_seconds'] ?? 0) + $seconds;

        return $state;
    }
}
