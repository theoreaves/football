<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use App\Models\World;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ClaimSavedGames extends Command
{
    protected $signature = 'football:claim-saves {email : Existing account email} {world? : Unowned save ID} {--all : Claim all unowned saves}';

    protected $description = 'Attach unowned local saves to an existing browser account and move their artwork into private storage';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();
        if (! $user || (! $this->argument('world') && ! $this->option('all'))) {
            $this->error('Use an existing account email and either a save ID or --all.');

            return self::FAILURE;
        }
        $worlds = World::whereNull('owner_user_id')->when($this->argument('world'), fn ($query, $id) => $query->whereKey($id))->get();
        if ($worlds->isEmpty()) {
            $this->error('No matching unowned saves. Saves owned by another account cannot be claimed.');

            return self::FAILURE;
        }
        foreach ($worlds as $world) {
            foreach (Team::withoutGlobalScopes()->where('world_id', $world->id)->get() as $team) {
                foreach (['team_logo', 'midfield_logo', 'helmet_logo_left', 'helmet_logo_right', 'endzone_logo_left', 'endzone_logo_right'] as $asset) {
                    $path = $team->{$asset};
                    if ($path && Storage::disk('public')->exists($path)) {
                        Storage::disk('team_art')->put($path, Storage::disk('public')->get($path));
                        Storage::disk('public')->delete($path);
                    }
                }
            }
            World::whereKey($world->id)->whereNull('owner_user_id')->update(['owner_user_id' => $user->id]);
        }
        $this->info('Claimed '.$worlds->count().' saves for '.$user->email.'.');

        return self::SUCCESS;
    }
}
