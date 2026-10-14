<?php

namespace App\Services\Simulation;

class OffensiveOptions
{
    public const ALIASES = ['inside_zone' => 'inside_run', 'outside_zone' => 'outside_run', 'stretch' => 'outside_run', 'toss' => 'outside_run', 'counter' => 'inside_run', 'power' => 'inside_run', 'lead' => 'inside_run', 'play_action' => 'medium_pass', 'mesh' => 'short_pass', 'flood' => 'medium_pass', 'quick_pass' => 'short_pass', 'qb_sneak' => 'inside_run', 'qb_draw' => 'inside_run', 'qb_power' => 'inside_run', 'qb_sweep' => 'outside_run', 'fake_punt_run' => 'inside_run', 'fake_punt_pass' => 'short_pass', 'fake_field_goal_run' => 'inside_run', 'fake_field_goal_pass' => 'short_pass'];

    public const FORMATIONS = [
        'singleback' => ['qb_sneak', 'inside_zone', 'outside_zone', 'counter', 'play_action', 'short_pass', 'inside_run', 'outside_run', 'slant', 'medium_pass'],
        'i_form' => ['qb_sneak', 'power', 'lead', 'toss', 'play_action', 'short_pass', 'inside_run', 'outside_run', 'medium_pass'],
        'shotgun' => ['inside_zone', 'draw', 'screen', 'slant', 'mesh', 'medium_pass', 'deep_pass', 'qb_draw', 'qb_power', 'qb_sweep', 'inside_run', 'short_pass'],
        'pistol' => ['inside_zone', 'stretch', 'counter', 'play_action', 'qb_power', 'qb_sweep', 'inside_run', 'outside_run', 'draw', 'short_pass', 'medium_pass', 'deep_pass'],
        'spread' => ['outside_zone', 'draw', 'screen', 'quick_pass', 'flood', 'deep_pass', 'qb_draw', 'qb_sweep', 'outside_run', 'short_pass', 'medium_pass'],
        'trips' => ['screen', 'slant', 'mesh', 'flood', 'deep_pass', 'outside_run', 'short_pass', 'medium_pass'],
        'bunch' => ['quick_pass', 'mesh', 'flood', 'screen', 'short_pass', 'medium_pass'],
        'empty' => ['quick_pass', 'mesh', 'medium_pass', 'deep_pass', 'qb_draw', 'short_pass'],
        'goal_line' => ['qb_sneak', 'power', 'lead', 'play_action', 'inside_run', 'short_pass'],
        'punt' => ['punt', 'fake_punt_run', 'fake_punt_pass'], 'field_goal' => ['field_goal', 'fake_field_goal_run', 'fake_field_goal_pass'],
    ];

    public static function canonical(string $call): string
    {
        return self::ALIASES[$call] ?? match ($call) {
            'draw' => 'inside_run', 'screen' => 'short_pass', default => $call
        };
    }

    public static function quarterbackRun(string $design): bool
    {
        return in_array($design, ['qb_sneak', 'qb_draw', 'qb_power', 'qb_sweep'], true);
    }
}
