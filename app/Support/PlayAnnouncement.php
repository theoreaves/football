<?php

namespace App\Support;

class PlayAnnouncement
{
    public static function titles(array $play): array
    {
        // Penalties can overturn a provisional score or turnover.
        if (! empty($play['penalty'])) {
            return ['PENALTY — '.(isset($play['penalty']['accepted'])
                ? ($play['penalty']['accepted'] ? 'ACCEPTED' : 'DECLINED')
                : 'DECISION PENDING')];
        }

        $outcome = (string) ($play['outcome'] ?? '');
        $call = (string) ($play['call'] ?? '');
        $summary = strtolower((string) ($play['summary'] ?? ''));
        $gain = (int) ($play['gain'] ?? 0);
        $yardage = $gain.' '.(abs($gain) === 1 ? 'Yard' : 'Yards');
        $pass = in_array($call, ['short_pass', 'medium_pass', 'deep_pass', 'screen_pass', 'two_point_pass'], true)
            || str_contains($call, 'pass');

        if (str_contains($outcome, 'touchdown') || str_contains($summary, 'touchdown')) {
            return ['TOUCHDOWN!'];
        }
        if ($outcome === 'safety' || str_contains($summary, 'safety')) {
            return ['SAFETY!'];
        }
        if ($outcome === 'interception') {
            return ['INTERCEPTION!'];
        }
        if ($outcome === 'fumble') {
            return ['FUMBLE!'];
        }
        if (str_contains($summary, 'turnover on downs')) {
            return ['TURNOVER ON DOWNS'];
        }
        if ($outcome === 'field_goal_good') {
            return ['FIELD GOAL GOOD!'];
        }
        if ($outcome === 'field_goal_blocked') {
            return ['FIELD GOAL BLOCKED!'];
        }
        if ($outcome === 'field_goal_missed') {
            return ['FIELD GOAL MISSED'];
        }
        if ($outcome === 'extra_point_good') {
            return ['EXTRA POINT GOOD'];
        }
        if ($outcome === 'extra_point_missed') {
            return ['EXTRA POINT MISSED'];
        }
        if ($outcome === 'extra_point_blocked') {
            return ['EXTRA POINT BLOCKED'];
        }
        if ($outcome === 'sack') {
            return ['SACK: '.$yardage];
        }
        if ($outcome === 'incomplete') {
            return ['PASS INCOMPLETE'];
        }
        if ($pass && ! empty($play['throwaway'])) {
            return ['PASS INCOMPLETE'];
        }
        if ($pass && ! in_array($outcome, ['punt', 'kickoff'], true)) {
            return ['PASS COMPLETE: '.$yardage];
        }
        if (in_array($call, ['punt', 'kickoff'], true)) {
            return [strtoupper(str_replace('_', ' ', $call))];
        }
        if ($outcome === 'spike') {
            return ['SPIKE — CLOCK STOPPED'];
        }
        if ($outcome === 'kneel') {
            return ['QB KNEEL'];
        }
        if ($call === 'two_point_run' || $call === 'two_point_pass') {
            return ['TWO-POINT TRY'];
        }
        if (isset($play['gain'])) {
            return ['RUN: '.$yardage];
        }

        return ['PLAY RESULT'];
    }
}
