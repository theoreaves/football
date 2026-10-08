<?php

namespace App\Services\Simulation;

class FieldOrientation
{
    public static function direction(array $state): int
    {
        return ($state['possession'] === 'home' ? 1 : -1) * ((int) $state['quarter'] % 2 === 0 ? -1 : 1);
    }

    public static function line(array $state): float
    {
        return self::direction($state) === 1 ? 10 + $state['spot'] : 110 - $state['spot'];
    }

    public static function animation(array $animation, array $before): array
    {
        $animation['direction'] = self::direction($before);
        if ((int) $before['quarter'] % 2 === 0) {
            foreach ($animation['players'] as &$player) {
                foreach ($player['path'] as &$point) {
                    $point[1] = 120 - $point[1];
                }
                unset($point);
            }
            unset($player);
            foreach ($animation['ball'] as &$point) {
                $point[1] = 120 - $point[1];
            }
            unset($point);
            foreach (['line', 'firstDown'] as $key) {
                if (isset($animation[$key])) {
                    $animation[$key] = 120 - $animation[$key];
                }
            }
        }

        return $animation;
    }
}
