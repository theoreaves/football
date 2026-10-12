<?php

namespace App\Services\Simulation;

use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use LogicException;

class NflHelmetDefaults
{
    /** Returns newly written files so a failed startup can clean up. */
    public function apply(Team $team, bool $replaceCustom = false, bool $artwork = true): array
    {
        $abbr = strtoupper($team->abbr);
        $design = match ($abbr) {
            'CIN' => 'cat_stripes', 'LAR' => 'ram_horns', default => 'standard'
        };
        $defaults = ['home' => ['design' => $design], 'away' => ['design' => $design],
            'flip_left' => false, 'flip_right' => in_array($abbr, ['ATL', 'BUF', 'DEN', 'DET', 'MIA'], true),
            'logo_color' => in_array($abbr, ['NYG', 'NYJ'], true) ? '#ffffff' : null];
        $settings = $team->team_settings ?? [];
        $custom = $settings['helmets'] ?? [];
        $settings['helmets'] = $replaceCustom ? $defaults : array_replace_recursive($defaults, $custom);
        $settings['nfl_import']['helmet_version'] = 1;
        $updates = ['team_settings' => $settings];
        $written = [];
        $disk = Storage::disk('team_art');
        try {
            foreach (['left', 'right'] as $side) {
                $field = 'helmet_logo_'.$side;
                $path = $team->{$field};
                $generated = ! $path || $path === "teams/{$team->id}/nfl-logo.png" || str_starts_with($path, "teams/{$team->id}/nfl-helmet-v1-");
                if (! $replaceCustom && ! $generated) {
                    continue;
                }
                if (in_array($abbr, ['CLE', 'CIN', 'LAR'], true) || ($abbr === 'PIT' && $side === 'left')) {
                    $updates[$field] = null;
                } elseif ($artwork && in_array($abbr, ['KC', 'BAL', 'PHI'], true)) {
                    $source = strtolower($abbr).'-'.$side.'.svg';
                    $bytes = file_get_contents(resource_path('nfl-helmet-decals/'.$source));
                    $target = "teams/{$team->id}/nfl-helmet-v1-{$side}.svg";
                    $new = ! $disk->exists($target);
                    if ($new) {
                        $written[] = $target;
                    }
                    if (! $disk->put($target, $bytes)) {
                        throw new LogicException('Could not save helmet decal for '.$abbr);
                    }
                    $updates[$field] = $target;
                }
            }
            if (! $custom || $replaceCustom) {
                foreach (['home', 'away'] as $venue) {
                    if ($abbr === 'CIN') {
                        $updates["uniform_{$venue}_helmet_stripe"] = '#000000';
                    }
                    if ($abbr === 'LAR') {
                        $updates["uniform_{$venue}_helmet_stripe"] = '#ffa300';
                    }
                    if (in_array($abbr, ['KC', 'ATL', 'PHI', 'CIN', 'LAR'], true)) {
                        $updates["uniform_{$venue}_helmet_stripe_enabled"] = false;
                    }
                }
            }
            $team->fill($updates);
            if ($team->isDirty()) {
                $team->save();
            }

            return $written;
        } catch (\Throwable $error) {
            if ($written) {
                $disk->delete($written);
            }
            throw $error;
        }
    }
}
