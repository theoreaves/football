<?php

namespace App\Services\Simulation;

use App\Models\Exhibition;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\DB;
use LogicException;

class LeagueTemplateSeeder
{
    public function pro(World $world, int $year, int $seed): void
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            DB::transaction(function () use ($world, $year, $seed, $context) {
                World::whereKey($world->id)->lockForUpdate()->firstOrFail();
                $context->id = $world->id;
                if (Team::exists() || Player::exists() || Exhibition::exists()) {
                    throw new LogicException('This saved game already has football data. Choose an empty save; nothing was changed.');
                }
                $league = $world->leagues()->first() ?? $world->leagues()->create(['name' => 'Pro Football League']);
                $league->seasons()->firstOrCreate(['year' => $year]);
                foreach (config('pro-football.teams') as $index => [$city, $name, $abbr, $conference, $division, $primary, $secondary, $accent, $helmet, $pants]) {
                    $team = Team::create([
                        'city' => $city, 'name' => $name, 'abbr' => $abbr, 'conference' => $conference, 'division' => $division,
                        'team_color1' => $primary, 'team_color2' => $secondary,
                        'uniform_home_helmet' => $helmet, 'uniform_home_shirt' => $primary, 'uniform_home_pants' => $pants, 'uniform_home_socks' => $primary,
                        'uniform_home_number' => '#ffffff', 'uniform_home_number_outline' => $secondary,
                        'uniform_away_helmet' => $helmet, 'uniform_away_shirt' => '#ffffff', 'uniform_away_pants' => $pants, 'uniform_away_socks' => $primary,
                        'uniform_away_number' => $primary, 'uniform_away_number_outline' => $secondary,
                        'uniform_home_helmet_stripe' => $secondary, 'uniform_home_shoulder_stripe' => $accent, 'uniform_home_pants_stripe' => $primary,
                        'uniform_away_helmet_stripe' => $secondary, 'uniform_away_shoulder_stripe' => $accent, 'uniform_away_pants_stripe' => $primary,
                        'uniform_home_facemask' => $primary, 'uniform_away_facemask' => $primary,
                        'endzone_text' => strtoupper($name), 'endzone_background' => $primary, 'endzone_text_color' => '#ffffff',
                    ]);
                    app(ProTeamBranding::class)->apply($team);
                    $teamSeed = (int) (($seed + $index * 7919) % 2147483647);
                    foreach (app(ProRosterGenerator::class)->generate($teamSeed) as $entry) {
                        $player = Player::create($entry['player']);
                        TeamPlayer::create(array_merge($entry['roster'], ['team_id' => $team->id, 'player_id' => $player->id, 'team_year' => (string) $year]));
                    }
                }
            });
        } finally {
            $context->id = $previous;
        }
    }
}
