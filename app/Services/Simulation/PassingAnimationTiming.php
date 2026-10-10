<?php

namespace App\Services\Simulation;

/** Retimes presentation only, using immutable routes and ratings. */
class PassingAnimationTiming
{
    public static function apply(array $animation, array $ratings, array $play): array
    {
        if (! $animation['passing'] || ! $animation['receiver_role']
            || ($play['throwaway'] ?? false) || ($play['defensive_return'] ?? false)
            || ($play['no_snap'] ?? false)) {
            return $animation;
        }
        $receiver = null;
        foreach ($animation['players'] as $player) {
            if ($player['team'] === 'offense' && $player['role'] === $animation['receiver_role']) {
                $receiver = $player['path'];
                break;
            }
        }
        if ($receiver === null) {
            return $animation;
        }
        // Give the receiver the same snap interval as the QB. The legacy
        // first segment could otherwise move a deep route many yards in .6s.
        $ready = $receiver[0];
        $ready[0] = .6;
        array_splice($receiver, 1, 0, [$ready]);
        foreach ($animation['players'] as &$player) {
            if ($player['team'] === 'offense' && $player['role'] === $animation['receiver_role']) {
                $player['path'] = $receiver;
            }
        }
        unset($player);
        $rating = fn ($name) => max(0, min(100, (float) ($ratings[$name] ?? 65)));
        $speed = 7 + .03 * $rating('speed');
        $acceleration = 3.5 + .03 * $rating('acceleration');
        $travel = static function (float $distance, float $initial) use ($speed, $acceleration): float {
            $ramp = ($speed - $initial) / $acceleration;
            $rampDistance = $initial * $ramp + .5 * $acceleration * $ramp ** 2;
            return $distance <= $rampDistance
                ? (sqrt($initial ** 2 + 2 * $acceleration * $distance) - $initial) / $acceleration
                : $ramp + ($distance - $rampDistance) / $speed;
        };
        $distanceAt = static function (float $time) use ($receiver): float {
            $distance = 0.0;
            for ($i = 1; $i < count($receiver); $i++) {
                $a = $receiver[$i - 1]; $b = $receiver[$i];
                if ($time <= $a[0]) {
                    break;
                }
                $fraction = min(1, ($time - $a[0]) / ($b[0] - $a[0]));
                $distance += max(.01, hypot($b[1] - $a[1], $b[3] - $a[3])) * $fraction;
            }
            return $distance;
        };
        $snapDistance = $distanceAt(.6);
        $routeTime = static fn (float $time) => $travel(max(0, $distanceAt($time) - $snapDistance), 2.0);
        $rawCatch = $routeTime(3.8);

        // The fixed legacy route may be long or very short. Never exceed the
        // receiver's speed cap; slow it when needed for a normal QB setup or
        // sufficient ball flight time. Flight uses the actual 3D ball arc.
        $flightDistance = 0.0;
        $ball = $animation['ball'];
        for ($i = 1; $i < count($ball); $i++) {
            $a = $ball[$i - 1]; $b = $ball[$i];
            if ($a[0] >= 2.2 && $b[0] <= 3.8) {
                $flightDistance += sqrt(($b[1] - $a[1]) ** 2 + ($b[2] - $a[2]) ** 2 + ($b[3] - $a[3]) ** 2);
            }
        }
        $flightTime = max(.35, $flightDistance / 24.0);
        $scale = max(1.0, (1.6 + $flightTime) / max(.001, $rawCatch));
        $catchAt = .6 + $rawCatch * $scale;
        $throwAt = $catchAt - $flightTime;
        $finishedAtCatch = in_array($play['outcome'], ['incomplete', 'interception'], true);
        $yacDistance = $distanceAt(5.3) - $distanceAt(3.8);
        $yacInitial = min($speed, 2 + $acceleration * $rawCatch) / $scale;
        $yacTime = $finishedAtCatch ? .45 : max(.25, $travel($yacDistance, $yacInitial));
        $resultAt = $catchAt + $yacTime;
        $mapTime = static function (float $time) use ($routeTime, $scale, $catchAt, $travel, $distanceAt, $finishedAtCatch, $yacTime, $yacInitial, $resultAt): float {
            if ($time <= .6) {
                return $time;
            }
            if ($time <= 3.8) {
                return .6 + $routeTime($time) * $scale;
            }
            if ($time >= 5.3) {
                return $resultAt + ($time - 5.3);
            }
            if ($finishedAtCatch) {
                return $catchAt + ($time - 3.8) / 1.5 * $yacTime;
            }
            $distance = $distanceAt($time) - $distanceAt(3.8);
            $total = $distanceAt(5.3) - $distanceAt(3.8);
            $physicalTime = $travel($total, $yacInitial);
            return $catchAt + $travel($distance, $yacInitial) * $yacTime / max(.000001, $physicalTime);
        };
        // Flight has its own clock: a long route delays the release instead
        // of making the football float slowly until the receiver arrives.
        $ballTime = static function (float $time) use ($throwAt, $catchAt, $mapTime): float {
            if ($time <= .6 || $time >= 3.8) {
                return $mapTime($time);
            }
            if ($time <= 2.2) {
                return .6 + ($time - .6) / 1.6 * ($throwAt - .6);
            }
            return $throwAt + ($time - 2.2) / 1.6 * ($catchAt - $throwAt);
        };
        foreach ($animation['players'] as &$player) {
            $player['path'] = self::retimePath($player['path'],
                $player['team'] === 'offense' && $player['role'] === 'QB' ? $ballTime : $mapTime);
        }
        unset($player);
        $animation['ball'] = self::retimePath($animation['ball'], $ballTime);
        foreach (['events', 'ballHolders'] as $key) {
            foreach ($animation[$key] as &$entry) {
                $entry[0] = $ballTime($entry[0]);
            }
            unset($entry);
        }
        if ($animation['contact_at'] !== null) {
            $animation['contact_at'] = $resultAt;
        }
        $animation['throw_at'] = $throwAt;
        $animation['catch_at'] = $catchAt;
        $animation['result_at'] = $resultAt;
        $animation['reveal_at'] = $resultAt;
        $animation['duration'] = $resultAt + .7;
        $animation['timing_version'] = 'passing-v1';
        return $animation;
    }

