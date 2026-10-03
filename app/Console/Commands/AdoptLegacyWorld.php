<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AdoptLegacyWorld extends Command
{
    protected $signature = 'world:adopt-legacy {user : Existing user email} {world : World ID owned by that user}';

    protected $description = 'Assign all unassigned legacy teams, players and games to an existing owned world';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('user'))->firstOrFail();
        $world = $user->worlds()->where('owner_user_id', $user->id)->findOrFail($this->argument('world'));
        DB::transaction(function () use ($world) {
            // Reject partial/corrupt ownership rather than connecting two worlds.
            foreach ([['team_players', 'team_id', 'teams'], ['team_players', 'player_id', 'players'],
                ['games', 'home_team_id', 'teams'], ['games', 'away_team_id', 'teams'],
                ['player_season_stats', 'player_id', 'players']] as [$child, $key, $parent]) {
                $foreign = DB::table($child)->join($parent, $parent.'.id', '=', $child.'.'.$key)
                    ->whereNotNull($parent.'.world_id')->where($parent.'.world_id', '!=', $world->id);
                if ($foreign->exists()) {
                    throw new \LogicException('Adoption requires a single legacy dataset with no foreign-world relationships.');
                }
            }
            foreach (['teams', 'players', 'games'] as $table) {
                DB::table($table)->whereNull('world_id')->update(['world_id' => $world->id]);
            }
        });
        $this->info('Unassigned legacy data now belongs to '.$world->name.'. Existing games remain exhibition games.');

        return self::SUCCESS;
    }
}
