<?php

namespace App\Services\Simulation;

/** Visual time only: never reads or modifies the game clock or consumes RNG. */
class RushingAnimationTiming
{
    public static function apply(array $animation, array $ratings, array $play): array
    {
        if (! in_array($play['call'], ['inside_run', 'outside_run', 'draw', 'two_point_run'], true)
            || $play['carrier'] !== 'RB' || ($play['defensive_return'] ?? false)
            || ($play['no_snap'] ?? false)) {
            return $animation;
        }

        $runner = null;
        foreach ($animation['players'] as $player) {
            if ($player['team'] === 'offense' && $player['role'] === 'RB') {
                $runner = $player['path'];
                break;
            }
        }
        if ($runner === null) {
            return $animation;
        }

        // Coordinates are yards; speeds are yards/second. Keep the existing
        // one-second snap/exchange. Draws accelerate more cautiously initially.
        $rating = fn ($name) => max(0, min(100, (float) ($ratings[$name] ?? 65)));
        $topSpeed = 7.0 + $rating('speed') * .03;
        $acceleration = 3.5 + $rating('acceleration') * .03;
        $initialSpeed = $play['call'] === 'draw' ? 2.0 : 3.0;
        $rampTime = ($topSpeed - $initialSpeed) / $acceleration;
        $rampDistance = $initialSpeed * $rampTime + .5 * $acceleration * $rampTime ** 2;
        $travelTime = static fn ($distance) => $distance <= $rampDistance
            ? (sqrt($initialSpeed ** 2 + 2 * $acceleration * $distance) - $initialSpeed) / $acceleration
            : $rampTime + ($distance - $rampDistance) / $topSpeed;

        // Use actual clamped, curved carrier geometry, including end-zone
        // follow-through and lateral travel, rather than recorded gain alone.
        $legs = [];
        $distance = 0.0;
        for ($i = 1; $i < count($runner); $i++) {
            $start = $runner[$i - 1];
            $end = $runner[$i];
            if ($start[0] < 1 || $end[0] > 5.3) {
                continue;
            }
            $length = hypot($end[1] - $start[1], $end[3] - $start[3]);
            // A stationary leg still needs strictly increasing timestamps.
            $length = max(.01, $length);
            $legs[] = [$start[0], $end[0], $distance, $length];
            $distance += $length;
        }
        if ($legs === []) {
            return $animation;
        }
        $resultAt = 1 + $travelTime($distance);
        $mapTime = static function (int|float $time) use ($legs, $travelTime, $resultAt): int|float {
            if ($time <= 1) {
                return $time;
            }
            if ($time >= 5.3) {
                // Preserve the .7-second contact/fall window at real-time speed.
                return $resultAt + ($time - 5.3);
            }
            foreach ($legs as [$start, $end, $distance, $length]) {
                if ($time <= $end) {
                    return 1 + $travelTime($distance + $length * ($time - $start) / ($end - $start));
                }
            }

            return $resultAt;
        };

        // Dense samples preserve the acceleration curve with the existing
        // linear replay sampler. Apply the same time map to every participant
        // and the ball so cuts, pursuit and possession changes remain aligned.
        $retimePath = static function (array $path) use ($mapTime): array {
            $result = [$path[0]];
            for ($i = 1; $i < count($path); $i++) {
                $start = $path[$i - 1];
                $end = $path[$i];
                $steps = max(1, (int) ceil(($end[0] - $start[0]) / .05));
                for ($step = 1; $step <= $steps; $step++) {
                    $fraction = $step / $steps;
                    $time = $start[0] + ($end[0] - $start[0]) * $fraction;
                    // Keep exact recorded endpoints, including integer
                    // coordinates, instead of recalculating them as floats.
                    $result[] = $step === $steps
                        ? [$mapTime($end[0]), $end[1], $end[2], $end[3]]
                        : [$mapTime($time),
                            $start[1] + ($end[1] - $start[1]) * $fraction,
                            $start[2] + ($end[2] - $start[2]) * $fraction,
                            $start[3] + ($end[3] - $start[3]) * $fraction];
                }
            }
            // Collapse stationary and constant-speed sections. Acceleration
            // samples and changes of direction stay in the saved replay, but
            // long straight runs do not inflate it with redundant frames.
            $compact = [];
            foreach ($result as $frame) {
                $compact[] = $frame;
                while (count($compact) >= 3) {
                    $n = count($compact);
                    [$a, $b, $c] = array_slice($compact, -3);
                    $fraction = ($b[0] - $a[0]) / ($c[0] - $a[0]);
                    $error = 0.0;
                    for ($axis = 1; $axis <= 3; $axis++) {
                        $error = max($error, abs($b[$axis] - ($a[$axis] + ($c[$axis] - $a[$axis]) * $fraction)));
                    }
                    if ($error > 1e-9) {
                        break;
                    }
                    array_splice($compact, $n - 2, 1);
                }
            }

            return $compact;
        };
        foreach ($animation['players'] as &$player) {
            $player['path'] = $retimePath($player['path']);
        }
        unset($player);
        $animation['ball'] = $retimePath($animation['ball']);
        foreach (['events', 'ballHolders'] as $key) {
            foreach ($animation[$key] as &$entry) {
                $entry[0] = $mapTime($entry[0]);
            }
            unset($entry);
        }
        if ($animation['contact_at'] !== null) {
            $animation['contact_at'] = $resultAt;
        }
        $animation['duration'] = $resultAt + .7;
        $animation['reveal_at'] = $resultAt;
        $animation['result_at'] = $resultAt;
        $animation['timing_version'] = 'rushing-v1';

        return $animation;
    }
}
