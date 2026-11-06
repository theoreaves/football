<?php

namespace App\Services\Simulation;

/** Coverage reads and breaks are presentation only; INT1 owns the catch and return. */
class InterceptionReactions
{
    public static function apply(array $animation, array $rosters): array
    {
        if (($animation['outcome'] ?? '') !== 'interception' || ! ($animation['passing'] ?? false)
            || ($animation['no_snap'] ?? false) || ($animation['fake_kick'] ?? false)) {
            return $animation;
        }
        $interceptor = null;
        foreach ($animation['players'] as $index => $player) {
            if ($player['team'] === 'defense' && $player['role'] === 'CB1') {
                $interceptor = $index;
                break;
            }
        }
        if ($interceptor === null) {
            return $animation;
        }
        $defender = $animation['players'][$interceptor];
        $start = $defender['path'][0];
        // Match the nearest receiver at the snap, not the eventual pass target.
        $receivers = array_values(array_filter($animation['players'], fn ($p) => $p['team'] === 'offense'
            && preg_match('/^(WR\d*|TE\d*|RB\d*|FB\d*)$/', $p['role'])));
        usort($receivers, function ($a, $b) use ($start) {
            $distance = fn ($p) => hypot($p['path'][0][1] - $start[1], $p['path'][0][3] - $start[3]);

            return ($distance($a) <=> $distance($b)) ?: strcmp($a['role'], $b['role']);
        });
        if (! $receivers) {
            return $animation;
        }
        $assigned = $receivers[0];
        $ratings = $rosters[$defender['side']]['players']['CB1']['ratings'] ?? [];
        $rating = fn ($key) => max(0, min(100, (float) ($ratings[$key] ?? 65)));
        $speed = 7 + .03 * $rating('speed');
        $throw = $animation['throw_at'] ?? 2.2;
        $catch = $animation['catch_at'] ?? 3.8;
        if ($catch <= $throw) {
            return $animation;
        }
        $reaction = $throw + min(.12 + (100 - $rating('awareness')) * .0015, ($catch - $throw) * .45);
        $caught = self::sample($defender['path'], $catch);
        $offsetX = $start[1] - $assigned['path'][0][1];
        $offsetZ = $start[3] - $assigned['path'][0][3];
        $coverage = [$start];
        // Follow the assigned route with a closing cushion. No catch coordinates
        // enter this calculation; changing the ball target cannot alter this read.
        $steps = max(1, (int) ceil($reaction / .1));
        for ($step = 1; $step <= $steps; $step++) {
            $time = $reaction * $step / $steps;
            $receiver = self::sample($assigned['path'], $time);
            $cushion = 1 - .7 * min(1, $time / max(.01, $throw));
            $previous = $coverage[array_key_last($coverage)];
            $x = $receiver[1] + $offsetX * $cushion;
            $z = $receiver[3] + $offsetZ * $cushion;
            $distance = hypot($x - $previous[1], $z - $previous[3]);
            $fraction = $distance > 0 ? min(1, $speed * ($time - $previous[0]) / $distance) : 0;
            $coverage[] = [$time, $previous[1] + ($x - $previous[1]) * $fraction, 0, $previous[3] + ($z - $previous[3]) * $fraction];
        }
        $read = $coverage[array_key_last($coverage)];
        $travel = hypot($caught[1] - $read[1], $caught[3] - $read[3]);
        $factor = max(1, (1.2 * $travel / $speed) / ($catch - $reaction));
        $map = fn ($time) => $time <= $reaction ? $time
            : ($time <= $catch ? $reaction + ($time - $reaction) * $factor : $time + ($catch - $reaction) * ($factor - 1));
        if ($factor > 1) {
            foreach ($animation['players'] as &$player) {
                $player['path'] = self::retime($player['path'], $reaction, $catch, $map);
            }
            unset($player);
            $animation['ball'] = self::retime($animation['ball'], $reaction, $catch, $map);
            foreach (['events', 'ballHolders'] as $key) {
                foreach ($animation[$key] as &$event) {
                    $event[0] = $map($event[0]);
                }
                unset($event);
            }
            foreach (['catch_at', 'contact_at', 'result_at', 'reveal_at', 'duration'] as $key) {
                if (isset($animation[$key])) {
                    $animation[$key] = $map($animation[$key]);
                }
            }
        }
        $catchAt = $map($catch);
        $tail = array_values(array_filter($animation['players'][$interceptor]['path'], fn ($p) => $p[0] > $catchAt + 1e-8));
        $break = [$reaction + ($catchAt - $reaction) * .25,
            $read[1] + ($caught[1] - $read[1]) * .125, 0, $read[3] + ($caught[3] - $read[3]) * .125];
        $caught[0] = $catchAt;
        $animation['players'][$interceptor]['path'] = array_merge($coverage, [$break, $caught], $tail);
        $animation['catch_at'] = $catchAt;
        $animation['interception_react_at'] = $reaction;
        $animation['coverage_role'] = $assigned['role'];
        $animation['interception_reaction_version'] = 'int2';

        return $animation;
    }

    private static function sample(array $path, float $time): array
    {
        for ($i = 1; $i < count($path); $i++) {
            if ($time <= $path[$i][0]) {
                $a = $path[$i - 1];
                $b = $path[$i];
                $fraction = max(0, min(1, ($time - $a[0]) / max(.000001, $b[0] - $a[0])));

                return [$time, $a[1] + ($b[1] - $a[1]) * $fraction, $a[2] + ($b[2] - $a[2]) * $fraction, $a[3] + ($b[3] - $a[3]) * $fraction];
            }
        }
        $last = $path[array_key_last($path)];

        return [$time, $last[1], $last[2], $last[3]];
    }

    private static function retime(array $path, float $reaction, float $catch, callable $map): array
    {
        foreach ([$reaction, $catch] as $cut) {
            if (! in_array($cut, array_column($path, 0)) && $cut > $path[0][0] && $cut < $path[array_key_last($path)][0]) {
                $path[] = self::sample($path, $cut);
                usort($path, fn ($a, $b) => $a[0] <=> $b[0]);
            }
        }

        return array_map(fn ($p) => [$map($p[0]), $p[1], $p[2], $p[3]], $path);
    }
}
