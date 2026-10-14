<?php

namespace App\Services\Simulation;

class GamePersonnel
{
    public function active(array $rosters, array $state, ?string $call = null, ?string $formation = null): array
    {
        foreach ($rosters as $side => &$roster) {
            if (! isset($roster['pool'])) {
                $roster = app(PersonnelRotation::class)->apply($roster, $state, $side, $call, $formation, $state['game_lineup'][$side] ?? []);

                continue;
            }
            $preferences = $state['game_lineup'][$side] ?? [];
            $specialistRole = null;
            $specialist = null;
            if ($side === ($state['possession'] ?? null) && ($state['phase'] ?? 'scrimmage') === 'scrimmage'
                && ! in_array($call, ['punt', 'field_goal', 'extra_point', 'kickoff', 'onside_kick', ...SpecialTeamsTricks::FAKES], true)
                && (($state['down'] ?? 1) === 3 || ($state['distance'] ?? 10) <= 2 || (($state['down'] ?? 1) === 4 && ! empty($roster['specialists']['fourth_down_back'])))
                && ! isset($preferences['RB'])) {
                $specialistRole = 'RB';
                $specialist = (($state['down'] ?? 1) === 4 ? ($roster['specialists']['fourth_down_back'] ?? null) : null) ?: ($roster['specialists']['third_down_back'] ?? null);
            } elseif ($side !== ($state['possession'] ?? null) && in_array($call, ['punt', 'kickoff'], true)) {
                $specialistRole = 'CB1';
                $specialist = $roster['specialists'][$call === 'punt' ? 'punt_returner' : 'kick_returner'] ?? null;
            }
            $specialist = $specialist ? (int) $specialist : null;
            $candidate = $specialist ? collect($roster['pool'])->firstWhere('id', $specialist) : null;
            $hurt = $specialist ? ($state['injuries'][$side][$specialist] ?? null) : null;
            $fourthDownSpecialist = $specialistRole === 'RB' && ($state['down'] ?? 1) === 4 && $specialist && $specialist === (int) ($roster['specialists']['fourth_down_back'] ?? 0);
            $positions = $fourthDownSpecialist ? array_unique(array_merge(...array_values(RosterBuilder::GROUPS))) : ($specialistRole === 'RB' ? ['RB', 'FB'] : ['RB', 'FB', 'WR', 'TE', 'CB', 'DB', 'S', 'FS', 'SS']);
            if ($specialist && $candidate && in_array($candidate['position'], $positions, true)
                && (! $hurt || ($hurt['return_snap'] !== null && ($state['personnel_snaps'] ?? 0) >= $hurt['return_snap']))
                && ($state['fatigue'][$side][$specialist] ?? 0) < 65
                && ! in_array($specialist, array_diff_key($preferences, [$specialistRole => true]), true)) {
                $preferences[$specialistRole] = (int) $specialist;
            } else {
                $specialistRole = null;
            }
            $used = [];
            $selected = [];
            $snap = $state['personnel_snaps'] ?? 0;
            foreach (RosterBuilder::GROUPS as $role => $positions) {
                if ($role === 'RB' && $specialistRole === 'RB' && $fourthDownSpecialist) {
                    $positions = array_unique(array_merge(...array_values(RosterBuilder::GROUPS)));
                }
                if ($role === 'CB1' && $specialistRole === 'CB1') {
                    $positions = array_merge($positions, ['RB', 'FB', 'WR', 'TE', 'S', 'FS', 'SS']);
                }
                $eligible = array_filter($roster['pool'], function ($player) use ($used, $state, $side, $snap, $preferences, $role) {
                    $injury = $state['injuries'][$side][$player['id']] ?? null;

                    return ! in_array($player['id'], $used, true) && ! in_array($player['id'], array_diff_key($preferences, [$role => true]), true) && (! $injury || ($injury['return_snap'] !== null && $snap >= $injury['return_snap']));
                });
                $rank = fn ($player) => [($preferences[$role] ?? null) === $player['id'] ? 0 : 1, array_search($player['position'], $positions, true), (int) (preg_replace('/\D/', '', $player['depth'] ?? '') ?: 99), $player['id']];
                $candidates = array_values(array_filter($eligible, fn ($player) => in_array($player['position'], $positions, true)));
                usort($candidates, fn ($a, $b) => $rank($a) <=> $rank($b));
                $player = $candidates[0] ?? null;
                if ($player && ($state['fatigue'][$side][$player['id']] ?? 0) >= 65) {
                    foreach ($candidates as $backup) {
                        if (($state['fatigue'][$side][$backup['id']] ?? 0) < 45) {
                            $player = $backup;
                            break;
                        }
                    }
                }
                if (! $player && in_array($role, ['LB4', 'LB5', 'CB3', 'CB4', 'TE2', 'FB', 'WR4'], true)) {
                    continue;
                }
                if (! $player) {
                    $player = array_values($eligible)[0] ?? null;
                    if ($player) {
                        $player['emergency'] = true;
                        foreach ($player['ratings'] as $field => &$rating) {
                            if (! in_array($field, ['stamina', 'durability'], true)) {
                                $rating = min(35, $rating);
                            }
                        }
                        unset($rating);
                    } else {
                        $player = ['id' => -1 - array_search($role, array_keys(RosterBuilder::GROUPS), true), 'name' => 'Emergency '.$role, 'number' => null, 'position' => $positions[0], 'depth' => $role, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 25), 'emergency' => true];
                    }
                }
                $used[] = $player['id'];
                $fatigue = $state['fatigue'][$side][$player['id']] ?? 0;
                foreach ($player['ratings'] as $field => &$rating) {
                    if (! in_array($field, ['stamina', 'durability'], true)) {
                        $rating = max(1, (int) round($rating * (1 - min(0.25, $fatigue / 400))));
                    }
                }
                unset($rating);
                $player['fatigue'] = $fatigue;
                $selected[$role] = $player;
            }
            $roster['players'] = $selected;
            $roster = app(PersonnelRotation::class)->apply($roster, $state, $side, $call, $formation, $preferences);
        }
        unset($roster);

