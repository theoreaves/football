<?php

namespace App\Services\Simulation;

class PenaltyRules
{
    public function preSnap(array $state): ?string
    {
        if (! ($state['rules']['penalties'] ?? false) || ($state['phase'] ?? 'scrimmage') !== 'scrimmage') {
            return null;
        }
        $roll = $this->roll($state, 'pre');

        return $roll < 1 ? 'false_start' : ($roll < 2 ? 'encroachment' : null);
    }

    public function live(array $before, array $play): ?string
    {
        if (! ($before['rules']['penalties'] ?? false) || ($play['no_snap'] ?? false) || in_array($play['call'], ['punt', 'field_goal', 'kickoff', 'extra_point', 'spike', 'kneel'], true)) {
            return null;
        }
        $roll = $this->roll($before, 'live');
        if ($roll < 3) {
            return 'holding';
        }
        if ($roll < 5 && in_array($play['call'], ['slant', 'short_pass', 'medium_pass', 'deep_pass'], true) && $play['carrier'] !== 'QB') {
            return 'defensive_pass_interference';
        }

        return $roll >= 5 && $roll < 6 && in_array($play['outcome'], ['tackle', 'touchdown'], true) ? 'face_mask' : null;
    }

    public function enforce(array $before, array $after, array $play, string $type): array
    {
        $side = $before['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $offense = in_array($type, ['holding', 'false_start'], true);
        $foulSide = $offense ? $side : $other;
        $dead = in_array($type, ['false_start', 'encroachment'], true);
        $accepted = $dead || ($offense ? $after['possession'] === $side && $after[$other.'_score'] === $before[$other.'_score'] && $play['outcome'] !== 'safety' : $play['outcome'] !== 'touchdown');
        $yards = 0;
        if ($accepted) {
            if ($offense) {
                $yards = min($type === 'holding' ? 10 : 5, max(0, intdiv($before['spot'], 2)));
                $after = $before;
                $after['spot'] = max(1, $before['spot'] - $yards);
                $yards = $before['spot'] - $after['spot'];
                $after['distance'] = $before['distance'] + $yards;
            } elseif ($type === 'encroachment') {
                $yards = min(5, max(1, intdiv(100 - $before['spot'], 2)));
                $after = $before;
                $after['spot'] = min(99, $before['spot'] + $yards);
                $yards = $after['spot'] - $before['spot'];
                if ($yards >= $before['distance']) {
                    $after['down'] = 1;
                    $after['distance'] = min(10, 100 - $after['spot']);
                } else {
                    $after['distance'] -= $yards;
                }
            } elseif ($type === 'defensive_pass_interference') {
                if ($after['possession'] === $side && $play['gain'] > $play['target'] && $after['down'] === 1) {
                    $accepted = false;
                } else {
                    $after = $before;
                    $after['spot'] = min(99, $before['spot'] + max(1, $play['target']));
                    $yards = $after['spot'] - $before['spot'];
                    $after['down'] = 1;
                    $after['distance'] = min(10, 100 - $after['spot']);
                }
            } else {
                $yards = min(15, max(1, intdiv(100 - $after['spot'], 2)));
                $start = $after['spot'];
                $after['spot'] = min(99, $after['spot'] + $yards);
                $yards = $after['spot'] - $start;
                $after['down'] = 1;
                $after['distance'] = min(10, 100 - $after['spot']);
            }
        }
        if ($accepted) {
            $after['stats'][$foulSide]['penalties'] = ($after['stats'][$foulSide]['penalties'] ?? 0) + 1;
            $after['stats'][$foulSide]['penalty_yards'] = ($after['stats'][$foulSide]['penalty_yards'] ?? 0) + $yards;
            if ($type !== 'face_mask') {
                $play['nullified_summary'] = $play['summary'];
                $play['live_outcome'] = $play['outcome'];
                $play['summary'] = $dead ? 'No snap' : 'Play nullified';
                $play['outcome'] = 'penalty';
            }
        }
        $play['penalty'] = ['type' => $type, 'team' => $foulSide, 'yards' => $yards, 'accepted' => $accepted];
        $play['no_snap'] = $dead;
        $play['summary'] .= ' · FLAG: '.ucwords(str_replace('_', ' ', $type)).' on '.$foulSide.($accepted ? " · {$yards} yards".($type === 'holding' || $type === 'false_start' ? ' · repeat down' : ($type !== 'encroachment' ? ' · automatic first down' : '')) : ' · declined, play stands');

        return ['state' => $after, 'play' => $play];
    }

    private function roll(array $state, string $stage): int
    {
        return (int) sprintf('%u', crc32("penalty-v1:{$state['seed']}:{$state['version']}:{$stage}")) % 100;
    }
}
