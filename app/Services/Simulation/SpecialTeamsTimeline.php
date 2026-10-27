<?php

namespace App\Services\Simulation;

class SpecialTeamsTimeline
{
    /**
     * Translate ST1b unit positions to the existing 11 animation roles.
     * These roles are renderer/possession anchors, not player positions.
     * This changes identities only; saved play outcomes/paths remain intact.
     */
    private function assignUnitPersonnel(array &$animation, array $rosters, string $side, string $other, string $call): void
    {
        $kickingUnit = match ($call) {
            'punt' => 'punt_coverage',
            'kickoff' => 'kickoff_coverage',
            'field_goal', 'extra_point' => 'field_goal',
            default => null,
        };
        $receivingUnit = match ($call) {
            'punt' => 'punt_return',
            'kickoff' => 'kickoff_return',
            'field_goal', 'extra_point' => 'field_goal_block',
            default => null,
        };
        if ($kickingUnit === null) return;

        // QB renders the punter/kicker in the existing special-teams timeline;
        // CB1 is the returner and RB the holder. Preserve those roles.
        $mapping = [
            'punt_coverage' => ['P'=>'QB','LS'=>'C','PP'=>'RB','G1'=>'LG','G2'=>'RG','T1'=>'LT','T2'=>'RT','WL'=>'TE','WR'=>'WR1','GL'=>'WR2','GR'=>'WR3'],
            'punt_return' => ['PR'=>'CB1','R1'=>'DE1','R2'=>'DE2','R3'=>'DT1','B1'=>'DT2','B2'=>'LB1','B3'=>'LB2','B4'=>'LB3','B5'=>'CB2','B6'=>'S1','B7'=>'S2'],
            'kickoff_coverage' => ['K'=>'QB','C1'=>'C','C2'=>'LG','C3'=>'RG','C4'=>'LT','C5'=>'RT','C6'=>'RB','C7'=>'TE','C8'=>'WR1','C9'=>'WR2','C10'=>'WR3'],
            'kickoff_return' => ['KR'=>'CB1','B1'=>'DE1','B2'=>'DE2','B3'=>'DT1','B4'=>'DT2','B5'=>'LB1','B6'=>'LB2','B7'=>'LB3','B8'=>'CB2','B9'=>'S1','B10'=>'S2'],
            'field_goal' => ['K'=>'QB','LS'=>'C','H'=>'RB','LG'=>'LG','RG'=>'RG','LT'=>'LT','RT'=>'RT','W1'=>'TE','W2'=>'WR1','B1'=>'WR2','B2'=>'WR3'],
            'field_goal_block' => ['R1'=>'DE1','R2'=>'DT1','R3'=>'DT2','R4'=>'DE2','R5'=>'LB1','R6'=>'LB2','R7'=>'LB3','R8'=>'CB1','R9'=>'CB2','R10'=>'S1','R11'=>'S2'],
        ];
        $identityFields = ['id','name','lastname','number','height_inches','weight_pounds','skin_tone','appearance'];
        foreach ([[$side, 'offense', $kickingUnit], [$other, 'defense', $receivingUnit]] as [$teamSide, $trackTeam, $unit]) {
            $assignments = $rosters[$teamSide]['special_teams'][$unit] ?? [];
            $pool = $rosters[$teamSide]['pool'] ?? [];
            if (! $assignments || ! $pool) continue;
            $poolById = [];
            foreach ($pool as $candidate) $poolById[(int) $candidate['id']] = $candidate;
            $trackIndexes = [];
            foreach ($animation['players'] as $index => $track) {
                if ($track['team'] === $trackTeam) $trackIndexes[$track['role']] = $index;
            }
            // Returner and kicking specialists take precedence over blockers.
            foreach ($mapping[$unit] as $slot => $role) {
                $id = (int) ($assignments[$slot] ?? 0);
                if ($id <= 0 || ! isset($poolById[$id], $trackIndexes[$role])) continue;
                $targetIndex = $trackIndexes[$role];
                $sourceIndex = null;
                foreach ($trackIndexes as $existingIndex) {
                    if ((int) ($animation['players'][$existingIndex]['id'] ?? 0) === $id) {
                        $sourceIndex = $existingIndex;
                        break;
                    }
                }
                if ($sourceIndex === $targetIndex) continue;
                $desired = array_intersect_key($poolById[$id], array_flip($identityFields));
                $replaced = array_intersect_key($animation['players'][$targetIndex], array_flip($identityFields));
                if ($sourceIndex !== null) {
                    // Swap actual player identities rather than duplicating an
                    // already present player's jersey on another track.
                    $animation['players'][$sourceIndex] = array_replace($animation['players'][$sourceIndex], $replaced);
                }
                $animation['players'][$targetIndex] = array_replace($animation['players'][$targetIndex], $desired);
            }
        }
    }

