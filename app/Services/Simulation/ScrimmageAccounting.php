<?php

namespace App\Services\Simulation;

/** Applies an already-resolved scrimmage outcome without generating randomness. */
class ScrimmageAccounting
{
    public function apply(array $state, array $before, string $outcome, int $gain, bool $flip, string $summary): array
    {
        $side = $before['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $state['stats'][$side]['plays']++;
        if (! in_array($outcome, ['punt', 'field_goal_good', 'field_goal_missed', 'interception'], true)) {
            $state['stats'][$side]['yards'] += $gain;
        }
        $deadSpot = $before['spot'] + $gain;
        if ($flip) {
            $state['stats'][$side]['turnovers']++;
            if ($deadSpot <= 0) {
                $state[$other.'_score'] += 6;
                $summary .= ' · defensive touchdown';
                $this->possession($state, $other, 85);
                $state['phase'] = 'extra_point';
            } else {
                $this->possession($state, $other, $deadSpot >= 100 ? 20 : 100 - $deadSpot);
            }
        } elseif ($outcome !== 'incomplete' && $deadSpot >= 100) {
            $outcome = 'touchdown';
            $summary .= ' · TOUCHDOWN';
            $state[$side.'_score'] += 6;
            $this->possession($state, $side, 85);
            $state['phase'] = 'extra_point';
        } elseif ($outcome !== 'incomplete' && $deadSpot <= 0) {
            $outcome = 'safety';
            $summary .= ' · SAFETY';
            $state[$other.'_score'] += 2;
            $this->possession($state, $side, 20);
            $state['phase'] = 'kickoff';
        } else {
            $state['spot'] = $deadSpot;
            if ($gain >= $before['distance']) {
                $state['down'] = 1;
                $state['distance'] = min(10, 100 - $deadSpot);
                $summary .= ' · first down';
            } else {
                $state['down']++;
                $state['distance'] -= $gain;
                if ($state['down'] > 4) {
                    $summary .= ' · turnover on downs';
                    $this->possession($state, $other, 100 - $deadSpot);
                }
            }
        }

        return ['state' => $state, 'outcome' => $outcome, 'summary' => $summary];
    }

    private function possession(array &$state, string $side, int $spot): void
    {
        $state['possession'] = $side;
        $state['spot'] = $spot;
        $state['down'] = 1;
        $state['distance'] = min(10, 100 - $spot);
    }
}
