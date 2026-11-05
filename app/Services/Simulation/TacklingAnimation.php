<?php

namespace App\Services\Simulation;

/** Visual pursuit only. Never writes results, stats, possession or simulation RNG. */
class TacklingAnimation
{
    public static function apply(array $animation, array $rosters): array
    {
        if (($animation['contact_at'] ?? null) === null || ! ($animation['tackle_style'] ?? null)
            || ! in_array($animation['outcome'] ?? '', ['tackle', 'sack'], true)
            || ($animation['out_of_bounds'] ?? false) || ($animation['no_snap'] ?? false)
            || isset($animation['contact_carrier_team']) || ($animation['fake_kick'] ?? false)) {
            return $animation;
        }
        $contact = $animation['contact_at'];
        $reaction = min($contact - .05, $animation['passing'] ? ($animation['catch_at'] ?? 3.8) : .6);
        $carrier = collect($animation['players'])->first(fn ($p) => $p['team'] === 'offense' && $p['role'] === $animation['carrier']);
        if (! $carrier || $reaction < 0) {
            return $animation;
        }
        $finish = self::sample($carrier['path'], $contact);
        $candidates = [];
        foreach ($animation['players'] as $index => &$player) {
            if ($player['team'] !== 'defense') {
                continue;
            }
            $rating = $rosters[$player['side']]['players'][$player['role']]['ratings']['speed'] ?? 65;
            $speed = 5.8 + max(0, min(100, $rating)) * .025;
            // Repair the old tackler exemption too, before assessing who can arrive.
            $player['path'] = self::limit($player['path'], $speed);
            $start = self::sample($player['path'], $reaction);
            $distance = hypot($finish[1] - $start[1], $finish[3] - $start[3]);
            $candidates[] = ['index' => $index, 'role' => $player['role'], 'start' => $start, 'speed' => $speed,
                'distance' => $distance, 'travel' => max(.3, 1.2 * max(0, $distance - .65) / $speed)];
        }
        unset($player);
        if (! $candidates) {
            return $animation;
        }
        usort($candidates, fn ($a, $b) => ($a['travel'] <=> $b['travel']) ?: strcmp($a['role'], $b['role']));
        $primary = $candidates[0];
        // Stretch only the post-catch/run presentation when no defender can arrive
        // in time. Ball and carrier keep every spatial point and possession event.
        $factor = max(1, $primary['travel'] / max(.05, $contact - $reaction));
        $map = fn ($t) => $t <= $reaction ? $t : ($t <= $contact ? $reaction + ($t - $reaction) * $factor : $t + ($contact - $reaction) * ($factor - 1));
        if ($factor > 1) {
            foreach ($animation['players'] as &$player) {
                $player['path'] = self::retime($player['path'], $reaction, $contact, $map);
            }
            unset($player);
            $animation['ball'] = self::retime($animation['ball'], $reaction, $contact, $map);
            foreach (['ballHolders', 'events'] as $key) {
                foreach ($animation[$key] as &$event) {
                    $event[0] = $map($event[0]);
                }
                unset($event);
            }
            foreach (['contact_at', 'result_at', 'reveal_at', 'duration'] as $key) {
                if (isset($animation[$key])) {
                    $animation[$key] = $map($animation[$key]);
                }
            }
        }
        $contact = $animation['contact_at'];
        $duration = $animation['duration'];
        $animation['tackler_role'] = $primary['role'];
        $animation['tackle_assists'] = [];
        foreach ($candidates as $rank => $candidate) {
            if ($rank > 2) {
                break;
            }
            $index = $candidate['index'];
            $start = $candidate['start'];
            $offset = $rank === 0 ? .65 : 1.3 + $rank * .35;
            $fraction = max(0, $candidate['distance'] - $offset) / max(.001, $candidate['distance']);
            $target = [$contact, $start[1] + ($finish[1] - $start[1]) * $fraction, 0, $start[3] + ($finish[3] - $start[3]) * $fraction];
            $arrival = $contact + ($rank === 0 ? 0 : .12 + ($rank - 1) * .22);
            $needed = max(.3, 1.2 * hypot($target[1] - $start[1], $target[3] - $start[3]) / $candidate['speed']);
            if ($rank > 0 && ($arrival > $duration || $needed > $arrival - $reaction)) {
                continue;
            }
            $prefix = array_values(array_filter($animation['players'][$index]['path'], fn ($p) => $p[0] < $reaction));
            $target[0] = $arrival;
            $approach = [$reaction + ($arrival - $reaction) * .25,
                $start[1] + ($target[1] - $start[1]) * .125, 0, $start[3] + ($target[3] - $start[3]) * .125];
            $animation['players'][$index]['path'] = array_merge($prefix, [$start, $approach, $target, [$duration, $target[1], 0, $target[3]]]);
            if ($rank > 0) {
                $animation['tackle_assists'][] = ['role' => $candidate['role'], 'at' => $arrival];
            }
        }
        $animation['pursuit_version'] = 'tack1';

        return $animation;
    }

    private static function sample(array $path, float $time): array
    {
        for ($i = 1; $i < count($path); $i++) {
            if ($time <= $path[$i][0]) {
                $a = $path[$i - 1];
                $b = $path[$i];
                $f = max(0, min(1, ($time - $a[0]) / max(.000001, $b[0] - $a[0])));

                return [$time, $a[1] + ($b[1] - $a[1]) * $f, $a[2] + ($b[2] - $a[2]) * $f, $a[3] + ($b[3] - $a[3]) * $f];
            }
        }
        $last = $path[array_key_last($path)];

        return [$time, $last[1], $last[2], $last[3]];
    }

    private static function limit(array $path, float $speed): array
    {
        for ($i = 1; $i < count($path); $i++) {
            $a = $path[$i - 1];
            $b = $path[$i];
            $distance = hypot($b[1] - $a[1], $b[3] - $a[3]);
            $allow = $speed * max(0, $b[0] - $a[0]);
            if ($distance > $allow && $distance > 0) {
                $path[$i][1] = $a[1] + ($b[1] - $a[1]) * $allow / $distance;
                $path[$i][3] = $a[3] + ($b[3] - $a[3]) * $allow / $distance;
            }
        }

        return $path;
    }

    private static function retime(array $path, float $reaction, float $contact, callable $map): array
    {
        foreach ([$reaction, $contact] as $cut) {
            if (! in_array($cut, array_column($path, 0)) && $cut > $path[0][0] && $cut < $path[array_key_last($path)][0]) {
                $path[] = self::sample($path, $cut);
                usort($path, fn ($a, $b) => $a[0] <=> $b[0]);
            }
        }

        return array_map(fn ($p) => [$map($p[0]), $p[1], $p[2], $p[3]], $path);
    }
}
