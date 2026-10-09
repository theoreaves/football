<?php

namespace App\Services\Simulation;

use LogicException;

class QuickSimulator
{
    public function __construct(private ExhibitionEngine $engine, private CpuCoach $coach, private GamePersonnel $personnel, private GameClock $clock) {}

    public function run(array $state, array $rosters, array $history = []): array
    {
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
        // Use the same engine and CPU decisions as watched games, retaining each replay.
        for ($events = 0; $state['status'] === 'playing'; $events++) {
            if ($events >= 2000) {
                throw new LogicException('Quick simulation exceeded its play limit.');
            }
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
        }

        return ['state' => $state, 'history' => $history];
    }
}
