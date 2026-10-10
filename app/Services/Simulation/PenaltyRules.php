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

        return $roll < 2 ? 'false_start' : ($roll < 4 ? 'encroachment' : null);
    }

    public function live(array $before, array $play): ?string
    {
        if (($before['phase'] ?? '') === 'extra_point') {
            return null;
        }

        if (! ($before['rules']['penalties'] ?? false) || isset($play['penalty']) || ($play['no_snap'] ?? false) || in_array($play['call'], ['punt', 'field_goal', 'kickoff', 'extra_point', 'spike', 'kneel'], true)) {
            return null;
        }
        if (($play['dev_force_flag'] ?? false) === true) {
            return 'holding';
        }
        if (in_array($play['dev_force_result'] ?? 'none', ['defensive_offside', 'illegal_formation'], true)) {
            return $play['dev_force_result'];
        }
        if (in_array($play['dev_force_result'] ?? 'none', ['touchdown', 'turnover'], true)) {
            return null;
        }
        $roll = $this->roll($before, 'live');
        if ($roll < 6) {
            return 'holding';
        }
        if ($roll < 10 && in_array($play['call'], ['slant', 'short_pass', 'medium_pass', 'deep_pass'], true) && $play['carrier'] !== 'QB') {
            return 'defensive_pass_interference';
        }

        if ($roll >= 12 && $roll < 14) {
            return 'defensive_offside';
        }
        if ($roll >= 14 && $roll < 16) {
            return 'illegal_formation';
        }

        return $roll >= 10 && $roll < 12 && in_array($play['outcome'], ['tackle', 'touchdown'], true) ? 'face_mask' : null;
    }

    public function enforce(array $before, array $after, array $play, string $type, ?bool $choice = null): array
    {
        $side = $before['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $offense = in_array($type, ['holding', 'false_start', 'illegal_formation'], true);
        $foulSide = $offense ? $side : $other;
        $dead = in_array($type, ['false_start', 'encroachment'], true);
        $playResult = $play['summary'];
        $accepted = $dead || ($offense ? $after['possession'] === $side && $after[$other.'_score'] === $before[$other.'_score'] && $play['outcome'] !== 'safety' : $play['outcome'] !== 'touchdown');
        // Keep a better live result instead of replacing it with five yards.
        if ($choice === null && $type === 'defensive_offside' && $after['possession'] === $side
            && $play['gain'] >= min(5, max(1, intdiv(100 - $before['spot'], 2)))
            && ($after['down'] === 1 || $play['gain'] >= 10)
            && ! in_array($play['outcome'], ['incomplete', 'interception', 'fumble', 'sack'], true)) {
            $accepted = false;
        }
        $accepted = $choice ?? $accepted;
        $yards = 0;
        if ($accepted) {
            if ($offense) {
                $yards = min($type === 'holding' ? 10 : 5, max(0, intdiv($before['spot'], 2)));
                $after = $before;
                $after['spot'] = max(1, $before['spot'] - $yards);
                $yards = $before['spot'] - $after['spot'];
                $after['distance'] = $before['distance'] + $yards;
            } elseif (in_array($type, ['encroachment', 'defensive_offside'], true)) {
                $yards = min(5, max(1, intdiv(100 - $before['spot'], 2)));
                $after = $before;
                $after['spot'] = min(99, $before['spot'] + $yards);
                $yards = $after['spot'] - $before['spot'];
                if ($yards >= $before['distance']) {
                    $after['down'] = 1;
                    $after['distance'] = min(10, 100 - $after['spot']);
                } else {
                    $after['distance'] = $before['distance'] - $yards;
                }
            } elseif ($type === 'defensive_pass_interference') {
                if ($choice === null && $after['possession'] === $side && $play['gain'] > $play['target'] && $after['down'] === 1) {
                    $accepted = false;
                } else {
                    $after = $before;
                    $after['spot'] = min(99, $before['spot'] + max(1, $play['target']));
                    $yards = $after['spot'] - $before['spot'];
                    $after['down'] = 1;
                    $after['distance'] = min(10, 100 - $after['spot']);
                }
            } else {
                if ($after['possession'] !== $side) {
                    $after['possession'] = $side;
                    $after['spot'] = max(1, min(99, $before['spot'] + $play['gain']));
                }
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
        $explanation = match ($type) {
            'false_start' => 'An offensive player moved before the snap. Five yards against the offense; repeat the down.',
            'encroachment' => 'A defender entered the neutral zone before the snap. Five yards against the defense; a first down if the line to gain is reached.',
            'defensive_offside' => 'A defender was in or beyond the neutral zone at the snap. The play continues. Five yards from the previous spot; repeat the down unless the line to gain is reached.',
            'illegal_formation' => 'The offense had fewer than seven players on the line at the snap. The play continues. Five yards against the offense; repeat the down if accepted.',
            'holding' => 'An offensive player illegally held a defender. Ten yards against the offense; repeat the down. The play result is erased if accepted.',
            'defensive_pass_interference' => 'A defender illegally interfered with the receiver. Ball at the foul spot and an automatic first down if accepted.',
            'face_mask' => 'A defender grabbed the ball carrier’s face mask. Fifteen yards from the end of the run and an automatic first down if accepted.',
        };
        $play['penalty'] = ['type' => $type, 'team' => $foulSide, 'beneficiary' => $offense ? $other : $side, 'yards' => $yards, 'accepted' => $accepted, 'explanation' => $explanation, 'play_result' => $playResult];
        $play['no_snap'] = $dead;
        $play['summary'] .= ' · FLAG: '.ucwords(str_replace('_', ' ', $type)).' on '.$foulSide.($accepted ? " · {$yards} yards".(in_array($type, ['holding', 'false_start', 'illegal_formation'], true) ? ' · repeat down' : (! in_array($type, ['encroachment', 'defensive_offside'], true) ? ' · automatic first down' : '')) : ($dead ? ' · declined, no snap; down unchanged' : ' · declined, play stands'));

        if ($accepted && in_array($type, ['encroachment', 'defensive_offside'], true)) {
            $distance = $after['distance'] >= 100 - $after['spot'] ? 'Goal' : $after['distance'];
            $play['summary'] .= ' · Down '.$after['down'].' & '.$distance;
        }

        return ['state' => $after, 'play' => $play];
    }

    private function roll(array $state, string $stage): int
    {
        return (int) sprintf('%u', crc32("penalty-v1:{$state['seed']}:{$state['version']}:{$stage}")) % 100;
    }
}