    private static function retimePath(array $path, callable $mapTime): array
    {
        $result = [$path[0]];
        for ($i = 1; $i < count($path); $i++) {
            $a = $path[$i - 1]; $b = $path[$i];
            // Split at timing phase boundaries even when a participant's
            // original track does not contain that timestamp.
            $cuts = [$a[0]];
            foreach ([.6, 2.2, 3.8, 5.3] as $cut) {
                if ($cut > $a[0] && $cut < $b[0]) {
                    $cuts[] = $cut;
                }
            }
            $cuts[] = $b[0];
            for ($j = 1; $j < count($cuts); $j++) {
                $steps = max(1, (int) ceil(($cuts[$j] - $cuts[$j - 1]) / .04));
                for ($step = 1; $step <= $steps; $step++) {
                    $time = $cuts[$j - 1] + ($cuts[$j] - $cuts[$j - 1]) * $step / $steps;
                    $fraction = ($time - $a[0]) / ($b[0] - $a[0]);
                    $result[] = [$mapTime($time), $a[1] + ($b[1] - $a[1]) * $fraction,
                        $a[2] + ($b[2] - $a[2]) * $fraction, $a[3] + ($b[3] - $a[3]) * $fraction];
                    while (count($result) >= 3) {
                        $n = count($result);
                        [$x, $y, $z] = array_slice($result, -3);
                        $f = ($y[0] - $x[0]) / ($z[0] - $x[0]);
                        $error = 0.0;
                        for ($axis = 1; $axis <= 3; $axis++) {
                            $error = max($error, abs($y[$axis] - ($x[$axis] + ($z[$axis] - $x[$axis]) * $f)));
                        }
                        if ($error > 1e-9) {
                            break;
                        }
                        array_splice($result, $n - 2, 1);
                    }
                }
            }
        }
        return $result;
    }
}
