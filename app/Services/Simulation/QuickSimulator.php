<?php

namespace App\Services\Simulation;

use LogicException;

class QuickSimulator
{
    public function __construct(private ExhibitionEngine $engine, private CpuCoach $coach, private GamePersonnel $personnel, private GameClock $clock) {}

    public function run(array $state, array $rosters): array
    {
        $state['controls'] = ['home' => 'cpu', 'away' => 'cpu'];
        $state['quick_sim'] = true;
        $history = [];
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
