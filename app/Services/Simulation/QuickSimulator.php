<?php

namespace App\Services\Simulation;

use LogicException;

class QuickSimulator
{
    public function __construct(private ExhibitionEngine $engine, private CpuCoach $coach, private GamePersonnel $personnel, private GameClock $clock) {}

    public function run(array $state, array $rosters, array $history = [], string $scope = 'game'): array
    {
        if (! in_array($scope, ['drive', 'quarter', 'half', 'game'], true)) {
            throw new LogicException('Unknown Quick Sim scope.');
        }
        $pendingBefore = ($state['penalty_pending'] ?? false) ? ($history[array_key_last($history)]['before'] ?? $state) : null;
        $startQuarter = ($pendingBefore ?? $state)['quarter'];
        $endQuarter = $scope === 'half' && $startQuarter <= 4 ? ($startQuarter <= 2 ? 2 : 4) : $startQuarter;
        $originalControls = $state['controls'] ?? null;
        $hadQuickSim = array_key_exists('quick_sim', $state);
        $originalQuickSim = $state['quick_sim'] ?? false;
        $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
        $state['quick_sim'] = true;
        if ($state['penalty_pending'] ?? false) {
            $index = array_key_last($history);
            $last = $history[$index] ?? [];
            $decision = ($last['penalty']['accepted'] ?? true) ? 'accept' : 'decline';
            $option = $last['penalty_options'][$decision] ?? null;
            if (! $option) {
                throw new LogicException('The pending penalty has no saved decision.');
            }
            $state = $option['state'];
            $history[$index] = $option['play'];
            $history[$index]['penalty']['decided'] = true;
            $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
            $state['quick_sim'] = true;
        }
        if ($state['coin_toss']['pending'] ?? false) {
            $winner = $state['coin_toss']['winner'];
            $choice = $this->coach->coinChoice($state);
            $receiver = $choice === 'receive' ? $winner : ($winner === 'home' ? 'away' : 'home');
            $state['coin_toss']['choice'] = $choice;
            $state['coin_toss']['pending'] = false;
            $state['opening_receiver'] = $receiver;
            $state['possession'] = $receiver === 'home' ? 'away' : 'home';
        }
        $overtime = app(Overtime::class);
        if ($state['overtime']['toss']['call_pending'] ?? false) {
            $state = $overtime->call($state, 'heads');
        } elseif ($state['overtime']['toss']['pending'] ?? false) {
            $state = $overtime->choose($state, $this->coach->coinChoice($state));
        }
        $boundaryReached = in_array($scope, ['quarter', 'half'], true) && $state['quarter'] > $endQuarter;
        if ($scope === 'drive' && $pendingBefore && $pendingBefore['phase'] === 'scrimmage') {
            $boundaryReached = $state['possession'] !== $pendingBefore['possession'] || $state['phase'] !== 'scrimmage'
                || ($pendingBefore['quarter'] === 2 && $state['quarter'] === 3);
        }
        // Use the same engine and CPU decisions as watched games, retaining each replay.
        for ($events = 0; $state['status'] === 'playing' && ! $boundaryReached; $events++) {
            if ($events >= 2000) {
                throw new LogicException('Quick simulation exceeded its play limit.');
            }
            $before = $state;
            $state = $this->clock->normalize($state);
            $timeout = $this->coach->timeoutTeam($state);
            if ($timeout) {
                $result = $this->engine->timeout($state, $rosters, $timeout);
            } else {
                $offense = $this->coach->offense($state, $this->personnel->active($rosters, $state));
                $defense = $this->coach->defense($state, $offense['call']);
                $management = $this->coach->management($state);
                $result = $this->engine->resolve($state, $rosters, $offense['call'], $defense['call'],
                    $offense['formation'], $defense['formation'], $management['tempo'], $management['clock_strategy'],
                    $state['distance'] >= 8 ? 'pass' : ($state['distance'] <= 2 ? 'run' : 'balanced'),
                    false, $offense['motion'] ?? 'none');
            }
            $state = $result['state'];
            $history[] = $result['play'];
            if ($scope === 'drive' && (($before['phase'] === 'scrimmage' && ($state['possession'] !== $before['possession'] || $state['phase'] !== 'scrimmage'))
                || $before['phase'] === 'extra_point' || $state['quarter'] >= 3 && $before['quarter'] < 3)) {
                break;
            }
            if (in_array($scope, ['quarter', 'half'], true) && $state['quarter'] > $endQuarter) {
                break;
            }
        }

        if ($scope !== 'game') {
            if ($originalControls !== null) {
                $state['controls'] = $originalControls;
            } else {
                unset($state['controls']);
            }
            if ($hadQuickSim) {
                $state['quick_sim'] = $originalQuickSim;
            } else {
                unset($state['quick_sim']);
            }
        }

        return ['state' => $state, 'history' => $history];
    }
}
