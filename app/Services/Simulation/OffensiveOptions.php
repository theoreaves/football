<?php

namespace App\Services\Simulation;

class OffensiveOptions
{
    public const ALIASES = ['inside_zone' => 'inside_run', 'outside_zone' => 'outside_run', 'stretch' => 'outside_run', 'toss' => 'outside_run', 'counter' => 'inside_run', 'power' => 'inside_run', 'lead' => 'inside_run', 'play_action' => 'medium_pass', 'mesh' => 'short_pass', 'flood' => 'medium_pass', 'quick_pass' => 'short_pass', 'qb_sneak' => 'inside_run', 'qb_draw' => 'inside_run', 'qb_power' => 'inside_run', 'qb_sweep' => 'outside_run', 'kickoff_deep' => 'kickoff', 'kickoff_high' => 'kickoff', 'kickoff_short' => 'kickoff', 'kickoff_left' => 'kickoff', 'kickoff_right' => 'kickoff', 'punt_distance' => 'punt', 'punt_hang' => 'punt', 'punt_pooch' => 'punt', 'punt_coffin' => 'punt', 'fake_punt_run' => 'inside_run', 'fake_punt_pass' => 'short_pass', 'fake_field_goal_run' => 'inside_run', 'fake_field_goal_pass' => 'short_pass', 'hitch' => 'short_pass', 'curl' => 'short_pass', 'te_stick' => 'short_pass', 'drag' => 'short_pass', 'rb_flat' => 'short_pass', 'comeback' => 'medium_pass', 'dig' => 'medium_pass', 'out' => 'medium_pass', 'te_seam' => 'medium_pass', 'post' => 'deep_pass', 'corner' => 'deep_pass', 'fade' => 'deep_pass', 'wheel' => 'deep_pass'];

    public const FORMATIONS = [
         'singleback' => ['qb_sneak', 'inside_zone', 'outside_zone', 'counter', 'play_action', 'short_pass', 'inside_run', 'outside_run', 'slant', 'medium_pass', 'curl', 'comeback', 'te_stick', 'te_seam', 'rb_flat'],
        'i_form' => ['qb_sneak', 'power', 'lead', 'toss', 'play_action', 'short_pass', 'inside_run', 'outside_run', 'medium_pass'],
         'shotgun' => ['inside_zone', 'draw', 'screen', 'slant', 'mesh', 'medium_pass', 'deep_pass', 'qb_draw', 'qb_power', 'qb_sweep', 'inside_run', 'short_pass', 'hitch', 'curl', 'dig', 'out', 'post', 'corner', 'fade', 'drag', 'rb_flat', 'wheel'],
        'pistol' => ['inside_zone', 'stretch', 'counter', 'play_action', 'qb_power', 'qb_sweep', 'inside_run', 'outside_run', 'draw', 'short_pass', 'medium_pass', 'deep_pass'],
         'spread' => ['outside_zone', 'draw', 'screen', 'quick_pass', 'flood', 'deep_pass', 'qb_draw', 'qb_sweep', 'outside_run', 'short_pass', 'medium_pass', 'hitch', 'curl', 'comeback', 'dig', 'out', 'post', 'corner', 'fade', 'drag', 'wheel'],
         'trips' => ['screen', 'slant', 'mesh', 'flood', 'deep_pass', 'outside_run', 'short_pass', 'medium_pass', 'drag', 'out', 'dig', 'post', 'corner', 'wheel'],
         'bunch' => ['quick_pass', 'mesh', 'flood', 'screen', 'short_pass', 'medium_pass', 'hitch', 'drag', 'out', 'te_stick'],
         'empty' => ['quick_pass', 'mesh', 'medium_pass', 'deep_pass', 'qb_draw', 'short_pass', 'hitch', 'dig', 'post', 'corner', 'fade', 'drag'],
         'goal_line' => ['qb_sneak', 'power', 'lead', 'play_action', 'inside_run', 'short_pass', 'te_stick', 'fade'],
        'punt' => ['punt', 'punt_distance', 'punt_hang', 'punt_pooch', 'punt_coffin', 'fake_punt_run', 'fake_punt_pass'], 'field_goal' => ['field_goal', 'fake_field_goal_run', 'fake_field_goal_pass'],
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
