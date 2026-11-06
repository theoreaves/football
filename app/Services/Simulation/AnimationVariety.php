<?php

namespace App\Services\Simulation;

/** Saved presentation choices; no simulation RNG or football results are changed. */
class AnimationVariety
{
    public static function choose(array $play): ?array
    {
        if (($play['no_snap'] ?? false) || ($play['defensive_return'] ?? false) || ($play['throwaway'] ?? false)
            || ($play['fake_kick'] ?? false) || ($play['out_of_bounds'] ?? false)) {
            return null;
        }
        $call = $play['call'] ?? '';
        $outcome = $play['outcome'] ?? '';
        $run = in_array($call, ['inside_run', 'outside_run', 'draw', 'two_point_run'], true);
        $pass = in_array($call, ['short_pass', 'medium_pass', 'deep_pass', 'slant', 'two_point_pass'], true)
            && ($play['carrier'] ?? '') !== 'QB';
        $family = $outcome === 'tackle' ? ($run ? 'run' : ($pass ? 'catch' : null))
            : ($pass && $outcome === 'incomplete' ? 'incomplete' : null);
        if ($family === null) {
            return null;
        }
        $saved = $play['animation_variety'] ?? null;
        if (($saved['version'] ?? null) === 1 && ($saved['family'] ?? null) === $family
            && is_int($saved['id'] ?? null) && $saved['id'] >= 0 && $saved['id'] < 100) {
            $id = $saved['id'];
        } else {
            $before = $play['before'] ?? [];
            $count = max(0, (int) ($before['animation_variety_counts'][$family] ?? 0));
            $offset = (int) sprintf('%u', crc32('variety-v1:'.($before['seed'] ?? 0).':'.$family)) % 100;
            // 37 is coprime to 100: every slot appears once before cycling.
            $id = ($offset + ($count % 100) * 37) % 100;
        }

        return ['version' => 1, 'family' => $family, 'id' => $id,
            'movement' => $id % 5, 'handling' => intdiv($id, 5) % 5, 'finish' => intdiv($id, 25)];
    }

    public static function apply(array $animation, array $play): array
    {
        $profile = self::choose($play);
        if ($profile === null) {
            return $animation;
        }
        $animation['variety'] = $profile;
        $role = $profile['family'] === 'run' ? $animation['carrier'] : $animation['receiver_role'];
        $offset = static function (float $time) use ($profile): float {
            if ($profile['family'] === 'run') {
                $u = ($time - 1) / 4.3;
            } elseif ($time <= 3.8) {
                $u = ($time - .6) / 3.2;
            } elseif ($profile['family'] === 'catch') {
                $u = ($time - 3.8) / 1.5;
            } else {
                return 0;
            }
            if ($u <= 0 || $u >= 1) {
                return 0;
            }

            return match ($profile['movement']) {
                0 => .45 * sin(M_PI * $u),
                1 => -.75 * sin(M_PI * $u),
                2 => .9 * sin(2 * M_PI * $u),
                3 => .55 * sin(3 * M_PI * $u),
                4 => -1.05 * sin(M_PI * $u) + .25 * sin(3 * M_PI * $u),
            };
        };
        foreach ($animation['players'] as &$player) {
            if ($player['team'] === 'offense' && $player['role'] === $role) {
                $player['path'] = self::bend($player['path'], $offset, $profile['family'] === 'run');
            }
        }
        unset($player);
        if ($profile['family'] !== 'incomplete') {
            // Preserve the snap/flight, then keep the ball on the carrier's lane.
            $ballOffset = fn ($t) => $t < ($profile['family'] === 'run' ? 1 : 3.8) ? 0 : $offset($t);
            $animation['ball'] = self::bend($animation['ball'], $ballOffset);
        }
        if ($animation['tackle_style'] !== null) {
            $animation['tackle_style'] = ['wrap', 'side', 'lunge', 'drive'][$profile['finish']];
        }

        return $animation;
    }

    private static function bend(array $path, callable $offset, bool $addSnap = true): array
    {
        $times = array_column($path, 0);
        $end = $path[array_key_last($path)][0];
        foreach ($addSnap ? [.6, 1, 2.2, 3.8, 5.3] : [1, 2.2, 3.8, 5.3] as $time) {
            if ($time > $times[0] && $time < $end) {
                $times[] = $time;
            }
        }
        for ($time = 1.2; $time < 5.3; $time += .25) {
            if ($time < $path[array_key_last($path)][0]) {
                $times[] = $time;
            }
        }
        sort($times, SORT_NUMERIC);
        $result = [];
        foreach (array_unique($times, SORT_REGULAR) as $time) {
            for ($i = 1; $i < count($path); $i++) {
                if ($time <= $path[$i][0] + 1e-9) {
                    $a = $path[$i - 1];
                    $b = $path[$i];
                    $f = max(0, min(1, ($time - $a[0]) / max(.000001, $b[0] - $a[0])));
                    $shift = $offset($time);
                    $z = $a[3] + ($b[3] - $a[3]) * $f;
                    $result[] = [$time, $a[1] + ($b[1] - $a[1]) * $f,
                        $a[2] + ($b[2] - $a[2]) * $f, $shift == 0 ? $z : max(1, min(52.33, $z + $shift))];
                    break;
                }
            }
        }

        return $result;
    }
}
