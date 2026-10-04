<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\World;
use App\Services\Simulation\MiddleEarthRosterGenerator;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;
use Native\Desktop\NativeServiceProvider;

class SeedMiddleEarthWorld extends Command
{
    protected $signature = 'football:seed-middle-earth {world? : Empty saved-game ID; omit to create a new saved game}
        {--name=Middle Earth Football : Name for the new saved game}
        {--year=2026 : Roster season, between 1900 and 2200}
        {--seed=2026 : Reproducible player-generation seed, between 1 and 2147483647}
        {--native : Use the NativePHP desktop database}';

    protected $description = 'Create the original 16-team Middle Earth Football League with new 53-player rosters';

    public function handle(MiddleEarthRosterGenerator $generator): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);
        $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);
        $worldId = $this->argument('world');
        $name = trim((string) $this->option('name'));
        if ($year === false || $year < 1900 || $year > 2200 || $seed === false || $seed < 1 || $seed > 2147483647 ||
            $name === '' || mb_strlen($name) > 255 || ($worldId !== null && (! ctype_digit((string) $worldId) || (int) $worldId < 1))) {
            $this->error('Use a numeric saved-game ID, a name of 1–255 characters, --year from 1900 to 2200, and --seed from 1 to 2147483647.');

            return self::FAILURE;
        }
        if ($this->option('native')) {
            (new NativeServiceProvider($this->laravel))->rewriteDatabase();
        }
        $context = app(CurrentWorld::class);
        $previousWorld = $context->id;
        try {
            $world = DB::transaction(function () use ($worldId, $name, $context, $year, $seed, $generator) {
                $world = $worldId ? World::whereKey($worldId)->lockForUpdate()->first() : World::create(['name' => $name]);
                if (! $world) {
                    throw new LogicException('That saved game does not exist. Omit the ID to create a new Middle Earth saved game.');
                }
                $context->id = $world->id;
                if (Team::exists() || Player::exists() || Game::exists()) {
                    throw new LogicException('This saved game already has football data. Omit the ID to create a new Middle Earth saved game; nothing was changed.');
                }
                $league = $world->leagues()->first() ?? $world->leagues()->create(['name' => 'Middle Earth Football League']);
                $league->seasons()->firstOrCreate(['year' => $year]);
                foreach (config('middle-earth.teams') as $index => [$city, $name, $abbr, $conference, $division, $primary, $secondary, $accent]) {
                    $team = Team::create([
                        'city' => $city, 'name' => $name, 'abbr' => $abbr, 'conference' => $conference, 'division' => $division,
                        'team_color1' => $primary, 'team_color2' => $secondary,
                        'playcalling_behind' => 2, 'playcalling_tied' => 1, 'playcalling_ahead' => -1,
                        'ol_rush' => 6, 'ol_power' => 6, 'ol_pass' => 6, 'ol_protect' => 6,
                        'uniform_home_helmet' => $primary, 'uniform_home_shirt' => $primary,
                        'uniform_home_pants' => $secondary, 'uniform_home_socks' => $accent,
                        'uniform_home_number' => $secondary, 'uniform_home_number_outline' => $accent,
                        'uniform_away_helmet' => $primary, 'uniform_away_shirt' => '#ffffff',
                        'uniform_away_pants' => $primary, 'uniform_away_socks' => $accent,
                        'uniform_away_number' => $primary, 'uniform_away_number_outline' => $secondary,
                        'endzone_text' => strtoupper($name), 'endzone_background' => $primary, 'endzone_text_color' => $secondary,
                    ]);
                    $teamSeed = (int) (($seed + $index * 7919) % 2147483647);
                    foreach ($generator->generate($teamSeed) as $entry) {
                        $player = Player::create($entry['player']);
                        TeamPlayer::create(array_merge($entry['roster'], ['team_id' => $team->id, 'player_id' => $player->id, 'team_year' => (string) $year]));
                    }
                }

                return $world;
            });
            $this->info("Created {$world->name} (saved-game ID {$world->id}): 16 teams, 848 new players. Year {$year}; seed {$seed}.");
            $this->table(['Conference', 'Division', 'Team'], array_map(fn ($team) => [$team[3], $team[4], $team[0].' '.$team[1]], config('middle-earth.teams')));
            $this->line('Open Saved games, select this saved game, then Play exhibition. Existing exhibitions retain their saved rosters.');

            return self::SUCCESS;
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $context->id = $previousWorld;
        }
    }
}
