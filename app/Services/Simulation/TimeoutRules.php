<?php

namespace App\Services\Simulation;

class TimeoutRules
{
    public const TIMINGS = ['now', 'at_one', 'after_lineup'];

    public static function canCall(array $state, string $side): bool
    {
        return ($state['status'] ?? '') === 'playing' && in_array($side, ['home', 'away'], true)
            && in_array($state['phase'] ?? '', ['scrimmage', 'extra_point'], true)
            && ! ($state['penalty_pending'] ?? false) && ($state['timeouts'][$side] ?? 0) > 0
            && ! ($state['timeout_used_since_snap'][$side] ?? false);
    }

    public static function wait(array $state, string $timing): int
    {
        if ($timing === 'now') {
            return 0;
        }
        $playClock = max(1, (int) ($state['play_clock'] ?? 40));
        $limit = $state['clock_running'] ? min($playClock, max(1, $state['clock'])) : $playClock;

        return max(0, min($timing === 'at_one' ? $limit : 7, $limit) - 1);
    }

    /** Formation preview only: no snap, outcome roll, score or player statistics. */
    public static function plan(array $state, array $rosters, array $input): array
    {
        $coach = app(CpuCoach::class);
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $controls = $coach->controls($state);
        $offense = $coach->offense($state, app(GamePersonnel::class)->active($rosters, $state));
        if ($controls[$side] === 'human') {
            $offense = ['call' => $input['call'] ?? $offense['call'], 'formation' => $input['offense_formation'] ?? $offense['formation']];
        }
        $defense = $coach->defense($state, $offense['call']);
        if ($controls[$other] === 'human') {
            $defense['call'] = $input['defense'] ?? $defense['call'];
            $defense['formation'] = $input['defense_formation'] ?? $defense['formation'];
        }

        return ['call' => $offense['call'], 'offense_formation' => $offense['formation'], 'defense_formation' => $defense['formation'], 'defense' => $defense['call']];
    }
}
