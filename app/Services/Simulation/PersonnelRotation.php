<?php

namespace App\Services\Simulation;

class PersonnelRotation
{
    public const PACKAGES = [
        'singleback' => ['QB', 'RB', 'WR1', 'WR2', 'TE', 'TE2'],
        'i_form' => ['QB', 'RB', 'FB', 'WR1', 'WR2', 'TE'],
        'goal_line' => ['QB', 'RB', 'FB', 'WR1', 'TE', 'TE2'],
        'empty' => ['QB', 'WR1', 'WR2', 'WR3', 'WR4', 'TE'],
    ];

    public const BASE = ['QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE'];

    public const LINE = ['C', 'LG', 'RG', 'LT', 'RT'];

    public function apply(array $roster, array $state, string $side, ?string $call, ?string $formation, array $preferences): array
    {
        $special = in_array($call, ['punt', 'field_goal', 'kickoff', 'onside_kick', 'extra_point', ...SpecialTeamsTricks::FAKES], true);
        $skills = $special ? self::BASE : (self::PACKAGES[$formation ?? 'shotgun'] ?? self::BASE);
        // Older/minimal rosters can continue with a legal eleven-player unit.
        if (array_diff($skills, array_keys($roster['players']))) {
            $skills = self::BASE;
        }
        $active = array_merge($skills, self::LINE);
        $roster['offensive_roles'] = $active;
        if ($special || ($state['_personnel_simulation'] ?? false) || ($state['phase'] ?? '') !== 'scrimmage' || ($state['possession'] ?? null) !== $side || ! isset($roster['pool'])) {
            return $roster;
        }
        $count = (int) ($state['personnel_usage'][$side]['offensive_snaps'] ?? $state['stats'][$side]['plays'] ?? 0);
        $eligible = function ($player) use ($state, $side, $preferences) {
            $hurt = $state['injuries'][$side][$player['id']] ?? null;

            return (! $hurt || ($hurt['return_snap'] !== null && ($state['personnel_snaps'] ?? 0) >= $hurt['return_snap']))
                && ! in_array($player['id'], $preferences, true);
        };
        $rank = fn ($p) => [(int) (preg_replace('/\D/', '', $p['depth'] ?? '') ?: 99), $p['id']];
        $candidates = function ($positions, $role) use (&$roster, $active, $eligible, $rank) {
            $otherIds = array_column(array_intersect_key($roster['players'], array_flip(array_diff($active, [$role]))), 'id');
            $people = array_values(array_filter($roster['pool'], fn ($p) => in_array($p['position'], $positions, true) && $eligible($p) && ! in_array($p['id'], $otherIds, true)));
            usort($people, fn ($a, $b) => $rank($a) <=> $rank($b));

            return $people;
        };
        if (in_array('RB', $active, true) && ! isset($preferences['RB'])) {
            $backs = $candidates(['RB'], 'RB');
            if (count($backs) >= 2) {
                $workload = max(0, min(100, (int) ($state['coaching'][$side]['rb_workload'] ?? 50)));
                $primary = (int) round(10 + $workload * .06);
                $third = count($backs) >= 3 ? 1 : 0;
                $secondary = 20 - $primary - $third;
                $sequence = [];
                while ($primary || $secondary) {
                    $n = min(4, $primary);
                    array_push($sequence, ...array_fill(0, $n, 0));
                    $primary -= $n;
                    $n = min(2, $secondary);
                    array_push($sequence, ...array_fill(0, $n, 1));
                    $secondary -= $n;
                }
                if ($third) {
                    $sequence[] = 2;
                }
                $backCount = (int) ($state['personnel_usage'][$side]['rb_snaps'] ?? $count);
                $person = $backs[$sequence[$backCount % 20]];
                if (($state['fatigue'][$side][$person['id']] ?? 0) < 65) {
                    $this->assign($roster, 'RB', $person, $state, $side);
                }
            }
        }
        // Brief two-snap breathers, staggered so starting receivers do not
        // all leave together. Package changes account for most WR3/TE2 usage.
        foreach (['WR1' => [20, 8], 'WR2' => [12, 4], 'TE' => [10, 6]] as $role => [$period, $start]) {
            if (! isset($roster['players'][$role]) || isset($preferences[$role]) || ! in_array($count % $period, [$start, $start + 1], true)) {
                continue;
            }
            $people = $candidates($role === 'TE' ? ['TE'] : ['WR'], $role);
            $starter = $roster['players'][$role]['id'];
            // Rest uses depth-four WRs / depth-two-or-later TEs, not a starter
            // reassigned from another receiving position.
            $backup = collect($people)->first(fn ($p) => $p['id'] !== $starter
                && (int) (preg_replace('/\D/', '', $p['depth'] ?? '') ?: 99) >= ($role === 'TE' ? 2 : 4)
                && ($state['fatigue'][$side][$p['id']] ?? 0) < 45);
            if ($backup) {
                $this->assign($roster, $role, $backup, $state, $side);
            }
        }

        return $roster;
    }

    private function assign(array &$roster, string $role, array $person, array $state, string $side): void
    {
        $old = $roster['players'][$role];
        foreach ($roster['players'] as $other => $selected) {
            if ($other !== $role && $selected['id'] === $person['id']) {
                $roster['players'][$other] = $old;
                break;
            }
        }
        $fatigue = $state['fatigue'][$side][$person['id']] ?? 0;
        foreach ($person['ratings'] as $field => &$rating) {
            if (! in_array($field, ['stamina', 'durability'], true)) {
                $rating = max(1, (int) round($rating * (1 - min(.25, $fatigue / 400))));
            }
        }
        unset($rating);
        $person['fatigue'] = $fatigue;
        $roster['players'][$role] = $person;
    }
}