    public function build(array $play, array $rosters): array
    {
        if ($play['call'] === 'onside_kick') {
            return app(OnsideKick::class)->animation($play, $rosters);
        }
        $base = app(PlayTimeline::class)->build($play, $rosters);
        $side = $play['before']['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $direction = $side === 'home' ? 1 : -1;
        $absolute = fn ($spot) => $side === 'home' ? 10 + $spot : 110 - $spot;
        $point = fn ($t, $x, $y, $z) => [$t, max(0, min(120, $x)), $y, $z];
        $kickoff = $play['call'] === 'kickoff';
        $kick = $absolute($play['before']['spot']) - ($kickoff ? 0 : 7 * $direction);
        // Older saved touchbacks capped the landing at the goal line.
        $landingSpot = str_ends_with($play['outcome'], '_touchback') ? max(103, $play['landing']) : $play['landing'];
        $landing = $absolute($landingSpot);
        $return = $play['return_yards'];
        $end = $landing - $direction * $return;
        $blocked = str_ends_with($play['outcome'], '_blocked');
        $goalKick = in_array($play['call'], ['field_goal', 'extra_point'], true);
        $missed = str_ends_with($play['outcome'], '_missed');
        $endZ = $missed ? 40 : 26.7;
        foreach ($base['players'] as $i => &$player) {
            $initial = $player['path'][0];
            if ($kickoff) {
                $z = 3 + ($i % 11) * 4.7;
                $x = $player['team'] === 'offense' ? $absolute($play['before']['spot']) - 1 * $direction : $absolute(65 + ($i % 3) * 8);
                $player['path'] = [$point(0, $x, 0, $z), $point(2, $x + $direction * 12, 0, $z), $point(6, $end + ($i % 4) * $direction, 0, $z)];
            }
            if ($player['role'] === 'QB') {
                $person = $rosters[$side]['players'][$play['carrier']];
                $player = array_merge($player, array_intersect_key($person, array_flip(['id', 'name', 'number', 'height_inches', 'weight_pounds', 'skin_tone', 'appearance'])));
                $player['path'] = $goalKick
                    ? [
                        $point(0, $kick - 5 * $direction, 0, 26.7),
                        $point(.45, $kick - 3.5 * $direction, 0, 26.7),
                        $point(.85, $kick - 1.8 * $direction, 0, 26.7),
                        $point(1.2, $kick + .55 * $direction, 0, 26.7),
                        $point(2.0, $kick + 2.5 * $direction, 0, 26.7),
                        $point(6, $kick + 3 * $direction, 0, 26.7),
                    ]
                    : [
                        // A kickoff starts with a run-up to the ball on a tee.
                        // Do not reuse the stationary punt-style kick motion.
                        $point(0, $kick - 5 * $direction, 0, 26.7),
                        $point(.55, $kick - 3.1 * $direction, 0, 26.7),
                        $point(.95, $kick - 1.25 * $direction, 0, 26.7),
                        $point(1.2, $kick, 0, 26.7),
                        $point(1.65, $kick + 1.25 * $direction, 0, 26.7),
                        $point(6, $kick + 3 * $direction, 0, 26.7),
                    ];
            }
            if ($player['role'] === 'CB1' && ! $goalKick) {
                $player['path'] = [$point(0, $landing, 0, 26.7), $point(3.5, $landing, 0, 26.7), $point(5.3, $end, 0, 26.7), $point(6, $end, 0, 26.7)];
            }
            if ($player['role'] === 'TE' && $player['team'] === 'offense' && ! $goalKick) {
                // Keep the kickoff formation position, not the scrimmage
                // starting point in front of the kicker.
                $player['path'] = [$player['path'][0], $point(3.5, $end - 8 * $direction, 0, 27), $point(5.3, $end, 0, 26.7), $point(6, $end, 0, 26.7)];
            }
            if ($player['role'] === 'DE1' && $blocked) {
                $player['path'] = [$initial, $point(1.2, $kick + $direction, 0, 26.7), $point(6, $landing, 0, 26.7)];
            }
        } unset($player);
        // ST1d: the entire punt/kick coverage unit advances in lanes, while
        // receiving-team players form a staggered blocking screen. Existing
        // kicking result, ball flight, possession and clock are unchanged.
        // Keep QB (punter/kicker) and CB1 (returner) on their specialized paths.
        if (! $goalKick && ! $blocked) {
            $touchback = $kickoff && str_ends_with($play['outcome'], '_touchback');
            $coverageRoles = ['C', 'LG', 'RG', 'LT', 'RT', 'RB', 'TE', 'WR1', 'WR2', 'WR3'];
            $blockRoles = ['DE1', 'DE2', 'DT1', 'DT2', 'LB1', 'LB2', 'LB3', 'CB2', 'S1', 'S2'];
            $coverageCount = count($coverageRoles);
            foreach ($base['players'] as &$player) {
                $role = $player['role'];
                if ($touchback && $player['team'] === 'offense' && in_array($role, $coverageRoles, true)) {
                    // The ball is dead. Coverage takes a few controlled steps
                    // out of its kickoff alignment; nobody races to the end zone.
                    $lane = array_search($role, $coverageRoles, true);
                    $z = max(3, min(50, 26.7 + ($lane - 4.5) * 4.35));
                    $x = $kick - 1.1 * $direction;
                    $player['path'] = [
                        $point(0, $x, 0, $z),
                        $point(1.2, $x + 1.2 * $direction, 0, $z),
                        $point(2.4, $x + 3.5 * $direction, 0, $z),
                        $point(3.5, $x + 5 * $direction, 0, $z),
                        $point(6, $x + 5 * $direction, 0, $z),
                    ];
                    continue;
                }
                if ($touchback && $player['team'] === 'defense' && in_array($role, $blockRoles, true)) {
                    // Receiving unit waits near its own return alignment.
                    $lane = array_search($role, $blockRoles, true);
                    $z = max(3, min(50, 26.7 + ($lane - 4.5) * 4.35));
                    $x = $landing - 13 * $direction;
                    $player['path'] = [
                        $point(0, $x, 0, $z),
                        $point(1.2, $x, 0, $z),
                        $point(3.5, $x + 1 * $direction, 0, $z),
                        $point(6, $x + 1 * $direction, 0, $z),
                    ];
                    continue;
                }
                if ($player['team'] === 'offense' && in_array($role, $coverageRoles, true)) {
                    $lane = array_search($role, $coverageRoles, true);
                    $wide = ($lane - ($coverageCount - 1) / 2) * 4.35;
                    $z = max(3, min(50, 26.7 + $wide));
                    $outside = in_array($role, ['WR2', 'WR3'], true);
                    // Punt protectors start near scrimmage, gunners start wide.
                    $startX = $kickoff ? $kick - 1.1 * $direction : $kick + 7 * $direction;
                    $startZ = $z;
                    $breakX = $startX + ($kickoff ? 8 : ($outside ? 12 : 5)) * $direction;
                    // Until variable return timing is implemented, bound
                    // travel from the kickoff/punt starting alignment.
                    $wantedPursue = $landing - ($outside ? 11 : 17) * $direction;
                    $pursueX = $startX + $direction * max(0, min(17, ($wantedPursue - $startX) * $direction));
                    $wantedClose = $landing - ($outside ? 4 : 9) * $direction;
                    $closeX = $startX + $direction * max(0, min(25, ($wantedClose - $startX) * $direction));
                    $closeZ = max(2, min(51, $z + (26.7 - $z) * ($outside ? .32 : .20)));
                    $player['path'] = [
                        $point(0, $startX, 0, $startZ),
                        $point(1.2, $startX + ($outside ? 3 : 0) * $direction, 0, $startZ),
                        $point(2.4, $breakX, 0, $startZ),
                        $point(3.5, $pursueX, 0, $closeZ),
                        $point(5.3, $closeX, 0, $closeZ),
                        $point(6, $closeX, 0, $closeZ),
                    ];
                } elseif ($player['team'] === 'defense' && in_array($role, $blockRoles, true)) {
                    $lane = array_search($role, $blockRoles, true);
                    $z = max(3, min(50, 26.7 + ($lane - 4.5) * 4.35));
                    $startX = $kickoff ? $landing - 13 * $direction : $kick + 10 * $direction;
                    $screenX = $kickoff ? $landing - 8 * $direction : $kick + 14 * $direction;
                    $endX = $kickoff ? $landing - 5 * $direction : $landing - 8 * $direction;
                    $player['path'] = [
                        $point(0, $startX, 0, $z),
                        $point(1.2, $screenX, 0, $z),
                        $point(2.4, $screenX + 2 * $direction, 0, $z),
                        $point(3.5, $endX, 0, $z + (26.7 - $z) * .15),
                        $point(5.3, $endX - 2 * $direction, 0, $z + (26.7 - $z) * .20),
                        $point(6, $endX - 2 * $direction, 0, $z + (26.7 - $z) * .20),
                    ];
                }
            }
            unset($player);
        }
        if ($goalKick) {
            // The QB track is repurposed as the kicker above. Use the RB track for
            // the holder, retaining its own player identity and uniform.
            foreach ($base['players'] as &$player) {
                if ($player['team'] !== 'offense' || $player['role'] !== 'RB') {
                    continue;
                }
                $player['pose'] = 'holder-kneel';
                $player['path'] = [
                    $point(0, $kick - .95 * $direction, 0, 27.65),
                    $point(.7, $kick - .95 * $direction, 0, 27.65),
                    $point(1.2, $kick - .95 * $direction, 0, 27.65),
                    $point(6, $kick - .95 * $direction, 0, 27.65),
                ];
                break;
            }
            unset($player);
        }
        $height = $goalKick && str_ends_with($play['outcome'], '_good') ? 5 : ($goalKick || str_ends_with($play['outcome'], '_touchback') ? .25 : 1);
        $base['ball'] = [$point(0, $kickoff ? $kick : $absolute($play['before']['spot']) - $direction, $kickoff ? .25 : 1, 26.7),
            $point(1.2, $kick, .5, 26.7), $point(2.4, ($kick + $landing) / 2, $blocked ? 1 : 14, (26.7 + $endZ) / 2),
            $point(3.5, $landing, $height, $endZ), $point(5.3, $end, $height, $endZ), $point(6, $end, $height, $endZ)];
        $base['events'] = [[0, $kickoff ? 'Kickoff setup' : 'Snap'], [1.2, 'Kick'], [2.4, $blocked ? 'Kick blocked' : 'Kick in flight'],
            [3.5, $goalKick ? 'Kick reaches the goal' : ($return ? 'Return' : 'Kick lands')], [5.3, $play['summary']]];
        $base['ballHolders'] = $kickoff || $goalKick ? [[0, null, null]] : [[0, 'offense', 'C'], [.01, null, null], [.35, 'offense', 'QB'], [1.2, null, null]];
        if (! $goalKick && ! str_ends_with($play['outcome'], '_touchback')) {
            $base['ballHolders'][] = [3.5, 'defense', 'CB1'];
        }
        // Kick/punt returns reverse the normal offense/defense relationship:
        // the returner is CB1 (defense), while TE is on the coverage team
        // (offense). Enable contact only on an actual, non-scoring return.
        $returnContact = ! $goalKick && ! $blocked
            && ! str_ends_with($play['outcome'], '_touchback')
            && ! str_ends_with($play['outcome'], '_touchdown')
            && $return > 0;
        if ($returnContact) {
            $base['carrier'] = 'CB1';
            $base['tackler_role'] = 'TE';
            $base['contact_carrier_team'] = 'defense';
            $base['contact_tackler_team'] = 'offense';
            $base['tackle_style'] = ['wrap', 'side', 'lunge'][abs((int) $end) % 3];
            $base['contact_at'] = 5.3;
        } else {
            $base['contact_at'] = null;
            $base['tackle_style'] = null;
        }
        $base['firstDown'] = null;

        // ST1c: assign the snapshotted special-teams personnel to actual
        // animation tracks, after the kick/holder/returner paths are established.
        $this->assignUnitPersonnel($base, $rosters, $side, $other, $play['call']);

        return $base;
    }
}
