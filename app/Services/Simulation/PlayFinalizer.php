<?php

namespace App\Services\Simulation;

/**
 * Finalizes an already-accounted play without resolving another football outcome.
 *
 * Callers supply the post-outcome state, snap state, selected active rosters and
 * elapsed seconds. Scoring, yardage, downs and possession are accounted upstream.
 * Live penalties, clock rules, presentation and personnel retain Coach behavior.
 */
class PlayFinalizer
{
    /**
     * @param  array|null  $recordedAnimation  Validated world-space recording with actual participant IDs; never mirrored or regenerated.
     */
    public function finish(array $state, array $before, array $play, array $rosters, int $seconds, ?array $recordedAnimation = null): array
    {
        $state = app(GameClock::class)->normalize($state);
        $snapBefore = app(GameClock::class)->normalize($before);
        $context = $before['_clock_context'] ?? [];
        $historyBefore = $context['before'] ?? $snapBefore;
        $play += ['runoff_seconds' => $context['runoff'] ?? 0, 'tempo' => $context['tempo'] ?? 'normal', 'clock_strategy' => $context['strategy'] ?? 'normal', 'snap_clock' => $snapBefore['clock']];
        $penalty = app(PenaltyRules::class)->live($snapBefore, $play);
        $decisionOptions = null;
        if ($penalty) {
            $result = app(PenaltyRules::class)->enforce($snapBefore, $state, $play, $penalty);
            $beneficiary = $result['play']['penalty']['beneficiary'];
            if (app(CpuCoach::class)->controls($state)[$beneficiary] === 'human') {
                foreach (['accept' => true, 'decline' => false] as $decision => $choice) {
                    $option = app(PenaltyRules::class)->enforce($snapBefore, $state, $play, $penalty, $choice);
                    $decisionOptions[$decision] = $this->finish($option['state'], $before, $option['play'], $rosters, $seconds, $recordedAnimation);
                }
            }
            $state = $result['state'];
            $play = $result['play'];
        }
        $state = app(GameClock::class)->advance($state, $snapBefore, $play, $seconds);
        unset($state['_clock_context'], $historyBefore['_clock_context']);
        $before = $historyBefore;
        $state['version']++;
        $play += ['number' => $state['version'], 'before' => $before, 'after' => $state, 'duration' => 6];
        $play['summary'] = ucfirst($play['summary']);
        if ($recordedAnimation !== null) {
            // Interactive recordings already use world coordinates and actual participants.
            $play['animation'] = $recordedAnimation;
            $play['animation']['no_huddle'] = (bool) ($snapBefore['_hold_personnel'] ?? false);
        } else {
            $animationPlay = array_merge($play, ['outcome' => $play['live_outcome'] ?? $play['outcome']]);
            $variety = AnimationVariety::choose($animationPlay);
            if ($variety !== null) {
                $play['animation_variety'] = $animationPlay['animation_variety'] = $variety;
                $state['animation_variety_counts'][$variety['family']] = (int) ($before['animation_variety_counts'][$variety['family']] ?? 0) + 1;
            }

            $play['animation'] = ($play['no_snap'] ?? false) ? app(StoppageTimeline::class)->build($play, $rosters) : (in_array($play['call'], ['punt', 'field_goal', 'kickoff', 'extra_point', 'onside_kick'], true)
                ? app(SpecialTeamsTimeline::class)->build($animationPlay, $rosters) : app(PlayTimeline::class)->build($animationPlay, $rosters));

            $play['animation']['no_huddle'] = (bool) ($snapBefore['_hold_personnel'] ?? false);
            $play['animation'] = FieldOrientation::animation($play['animation'], $before);
        }

        // Keep choices in the saved before-state for replays, never in the next-play state.
        unset($state['_snap_lineup']);
        $personnel = app(GamePersonnel::class)->afterPlay($state, $before, $play, $rosters);
        $state = $personnel['state'];
        $play['personnel_notices'] = $personnel['notices'];
        $state = HurryUp::remember($state, $before, $play, $rosters);
        if (! ($play['no_snap'] ?? false) || $state['quarter'] !== $before['quarter']) {
            unset($state['timeout_used_since_snap']);
        }
        $play['after'] = $state;
        if ($personnel['notices']) {
            $play['summary'] .= ' · '.implode(' · ', $personnel['notices']);
        }

        if ($decisionOptions) {
            $play['penalty_options'] = $decisionOptions;
            $state['penalty_pending'] = true;
            $play['after'] = $state;
            $play['summary'] = 'FLAG: '.ucwords(str_replace('_', ' ', $play['penalty']['type'])).' · awaiting '.$play['penalty']['beneficiary'].' decision';
            $play['animation']['events'][count($play['animation']['events']) - 1][1] = $play['summary'];
        }

        return ['state' => $state, 'play' => $play];
    }
}
