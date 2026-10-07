<?php

namespace App\Services\Simulation;

use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProTeamBranding
{
    public function apply(Team $team): void
    {
        $source = resource_path('team-logos/pro/'.strtolower($team->abbr).'.svg');
        if (! is_file($source)) {
            throw new RuntimeException("Missing starter logo for {$team->abbr}.");
        }
        $disk = Storage::disk('team_art');
        $paths = [];
        try {
            foreach (['team_logo', 'helmet_logo_left', 'helmet_logo_right', 'midfield_logo'] as $field) {
                $path = "worlds/{$team->world_id}/teams/{$team->id}/{$field}.svg";
                if (! $disk->put($path, file_get_contents($source))) {
                    throw new RuntimeException('Unable to save starter team art.');
                }
                $paths[$field] = $path;
            }
            $team->update($paths);
        } catch (\Throwable $exception) {
            $disk->delete(array_values($paths));
            throw $exception;
        }
    }
}
