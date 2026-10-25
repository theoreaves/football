<?php

namespace App\Services\Simulation;

/** Reserve-first selection for kicking and return units, preserving specialists. */
class SpecialTeamsPersonnel
{
    public function apply(array $roster, array $state, string $side, ?string $call): array
    {
        if (! in_array($call, ['punt', 'kickoff', 'field_goal', 'extra_point', 'onside_kick'], true)
            || ! isset($roster['pool'], $roster['players'])) {
            return $roster;
        }
        $kicking = $side === ($state['possession'] ?? null);
        $fieldGoal = in_array($call, ['field_goal', 'extra_point'], true);
        // Field-goal protection retains starting OL. Return specialists remain in CB1.
        $roles = $fieldGoal ? ($kicking ? ['TE', 'WR3'] : ['LB1', 'LB2', 'CB2', 'S2'])
            : ['WR3', 'WR2', 'TE', 'RB', 'LB1', 'LB3', 'CB2', 'S2'];
        $used = array_fill_keys(array_column($roster['players'], 'id'), true);
        $assigned = $state['game_lineup'][$side] ?? [];
        $snap = (int) ($state['personnel_snaps'] ?? 0);
        foreach ($roles as $role) {
            if (! isset($roster['players'][$role]) || isset($assigned[$role])) continue;
            $positions = RosterBuilder::GROUPS[$role] ?? [];
            $positions = array_unique(array_merge($positions, ['LB', 'OLB', 'ILB', 'MLB', 'CB', 'DB', 'S', 'FS', 'SS', 'WR', 'TE', 'RB', 'FB']));
            $available = array_values(array_filter($roster['pool'], function ($p) use ($positions, $used, $state, $side, $snap) {
                $id = $p['id'] ?? null;
                $injury = $state['injuries'][$side][$id] ?? null;
                $depth = (int) (preg_replace('/\D/', '', $p['depth'] ?? '') ?: 99);
                return $id !== null && ! isset($used[$id])
                    && in_array($p['position'] ?? '', $positions, true) && $depth >= 2
                    && (! $injury || ($injury['return_snap'] !== null && $snap >= $injury['return_snap']))
                    && ($state['fatigue'][$side][$id] ?? 0) < 65;
            }));
            usort($available, function ($a, $b) use ($role) {
                $fit = RosterBuilder::GROUPS[$role] ?? [];
                $rank = fn ($p) => [in_array($p['position'], $fit, true) ? 0 : 1,
                    (int) (preg_replace('/\D/', '', $p['depth'] ?? '') ?: 99), $p['id']];
                return $rank($a) <=> $rank($b);
            });
            if (! ($candidate = $available[0] ?? null)) continue;
            $fatigue = $state['fatigue'][$side][$candidate['id']] ?? 0;
            foreach ($candidate['ratings'] as $field => &$rating) {
                if (! in_array($field, ['stamina', 'durability'], true))
                    $rating = max(1, (int) round($rating * (1 - min(.25, $fatigue / 400))));
            }
            unset($rating);
            $candidate['fatigue'] = $fatigue;
            $roster['players'][$role] = $candidate;
            $used[$candidate['id']] = true;
        }
        return $roster;
    }
}
