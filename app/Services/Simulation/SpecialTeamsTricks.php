<?php

namespace App\Services\Simulation;

class SpecialTeamsTricks
{
    public const FAKES = ['fake_punt_run', 'fake_punt_pass', 'fake_field_goal_run', 'fake_field_goal_pass'];

    public static function personnel(array $rosters, string $side, string $call): array
    {
        if (in_array($call, self::FAKES, true)) {
            // Punter on punt fakes; punter serves as the holder on field-goal fakes.
            $rosters[$side]['players']['QB'] = $rosters[$side]['players']['P'];
            if (str_starts_with($call, 'fake_field_goal')) {
                $rosters[$side]['players']['RB'] = $rosters[$side]['players']['K'];
            }
        }

        return $rosters;
    }

    public static function roll(array $state, string $tag): float
    {
        return ((int) sprintf('%u', crc32($state['seed'].':'.$state['version'].':special-trick:'.$tag)) % 100000) / 100000;
    }

    public static function fakeChance(array $state, string $call, array $off, array $def, string $defense): float
    {
        // FanIQ 1999-2025 formation-tagged proxy: 53.9% punt / 27.9% FG.
        // Chosen short punt fakes: 62.8%; forced/long punt fakes: 46.6%.
        $punt = str_starts_with($call, 'fake_punt');
        $base = $punt ? (($state['distance'] <= 5 && $state[$state['possession'].'_score'] - $state[($state['possession'] === 'home' ? 'away' : 'home').'_score'] >= -8) ? .628 : .466) : .279;
        $skill = str_ends_with($call, '_pass') ? $off['QB']['ratings']['throwing'] : $off['QB']['ratings']['speed'];
        $adjustment = ($skill - $def['LB2']['ratings']['awareness']) * .0015;
        // A normal defense guarding against the fake removes part of the surprise.
        $adjustment -= in_array($defense, ['punt_return', 'field_goal_block'], true) ? 0 : .12;
        $adjustment -= max(0, $state['distance'] - ($punt ? 8 : 5)) * .025;

        return max(.03, min(.8, $base + $adjustment));
    }

    public static function cpuFake(array $state, string $kick): ?array
    {
        if ($state['distance'] > 8 || $state['spot'] < 30 || $state['spot'] > 90
            || (in_array($state['quarter'], [2, 4], true) && $state['clock'] <= 120)) {
            return null;
        }
        // Deliberate model choices, not measured per-opportunity NFL rates.
        // ~19 punt fakes/year and ~3 FG fakes/year are league-wide reference volumes.
        // 2025 regular-season pbp contains 652 punts / 375 fourth-down FG
        // attempts meeting these same eligibility filters. Target 19 / 3 fakes.
        $chance = $kick === 'punt' ? 19 / 652 : 3 / 375;
        if (self::roll($state, 'cpu-'.$kick) >= $chance) {
            return null;
        }
        $type = self::roll($state, 'fake-type') < .5 ? 'run' : 'pass';

        return ['call' => 'fake_'.$kick.'_'.$type, 'formation' => $kick];
    }

    public static function cpuOnside(array $state): bool
    {
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $deficit = $state[$other.'_score'] - $state[$side.'_score'];
        if ($state['quarter'] !== 4 || $deficit <= 0 || $state['clock'] <= 0) {
            return false;
        }
        // At 1:22 down two, a deep kick risks the opponent kneeling out the game.
        // With three timeouts and more time, attempt a defensive stop instead.
        $timeouts = $state['timeouts'][$side] ?? 0;

        return $state['clock'] <= 90 || ($state['clock'] <= 150 && $timeouts < 3)
            || ($deficit > 8 && $state['clock'] <= 240);
    }
}
