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
        // ST1e.3: a compact five-man front, three-player protection shield,
        // and two outside gunners. Return defense splits into four rushers,
        // two gunner vices, and four staggered return-wall blockers.
        // These are animation-only assignments; result, personnel and stats
        // remain authoritative in the engine.
        if ($play['call'] === 'punt' && ! $blocked) {
            $snapX = $absolute($play['before']['spot']);
            // Z lane, depth behind LOS, post-kick yards/sec, release delay.
            // Snapper/guards/tackles provide a tight initial front.
            $coverage = [
                'C' => [26.7, 0.0, 3.5, .12],
                'LG' => [24.5, -.35, 3.3, .19],
                'RG' => [28.9, -.35, 3.3, .23],
                'LT' => [22.3, -.55, 3.7, .26],
                'RT' => [31.1, -.55, 3.7, .30],
                // Three-person shield behind the front, including PP.
                'RB' => [26.7, -5.1, 4.0, .23],
                'TE' => [22.5, -4.1, 4.5, .18],
                'WR1' => [30.9, -4.1, 4.5, .20],
                // Both gunners can release when the ball is snapped.
                'WR2' => [5.8, .2, 7.2, 0],
                'WR3' => [47.5, .2, 7.2, 0],
            ];
            // Receiving team: four pressure players near the line, a vice
            // beside each gunner, and four blockers at staggered depths.
            $rush = [
                'DE1' => [22.0, 1.3, 'rush'],
                'DT1' => [25.1, 1.0, 'rush'],
                'DT2' => [28.3, 1.0, 'rush'],
                'DE2' => [31.4, 1.3, 'rush'],
                'LB1' => [18.5, 5.0, 'wall'],
                'LB2' => [25.0, 7.5, 'wall'],
                'LB3' => [32.0, 9.5, 'wall'],
                'CB2' => [38.0, 12.0, 'wall'],
                'S1' => [6.4, .9, 'vice'],
                'S2' => [46.9, .9, 'vice'],
            ];
            foreach ($base['players'] as &$player) {
                $role = $player['role'];
                if ($player['team'] === 'offense' && $role === 'QB') {
                    // The punter remains the last line of defense.
                    $player['path'] = [
                        $point(0, $snapX - 14 * $direction, 0, 26.7),
                        $point(.35, $snapX - 13 * $direction, 0, 26.7),
                        $point(1.2, $snapX - 7 * $direction, 0, 26.7),
                        $point(2.4, $snapX - 6.5 * $direction, 0, 26.7),
                        $point(6, $snapX - 6 * $direction, 0, 26.7),
                    ];
                } elseif ($player['team'] === 'offense' && isset($coverage[$role])) {
                    [$z, $back, $speed, $delay] = $coverage[$role];
                    $gunner = in_array($role, ['WR2', 'WR3'], true);
                    $x = $snapX + $back * $direction;
                    // All protectors release just after the 1.2-second kick;
                    // gunners have already gained a short head start.
                    $early = $gunner ? 1.55 : 0;
                    $travel = static fn (float $t): float => $early
                        + max(0, $t - 1.2 - $delay) * $speed;
                    $drift = $gunner ? .09 : .035;
                    $player['path'] = [
                        $point(0, $x, 0, $z),
                        $point(1.2, $x + $early * $direction, 0, $z),
                        $point(2.4, $x + $travel(2.4) * $direction, 0, $z),
                        $point(3.5, $x + $travel(3.5) * $direction, 0, $z),
                        $point(5.3, $x + $travel(5.3) * $direction, 0,
                            $z + (26.7 - $z) * $drift),
                        $point(6, $x + $travel(6) * $direction, 0,
                            $z + (26.7 - $z) * ($drift + .015)),
                    ];
                } elseif ($player['team'] === 'defense' && isset($rush[$role])) {
                    [$z, $depth, $assignment] = $rush[$role];
                    $x = $snapX + $depth * $direction;
                    if ($assignment === 'rush') {
                        // Rushers first challenge the protection, then turn
                        // to pursue or begin setting the inside return wall.
                        $swing = $role === 'DE1' || $role === 'DT1' ? -1 : 1;
                        $player['path'] = [
                            $point(0, $x, 0, $z),
                            $point(1.2, $x - .65 * $direction, 0, $z + $swing * .25),
                            $point(2.4, $x - 1.2 * $direction, 0, $z + $swing * .45),
                            $point(3.5, $x + .15 * $direction, 0, $z + $swing * .7),
                            $point(5.3, $x + 2.4 * $direction, 0, $z + $swing * .9),
                            $point(6, $x + 3.4 * $direction, 0, $z + $swing * .9),
                        ];
                    } elseif ($assignment === 'vice') {
                        // Double/hold-up responsibility against the gunners;
                        // no instantaneous dash toward the returner.
                        $player['path'] = [
                            $point(0, $x, 0, $z),
                            $point(1.2, $x - .45 * $direction, 0, $z),
                            $point(2.4, $x - .5 * $direction, 0, $z),
                            $point(3.5, $x + 1.3 * $direction, 0, $z),
                            $point(5.3, $x + 4.4 * $direction, 0, $z),
                            $point(6, $x + 5.1 * $direction, 0, $z),
                        ];
                    } else {
                        // Return-wall players are deliberately off the line.
                        // They reposition across staggered levels as coverage
                        // approaches instead of joining the original rush.
                        $sign = $z < 26.7 ? 1 : -1;
                        $player['path'] = [
                            $point(0, $x, 0, $z),
                            $point(1.2, $x, 0, $z),
                            $point(2.4, $x + .3 * $direction, 0, $z + $sign * .3),
                            $point(3.5, $x + .8 * $direction, 0, $z + $sign * .7),
                            $point(5.3, $x + 1.2 * $direction, 0, $z + $sign * 1.1),
                            $point(6, $x + 1.5 * $direction, 0, $z + $sign * 1.3),
                        ];
                    }
                }
            }
            unset($player);
        }
        // ST1d.2: NFL dynamic kickoff formation. The kicker remains at the
        // kickoff spot; ten coverage players line up at receiving team's 40,
        // and all ten receiving blockers occupy the setup zone (35 to 30).
        // Six are on the receiving 35, four behind. The returner (CB1) is
        // the only receiving player allowed to track the ball before landing.
        // These field landmarks are independent of kick length or penalties.
        if ($kickoff && ! $blocked) {
            $coverageRoles = ['C', 'LG', 'RG', 'LT', 'RT', 'RB', 'TE', 'WR1', 'WR2', 'WR3'];
            $blockRoles = ['DE1', 'DE2', 'DT1', 'DT2', 'LB1', 'LB2', 'LB3', 'CB2', 'S1', 'S2'];
            $coverageLine = $absolute(60); // Receiving team's 40-yard line.
            $setupLine = $absolute(65);    // Receiving team's 35-yard line.
            $backLine = $absolute(69);     // Inside the 35-to-30 setup zone.
            $dead = str_ends_with($play['outcome'], '_touchback');
            foreach ($base['players'] as &$player) {
                $role = $player['role'];
                if ($player['team'] === 'offense' && in_array($role, $coverageRoles, true)) {
                    $index = array_search($role, $coverageRoles, true);
                    $z = 4.2 + $index * 5.0;
                    $after = $dead ? $coverageLine : $coverageLine + $direction * (3 + ($index % 3));
                    $player['path'] = [
                        $point(0, $coverageLine, 0, $z),
                        $point(1.2, $coverageLine, 0, $z),
                        $point(2.4, $coverageLine, 0, $z),
                        $point(3.5, $coverageLine, 0, $z),
                        $point(5.3, $after, 0, $z + (26.7 - $z) * ($dead ? 0 : .07)),
                        $point(6, $after, 0, $z + (26.7 - $z) * ($dead ? 0 : .07)),
                    ];
                } elseif ($player['team'] === 'defense' && in_array($role, $blockRoles, true)) {
                    $index = array_search($role, $blockRoles, true);
                    // Six on the restraining line; four staggered behind it.
                    $front = $index < 6;
                    $z = $front ? 4.4 + $index * 9 : 9 + ($index - 6) * 11.5;
                    $x = $front ? $setupLine : $backLine;
                    $after = $dead ? $x : $x - $direction * (1.5 + $index % 3);
                    $player['path'] = [
                        $point(0, $x, 0, $z),
                        $point(1.2, $x, 0, $z),
                        $point(2.4, $x, 0, $z),
                        $point(3.5, $x, 0, $z),
                        $point(5.3, $after, 0, $z),
                        $point(6, $after, 0, $z),
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

        // Extend the punt after the kick while preserving all authored
        // possession events: a long punt must not complete in six seconds.
        if ($play['call'] === 'punt' && ! $blocked) {
            // Longer, bounded flight gives staggered coverage time to develop.
            // Hang time is from the 1.2-second kick, not the snap.
            $hangSeconds = 4.55 + min(.55, max(0, ($play['gain'] - 35) * .03));
            $catchAt = 1.2 + $hangSeconds;
            $returnAt = $catchAt + ($return > 0 ? max(1.5, $return / 7.0) : .18);
            $finishAt = $returnAt + .65;
            $remap = static function (float $t) use ($catchAt, $returnAt, $finishAt): float {
                if ($t < 1.2) return $t;
                if ($t <= 3.5) return 1.2 + ($t - 1.2) / 2.3 * ($catchAt - 1.2);
                if ($t <= 5.3) return $catchAt + ($t - 3.5) / 1.8 * ($returnAt - $catchAt);
                return $returnAt + ($t - 5.3) / .7 * ($finishAt - $returnAt);
            };
            foreach ($base['players'] as &$track) {
                foreach ($track['path'] as &$waypoint) $waypoint[0] = $remap((float) $waypoint[0]);
                unset($waypoint);
            }
            unset($track);
            foreach (['ball', 'events', 'ballHolders'] as $field) {
                foreach ($base[$field] as &$waypoint) $waypoint[0] = $remap((float) $waypoint[0]);
                unset($waypoint);
            }
            // ST1e.4: the coverage players can still be many yards from a
            // punt returner at the final spot. Never pull the designated TE
            // across the field for a manufactured tackle animation.
            // This changes presentation only; engine outcome is authoritative.
            if ($base['contact_at'] !== null) {
                $tackler = null;
                $returner = null;
                foreach ($base['players'] as $track) {
                    if ($track['team'] === 'offense' && $track['role'] === 'TE') {
                        $tackler = $track;
                    } elseif ($track['team'] === 'defense' && $track['role'] === 'CB1') {
                        $returner = $track;
                    }
                }
                $at = static function (?array $track, float $time): ?array {
                    if ($track === null) return null;
                    $last = null;
                    foreach ($track['path'] as $p) {
                        if ($p[0] >= $time) {
                            if ($last === null || $p[0] <= $last[0]) return $p;
                            $fraction = max(0, min(1, ($time - $last[0]) / ($p[0] - $last[0])));
                            return [
                                $time,
                                $last[1] + ($p[1] - $last[1]) * $fraction,
                                0,
                                $last[3] + ($p[3] - $last[3]) * $fraction,
                            ];
                        }
                        $last = $p;
                    }
                    return $last;
                };
                $a = $at($tackler, $returnAt);
                $b = $at($returner, $returnAt);
                if ($a === null || $b === null || hypot($a[1] - $b[1], $a[3] - $b[3]) > 4.5) {
                    $base['contact_at'] = null;
                    $base['tackle_style'] = null;
                } else {
                    $base['contact_at'] = $returnAt;
                }
            }
            $base['duration'] = $finishAt;
            $base['result_at'] = $returnAt;
            $base['reveal_at'] = $returnAt;
        }

        // ST1c: assign the snapshotted special-teams personnel to actual
        // animation tracks, after the kick/holder/returner paths are established.
        $this->assignUnitPersonnel($base, $rosters, $side, $other, $play['call']);

        return $base;
    }
}
