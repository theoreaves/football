<?php

namespace App\Services\Arcade;

use App\Services\Simulation\FieldOrientation;

/** Continuous physical coordinates: x in [0,120], y in [0,53.333...]. */
final class FieldPosition
{
    public static function physical(float $spot, array $state): float
    {
        return FieldOrientation::direction($state) === 1 ? 10 + $spot : 110 - $spot;
    }

    public static function official(float $x, array $state): float
    {
        return FieldOrientation::direction($state) === 1 ? $x - 10 : 110 - $x;
    }

    public static function boundary(float $x, array $state): ?string
    {
        $spot = self::official($x, $state);

        return $spot >= 100 ? 'touchdown' : ($spot <= 0 ? 'safety' : null);
    }
}
