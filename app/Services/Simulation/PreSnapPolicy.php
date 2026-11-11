<?php

namespace App\Services\Simulation;

/** Shared snap-time clock and pre-snap penalty policy; no wall-clock time is consumed. */
class PreSnapPolicy
{
    /**
     * The caller may discard a prepared snap when cancelled. A non-null result is
     * a completed no-snap boundary and its state/play must be committed together.
     *
     * @return array{state: array, result: array|null}
     */
    public function prepare(array $state, array $rosters, string $tempo = 'normal', string $clockStrategy = 'normal', bool $debugSnap = false): array
    {
        $clock = app(GameClock::class);
        $state = $clock->normalize($state);
        $original = $state;
        $runoff = $debugSnap ? 0 : $clock->runoff($state, $tempo);
        if ($clock->warningDue($state, $runoff)) {
            $state['clock'] = 120;

            return ['state' => $state, 'result' => $this->finish($state, $original, $this->stoppage('Official timeout', 'two_minute_warning') + ['two_minute_warning' => true, 'runoff_seconds' => $original['clock'] - 120], $rosters, 0)];
        }
        if ($runoff >= $state['clock'] && $runoff > 0 && ! $state['untimed_down']) {
            $state['clock'] = 0;

            return ['state' => $state, 'result' => $this->finish($state, $original, $this->stoppage('Clock expires before the snap', 'clock_expired') + ['runoff_seconds' => $original['clock']], $rosters, 0)];
        }
        $state['clock'] -= $runoff;
        // The ready-for-play restart has now been applied. Do not carry it
        // through a different type of stoppage on the next play.
        unset($state['clock_restart_on_ready']);
        $state['_clock_context'] = ['before' => $original, 'runoff' => $runoff, 'tempo' => $tempo, 'strategy' => $clockStrategy];
        $prePenalty = $debugSnap ? null : app(PenaltyRules::class)->preSnap($state);
        if ($prePenalty) {
            $result = app(PenaltyRules::class)->enforce($state, $state, $this->stoppage('No snap', 'penalty'), $prePenalty);

            $finished = $this->finish($result['state'], $state, $result['play'], $rosters, 0);
            if (app(CpuCoach::class)->controls($state)[$result['play']['penalty']['beneficiary']] === 'human') {
                $declined = app(PenaltyRules::class)->enforce($state, $state, $this->stoppage('No snap', 'penalty'), $prePenalty, false);
                $finished['play']['penalty_options'] = ['accept' => $finished, 'decline' => $this->finish($declined['state'], $state, $declined['play'], $rosters, 0)];
                $finished['state']['penalty_pending'] = true;
                $finished['play']['after'] = $finished['state'];
            }

            return ['state' => $state, 'result' => $finished];
        }

        return ['state' => $state, 'result' => null];
    }

    private function finish(array $state, array $before, array $play, array $rosters, int $seconds): array
    {
        return app(PlayFinalizer::class)->finish($state, $before, $play, $rosters, $seconds);
    }

    private function stoppage(string $summary, string $outcome): array
    {
        return ['call' => 'clock_event', 'defense' => 'man_to_man', 'offense_formation' => 'shotgun', 'defense_formation' => 'base_4_3', 'outcome' => $outcome, 'carrier' => 'RB', 'gain' => 0, 'target' => 0, 'summary' => $summary, 'no_snap' => true];
    }
}
