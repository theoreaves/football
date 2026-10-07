<?php

namespace App\Services\Simulation;

class ProTeamAppearance
{
    public function defaults(array $entry): array
    {
        [, , $abbr, , , $primary, $secondary, $accent, $helmet, $pants] = $entry;
        $stadiums = [
            'BFB' => 'grand_bowl', 'MIP' => 'sideline_canopy', 'NEM' => 'horseshoe', 'NYP' => 'classic_oval',
            'BAC' => 'steep_bowl', 'CIW' => 'open_corners', 'CLC' => 'grand_bowl', 'PII' => 'horseshoe',
            'HOW' => 'retractable_roof', 'INS' => 'retractable_roof', 'JAP' => 'sideline_canopy', 'TEC' => 'skyline',
            'DEM' => 'steep_bowl', 'KCW' => 'classic_oval', 'LVM' => 'indoor_dome', 'LAL' => 'twin_roof',
            'DAR' => 'retractable_roof', 'NYG' => 'grand_bowl', 'PHO' => 'open_corners', 'WAG' => 'classic_oval',
            'CHG' => 'skyline', 'DEP' => 'indoor_dome', 'GBB' => 'classic_oval', 'MIN' => 'indoor_dome',
            'ATP' => 'indoor_dome', 'CAC' => 'open_corners', 'NOA' => 'indoor_dome', 'TBP' => 'horseshoe',
            'ARF' => 'retractable_roof', 'LAB' => 'twin_roof', 'SFP' => 'sideline_canopy', 'SES' => 'steep_bowl',
        ];
        $contrast = fn ($base, $choices) => collect($choices)->first(fn ($color) => strtolower($color) !== strtolower($base)) ?? '#ffffff';
        $settings = [
            'stadium_style' => $stadiums[$abbr] ?? 'classic_oval',
            'stadium_seat_color' => $primary,
            'stadium_wall_color' => $secondary,
            'stadium_roof_color' => $accent,
        ];
        foreach (['home', 'away'] as $venue) {
            $settings["uniform_{$venue}_name_enabled"] = true;
            $settings["uniform_{$venue}_name_color"] = $venue === 'home' ? '#ffffff' : $primary;
            foreach (['helmet', 'shoulder', 'pants'] as $part) {
                $settings["uniform_{$venue}_{$part}_stripe_enabled"] = true;
            }
            $settings["uniform_{$venue}_helmet_stripe"] = $contrast($helmet, [$secondary, $primary, $accent]);
            $settings["uniform_{$venue}_shoulder_stripe"] = $contrast($venue === 'home' ? $primary : '#ffffff', [$accent, $secondary, $primary]);
            $settings["uniform_{$venue}_pants_stripe"] = $contrast($pants, [$primary, $secondary, $accent]);
        }

        return $settings;
    }
}
