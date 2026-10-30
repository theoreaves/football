<?php

namespace App\Services\Simulation;

class HurryUp
{
    public static function available(array $state): bool
    {
        return isset($state['on_field']) && ($state['phase'] ?? '') === 'scrimmage'
            && ($state['status'] ?? '') === 'playing' && ! ($state['penalty_pending'] ?? false);
    }

    public static function prepare(array $state, string $tempo, string $call, string &$offense, string &$defense): array
    {
        unset($state['_hold_personnel']);
        if ($tempo !== 'hurry' || ! self::available($state) || in_array($call, ['punt', 'punt_distance', 'punt_hang', 'punt_pooch', 'punt_coffin', 'field_goal', ...SpecialTeamsTricks::FAKES], true)) {
            $state['no_huddle'] = false;

            return $state;
        }
        $allowed = OffensiveOptions::FORMATIONS[$state['on_field']['offense_formation']] ?? [];
        if (! in_array($call, [...$allowed, 'kneel', 'spike'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['call' => 'Hurry-up uses plays from the formation already on the field.']);
        }
        $offense = $state['on_field']['offense_formation'];
        $defense = $state['on_field']['defense_formation'];
        $state['_snap_lineup'] = $state['on_field']['players'];
        $state['_hold_personnel'] = true;

        return $state;
    }

    public static function remember(array $state, array $before, array $play, array $rosters): array
    {
        unset($state['_hold_personnel'], $state['on_field'], $state['no_huddle']);
        if (($play['no_snap'] ?? false) || ($play['two_minute_warning'] ?? false) || isset($play['penalty']) || ! empty($play['personnel_notices'])
            || $state['status'] !== 'playing' || $state['phase'] !== 'scrimmage'
            || $state['possession'] !== $before['possession'] || $state['quarter'] !== $before['quarter']
            || ! isset($play['offense_formation'], $play['defense_formation'])) {
            return $state;
        }
        $state['on_field'] = ['offense_formation' => $play['offense_formation'], 'defense_formation' => $play['defense_formation'], 'players' => []];
        foreach ($rosters as $side => $roster) {
            $state['on_field']['players'][$side] = array_map(fn ($p) => $p['id'], $roster['players']);
        }
        $state['no_huddle'] = ($play['tempo'] ?? 'normal') === 'hurry';

        return $state;
    }
}
