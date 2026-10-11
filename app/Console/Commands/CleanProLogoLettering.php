<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\World;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanProLogoLettering extends Command
{
    protected $signature = 'football:clean-pro-logo-lettering {world : Saved-game ID}';

    protected $description = 'Remove footer lettering from unchanged starter Pro logos in one saved game';

    public function handle(): int
    {
        $id = filter_var($this->argument('world'), FILTER_VALIDATE_INT);
        if (! $id || $id < 1 || ! World::whereKey($id)->exists()) {
            $this->error('Provide an existing saved-game ID.');

            return self::FAILURE;
        }
        $disk = Storage::disk('team_art');
        $count = 0;
        foreach (Team::withoutGlobalScopes()->where('world_id', $id)->get() as $team) {
            $source = resource_path('team-logos/pro/'.strtolower($team->abbr).'.svg');
            if (! is_file($source)) {
                continue;
            }
            $clean = file_get_contents($source);
            foreach (['team_logo', 'helmet_logo_left', 'helmet_logo_right', 'midfield_logo'] as $field) {
                $path = $team->{$field};
                // Only inspect generated assets owned by this team, never uploaded art.
                if ($path !== "worlds/{$id}/teams/{$team->id}/{$field}.svg" || ! $disk->exists($path)) {
                    continue;
                }
                $old = $disk->get($path);
                $updated = preg_replace('/<path d="M61 165h134v37H61Z"[^>]*\/><text\b.*?<\/text>/s', '', $old);
                if ($updated !== $old && $updated === $clean) {
                    if (! $disk->put($path, $updated)) {
                        $this->error("Unable to update {$path}.");

                        return self::FAILURE;
                    }
                    $count++;
                }
            }
        }
        $this->info("Removed lettering from {$count} starter logo assets in save #{$id}.");

        return self::SUCCESS;
    }
}
