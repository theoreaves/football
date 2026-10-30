<?php

namespace App\Services\Simulation;

/** Kickoff rules for new games; missing profiles retain the former legacy behavior. */
class KickoffRuleProfile
{
    public const PROFILES = ['traditional','nfl_2024','nfl_2025','nfl_2026'];

    public function touchbackSpot(string $profile, bool $touchedLandingZone, int $kickSpot = 35): int
    {
        if ($profile === 'nfl_2026' && $kickSpot === 50) return 20;
        if ($profile === 'nfl_2026' || $profile === 'nfl_2025') return $touchedLandingZone ? 20 : 35;
        return $profile === 'nfl_2024' ? ($touchedLandingZone ? 20 : 30) : 25;
    }
}
