<?php

namespace App\Support;

class PlayAnnouncement
{
    public static function titles(array $play): array
    {
        // A flag can erase the apparent result, so don't announce a provisional score.
        if (! empty($play['penalty'])) {
            return ['FLAG!'];
        }
        $outcome = $play['outcome'] ?? '';
        $summary = strtolower($play['summary'] ?? '');
        $titles = [];
        if (str_contains($outcome, 'touchdown') || str_contains($summary, 'touchdown')) {
            $titles[] = 'TOUCHDOWN!';
        }
        if (in_array($outcome, ['interception', 'fumble'], true) || str_contains($summary, 'turnover on downs')) {
            $titles[] = 'TURNOVER!';
        }
        if ($outcome === 'field_goal_good') {
            $titles[] = 'FIELD GOAL!';
        } elseif (in_array($outcome, ['field_goal_missed', 'field_goal_blocked'], true)) {
            $titles[] = 'MISSED!';
        }
        if ($outcome === 'safety' || str_contains($summary, 'safety')) {
            $titles[] = 'SAFETY!';
        }

        return $titles ?: ['Play result'];
    }
}
