<?php

namespace App\Services\Simulation;

class GamePersonnel
{
    public function active(array $rosters, array $state): array
    {
        foreach ($rosters as $side => &$roster) {
            if (! isset($roster['pool'])) {
                continue;
            }
            $preferences = $state['game_lineup'][$side] ?? [];
            $used = [];
            $selected = [];
            $snap = $state['personnel_snaps'] ?? 0;
            foreach (RosterBuilder::GROUPS as $role => $positions) {
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
                if (! $player && in_array($role, ['LB4', 'LB5', 'CB3', 'CB4'], true)) {
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