        return $rosters;
    }

    public function afterPlay(array $state, array $before, array $play, array $rosters): array
    {
        if (($play['no_snap'] ?? false) && ($before['quarter'] ?? 1) !== ($state['quarter'] ?? 1)) {
            foreach ($state['fatigue'] ?? [] as $side => $players) {
                foreach ($players as $id => $fatigue) {
                    $state['fatigue'][$side][$id] = max(0, $fatigue - ($before['quarter'] === 2 ? 60 : 20));
                }
            }
        }
        if (($play['no_snap'] ?? false) || ($state['_personnel_simulation'] ?? false)) {
            return ['state' => $state, 'notices' => []];
        }
        $snap = ($before['personnel_snaps'] ?? 0) + 1;
        $state['personnel_snaps'] = $snap;
        if (($before['phase'] ?? 'scrimmage') === 'scrimmage' && ! ($play['conversion'] ?? false)
            && ! in_array($play['call'], ['punt', 'field_goal', 'kickoff', 'onside_kick', 'extra_point'], true)) {
            $offense = $before['possession'];
            $state['personnel_usage'][$offense]['offensive_snaps'] = (int) ($before['personnel_usage'][$offense]['offensive_snaps'] ?? $before['stats'][$offense]['plays'] ?? 0) + 1;
            $usedBack = ! ($play['fake_kick'] ?? false) && collect($play['animation']['players'] ?? [])->contains(fn ($p) => $p['side'] === $offense && $p['role'] === 'RB');
            $state['personnel_usage'][$offense]['rb_snaps'] = (int) ($before['personnel_usage'][$offense]['rb_snaps'] ?? $before['stats'][$offense]['plays'] ?? 0) + (int) $usedBack;
        }
        $notices = [];
        $participants = $play['animation']['players'] ?? [];
        foreach ($rosters as $side => $roster) {
            $ids = array_column(array_filter($participants, fn ($track) => $track['side'] === $side), 'id');
            foreach ($roster['pool'] ?? $roster['players'] as $player) {
                $id = $player['id'];
                $fatigue = $before['fatigue'][$side][$id] ?? 0;
                $stamina = $player['ratings']['stamina'] ?? 65;
                $onField = in_array($id, $ids, true);
                $change = $onField ? max(1, (100 - $stamina) / 12) : -max(3, $stamina / 12);
                $state['fatigue'][$side][$id] = max(0, min(100, $fatigue + $change));
                $injury = $before['injuries'][$side][$id] ?? null;
                if ($injury && $injury['return_snap'] !== null && $snap >= $injury['return_snap']) {
                    unset($state['injuries'][$side][$id]);
                    $notices[] = ucfirst($side).' · '.$player['name'].' is cleared to return';
                }
            }
        }
        if (($before['quarter'] ?? 1) !== ($state['quarter'] ?? 1)) {
            foreach ($state['fatigue'] ?? [] as $side => $players) {
                foreach ($players as $id => $fatigue) {
                    $state['fatigue'][$side][$id] = max(0, $fatigue - ($before['quarter'] === 2 ? 60 : 20));
                }
            }
        }
        if (! ($state['rules']['injuries'] ?? true) || in_array($play['call'], ['kneel', 'spike'], true)) {
            return ['state' => $state, 'notices' => $notices];
        }
        foreach ($participants as $track) {
            $side = $track['side'];
            $id = $track['id'];
            if ($id < 0 || ! isset($rosters[$side]['pool']) || isset($before['injuries'][$side][$id])) {
                continue;
            }
            $person = $rosters[$side]['players'][$track['role']] ?? null;
            if (! $person || $person['id'] !== $id) {
                $person = collect($rosters[$side]['players'])->firstWhere('id', $id);
            }
            $durability = $person['ratings']['durability'] ?? 65;
            $fatigue = $before['fatigue'][$side][$id] ?? 0;
            $risk = (0.00035 + (100 - $durability) * 0.00002) * (1 + $fatigue / 100);
            $roll = ((int) sprintf('%u', crc32($before['seed'].':'.$before['version'].':injury:'.$side.':'.$id)) % 1000000) / 1000000;
            if ($roll >= $risk) {
                continue;
            }
            $severity = (int) sprintf('%u', crc32($before['seed'].':'.$before['version'].':severity:'.$id));
            $returnSnap = $severity % 100 < 70 ? $snap + 3 + $severity % 6 : null;
            $type = $returnSnap === null ? 'Leg injury' : 'Shaken up';
            $state['injuries'][$side][$id] = ['name' => $track['name'], 'type' => $type, 'return_snap' => $returnSnap, 'occurred_snap' => $snap];
            $roles = array_keys(array_filter($rosters[$side]['players'], fn ($player) => $player['id'] === $id));
            $replacement = $this->active($rosters, $state)[$side]['players'][$roles[0] ?? $track['role']] ?? null;
            $notices[] = ucfirst($side).' · '.$track['name'].' · '.$type.' · '.($returnSnap === null ? 'out for the game' : 'out for '.($returnSnap - $snap).' snaps').($replacement ? ' · '.$replacement['name'].' comes in' : '');
        }

        return ['state' => $state, 'notices' => $notices];
    }
}
