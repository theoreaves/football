<?php

namespace App\Services\Simulation;

use App\Models\Team;

class NflPresentationDefaults
{
    public function apply(Team $team, bool $replaceCustom = false): void
    {
        $defaults = config('nfl-presentation.'.$team->abbr);
        if (! $defaults || ($team->team_settings['nfl_import']['source'] ?? '') !== 'ESPN') {
            return;
        }
        $settings = $team->team_settings ?? [];
        if ($replaceCustom || empty($settings['stadium']['name'])) {
            $settings['stadium']['name'] = $defaults['stadium'];
        }
        $mascot = $defaults['mascot'] + ['shirt' => $team->team_color1 ?: '#174880'];
        $settings['mascot'] = $replaceCustom ? $mascot : array_replace($mascot, $settings['mascot'] ?? []);
        $settings['nfl_import']['presentation_version'] = 1;
        $team->team_settings = $settings;
        if ($team->isDirty()) {
            $team->save();
        }
    }

    public function setup($teams): ?array
    {
        if ($teams->count() !== 32 || $teams->contains(fn ($team) => ($team->team_settings['nfl_import']['source'] ?? '') !== 'ESPN')
            || $teams->pluck('abbr')->unique()->count() !== 32
            || $teams->contains(fn ($team) => ! config('nfl-presentation.'.$team->abbr))) {
            return null;
        }
        $groups = [];
        $assignments = [];
        foreach (['A' => 'AFC', 'B' => 'NFC'] as $key => $conference) {
            foreach (['East', 'North', 'South', 'West'] as $i => $division) {
                $group = $key.($i + 1);
                $members = $teams->filter(fn ($team) => $team->conference === $conference && $team->division === $division);
                if ($members->count() !== 4) {
                    return null;
                }
                $groups[$group] = ['conference' => $conference, 'division' => $division, 'size' => 4];
                foreach ($members as $team) {
                    $assignments[$team->id] = $group;
                }
            }
        }

        return ['groups' => $groups, 'assignments' => $assignments];
    }
}
