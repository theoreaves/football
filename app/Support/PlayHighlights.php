<?php

namespace App\Support;

class PlayHighlights
{
    public static function reasons(array $play): array
    {
        if (($play['outcome'] ?? '') === 'penalty' || ($play['no_snap'] ?? false) || ($play['after']['penalty_pending'] ?? false)) {
            return [];
        }
        $before = $play['before'] ?? [];
        $after = $play['after'] ?? [];
        $reasons = [];
        $yards = in_array($play['call'] ?? '', ['punt', 'kickoff'], true) ? ($play['return_yards'] ?? 0) : ($play['gain'] ?? 0);
        if ($yards > 20) {
            $reasons[] = '20+ yards';
        }
        foreach (['home', 'away'] as $side) {
            if (($after[$side.'_score'] ?? 0) > ($before[$side.'_score'] ?? 0)) {
                $reasons[] = 'Score';
            }
            if (($after['stats'][$side]['turnovers'] ?? 0) > ($before['stats'][$side]['turnovers'] ?? 0)) {
                $reasons[] = 'Turnover';
            }
        }

        if (($before['possession'] ?? null) !== ($after['possession'] ?? null) && str_contains(strtolower($play['summary'] ?? ''), 'turnover on downs')) {
            $reasons[] = 'Turnover';
        }

        return array_values(array_unique($reasons));
    }

    public static function saved(array $play): bool
    {
        return ($play['saved_highlight'] ?? false) || self::reasons($play) !== [];
    }
}
