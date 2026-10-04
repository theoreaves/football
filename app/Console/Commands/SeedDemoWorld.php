<?php

namespace App\Console\Commands;

use App\Models\Exhibition;
use App\Models\LocalSetting;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\World;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;

class SeedDemoWorld extends Command
{
    protected $signature = 'world:seed-demo {world? : Saved-game ID; defaults to the last selected saved game}
        {--teams=4 : Number of fictional teams, from 2 to 16}
        {--year= : Roster year; defaults to the latest season in the world}';

    protected $description = 'Fill an empty local saved game with fictional teams, 53-player rosters and exhibition games';

    private const TEAMS = [
        ['Memphis', 'Riverhawks'], ['Jackson', 'Copperheads'], ['Birmingham', 'Ironclads'], ['Little Rock', 'Thunder'],
        ['Nashville', 'Nightjacks'], ['Tulsa', 'Outriders'], ['Louisville', 'Stallions'], ['Knoxville', 'Firebirds'],
        ['Huntsville', 'Comets'], ['Mobile', 'Breakers'], ['Shreveport', 'Redtails'], ['Chattanooga', 'Mountaineers'],
        ['Springfield', 'Sentinels'], ['Evansville', 'Voyagers'], ['Hattiesburg', 'Timberwolves'], ['Pensacola', 'Tritons'],
    ];

    private const FIRST_NAMES = ['Alex', 'Marcus', 'Jordan', 'Evan', 'Caleb', 'Dylan', 'Miles', 'Noah', 'Cole', 'Logan', 'Isaiah', 'Owen', 'Nolan', 'Gavin', 'Derek', 'Bryce'];

    private const LAST_NAMES = ['Bennett', 'Carter', 'Dawson', 'Ellis', 'Foster', 'Grant', 'Hayes', 'Jackson', 'Lawson', 'Mercer', 'Parker', 'Reed', 'Sullivan', 'Turner', 'Walker', 'Wells'];

    public function handle(): int
    {
        $worldId = $this->argument('world') ?? LocalSetting::find(1)?->current_world_id;
        $world = $worldId ? World::find($worldId) : null;
        if (! $world) {
            $this->error('Create or open a saved game first, or supply its numeric ID: world:seed-demo 1');

            return self::FAILURE;
        }
        $count = filter_var($this->option('teams'), FILTER_VALIDATE_INT);
        $specifiedYear = $this->option('year');
        $year = $specifiedYear === null ? null : filter_var($specifiedYear, FILTER_VALIDATE_INT);
        if ($count === false || $count < 2 || $count > 16 ||
            ($specifiedYear !== null && ($year === false || $year < 1900 || $year > 2200))) {
            $this->error('Use --teams between 2 and 16, and --year between 1900 and 2200.');

            return self::FAILURE;
        }

        $context = app(CurrentWorld::class);
        $previousWorld = $context->id;
        $context->id = $world->id;
        try {
            $year ??= (int) ($world->seasons()->orderByDesc('year')->value('year') ?? 2026);
            $teams = DB::transaction(function () use ($world, $year, $count) {
                $world->newQuery()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                if (Team::exists() || Player::exists() || Exhibition::exists()) {
                    throw new LogicException('This world already contains football data. Create an empty world to seed; nothing was changed.');
                }
                $league = $world->leagues()->first() ?? $world->leagues()->create(['name' => 'Demo Football League']);
                $league->seasons()->firstOrCreate(['year' => $year]);
                $teams = [];
                foreach (array_slice(self::TEAMS, 0, $count) as $index => [$city, $name]) {
                    $team = Team::create([
                        'city' => $city, 'name' => $name, 'abbr' => 'D'.($index + 1),
                    ]);
                    $this->seedRoster($team, $year);
                    $teams[] = $team;
                }
                foreach ($teams as $i => $home) {
                    foreach (array_slice($teams, $i + 1) as $away) {
                        Exhibition::create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'state' => app(ExhibitionEngine::class)->initial(180), 'rosters' => ['home' => app(RosterBuilder::class)->build($home), 'away' => app(RosterBuilder::class)->build($away)], 'history' => []]);
                    }
                }

                return $teams;
            });
            $this->info("Seeded {$world->name}: {$count} teams, ".($count * 53).' players, '.intdiv($count * ($count - 1), 2)." exhibition games. Roster year: {$year}.");
            $this->table(['ID', 'City', 'Team'], array_map(fn (Team $team) => [$team->id, $team->city, $team->name], $teams));
            $this->line('Open Exhibitions to play, or Teams to inspect the rosters. No results or historical stats were fabricated.');

            return self::SUCCESS;
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $context->id = $previousWorld;
        }
    }

    private function seedRoster(Team $team, int $year): void
    {
        $jersey = 1;
        foreach (['QB' => 2, 'RB' => 4, 'WR' => 6, 'TE' => 3, 'OL' => 10, 'DL' => 9, 'LB' => 6, 'CB' => 7, 'S' => 4, 'K' => 1, 'P' => 1] as $position => $count) {
            for ($depth = 1; $depth <= $count; $depth++) {
                $slot = $position.$depth;
                $player = Player::create([
                    'firstname' => self::FIRST_NAMES[array_rand(self::FIRST_NAMES)],
                    'lastname' => self::LAST_NAMES[array_rand(self::LAST_NAMES)],
                    'age' => random_int(21, 34), 'position' => $position,
                ]);
                $player->update(['simulation_ratings' => app(PlayerRatings::class)->generate($player)]);
                $pivot = ['team_id' => $team->id, 'player_id' => $player->id, 'team_year' => (string) $year,
                    'position' => $position, 'jersey_number' => $jersey++, 'depth_chart_position' => $slot];
                TeamPlayer::create($pivot);
            }
        }
    }
}
