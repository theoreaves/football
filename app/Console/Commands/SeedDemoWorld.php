<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\LocalSetting;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\World;
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
                if (Team::exists() || Player::exists() || Game::exists()) {
                    throw new LogicException('This world already contains football data. Create an empty world to seed; nothing was changed.');
                }
                $league = $world->leagues()->first() ?? $world->leagues()->create(['name' => 'Demo Football League']);
                $league->seasons()->firstOrCreate(['year' => $year]);
                $teams = [];
                foreach (array_slice(self::TEAMS, 0, $count) as $index => [$city, $name]) {
                    $team = Team::create([
                        'city' => $city, 'name' => $name, 'abbr' => 'D'.($index + 1),
                        'playcalling_behind' => 2, 'playcalling_tied' => 1, 'playcalling_ahead' => -1,
                        'ol_rush' => random_int(3, 8), 'ol_power' => random_int(3, 8),
                        'ol_pass' => random_int(3, 8), 'ol_protect' => random_int(3, 8),
                    ]);
                    $this->seedRoster($team, $year);
                    $teams[] = $team;
                }
                foreach ($teams as $i => $home) {
                    foreach (array_slice($teams, $i + 1) as $away) {
                        Game::create([
                            'home_team_id' => $home->id, 'away_team_id' => $away->id,
                            'quarter' => 1, 'clock' => 900, 'phase' => 'KICKOFF',
                            'home_q' => [0, 0, 0, 0, 0], 'away_q' => [0, 0, 0, 0, 0],
                            'first_kick_team' => 'HOME',
                        ]);
                    }
                }

                return $teams;
            });
            $this->info("Seeded {$world->name}: {$count} teams, ".($count * 53).' players, '.intdiv($count * ($count - 1), 2)." exhibition games. Roster year: {$year}.");
            $this->table(['ID', 'City', 'Team'], array_map(fn (Team $team) => [$team->id, $team->city, $team->name], $teams));
            $this->line('Open Games to play, or Teams to inspect the rosters. No results or historical stats were fabricated.');

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
                $player = Player::create(array_merge([
                    'firstname' => self::FIRST_NAMES[array_rand(self::FIRST_NAMES)],
                    'lastname' => self::LAST_NAMES[array_rand(self::LAST_NAMES)],
                    'age' => random_int(21, 34), 'position' => $position,
                ], $this->ratings($position)));
                $pivot = [
                    'team_id' => $team->id, 'player_id' => $player->id, 'team_year' => (string) $year,
                    'position' => $position, 'jersey_number' => $jersey++, 'depth_chart_position' => $slot,
                    'kick_return_depth_chart_position' => '', 'punt_return_depth_chart_position' => '',
                ];
                $groups = [
                    'catch' => ['RB1', 'RB2', 'TE1', 'TE2', 'WR1', 'WR2', 'WR3', 'WR4'],
                    'catch_plus' => ['TE1', 'TE2', 'WR1', 'WR2', 'WR3', 'WR4'],
                    'rush' => ['QB1', 'RB1', 'RB2', 'RB3', 'RB4'],
                    'sack' => ['DL1', 'DL2', 'DL3', 'DL4', 'LB1', 'LB2', 'LB3', 'LB4'],
                    'interception' => ['LB1', 'LB2', 'LB3', 'LB4', 'CB1', 'CB2', 'S1', 'S2'],
                    'tackle' => ['DL1', 'DL2', 'DL3', 'DL4', 'LB1', 'LB2', 'LB3', 'LB4', 'CB1', 'CB2', 'S1', 'S2'],
                    'kick' => ['WR1', 'WR2', 'RB2'], 'punt' => ['WR1', 'WR2', 'RB2'],
                ];
                foreach ($groups as $prefix => $slots) {
                    $index = array_search($slot, $slots, true);
                    $pivot[$prefix.'_from'] = $index === false ? 0 : intdiv($index * 20, count($slots)) + 1;
                    $pivot[$prefix.'_to'] = $index === false ? 0 : intdiv(($index + 1) * 20, count($slots));
                    if ($index !== false && in_array($prefix, ['kick', 'punt'], true)) {
                        $pivot[$prefix === 'kick' ? 'kick_return_depth_chart_position' : 'punt_return_depth_chart_position'] = ($prefix === 'kick' ? 'KR' : 'PR').($index + 1);
                    }
                }
                TeamPlayer::create($pivot);
            }
        }
    }

    private function ratings(string $position): array
    {
        $ratings = ['speed' => random_int(3, 9), 'fumble' => random_int(1, 5)];
        $fields = match ($position) {
            'QB' => ['pass_evade', 'pass_accuracy', 'pass_deep', 'pass_control', 'rush'],
            'RB' => ['rush', 'rush_power', 'receive', 'receive_deep', 'return_yards', 'return_speed', 'return_fumble'],
            'WR', 'TE' => ['receive', 'receive_deep', 'return_yards', 'return_speed', 'return_fumble'],
            'DL', 'LB', 'CB', 'S' => ['tackle', 'sack', 'cover', 'interception', 'strip'],
            'K' => ['kick30', 'kick39', 'kick49', 'kick50'],
            'P' => ['punt_distance', 'punt_pooch', 'punt_block'],
            default => [],
        };
        foreach ($fields as $field) {
            $ratings[$field] = random_int(3, 9);
        }
        if ($position === 'P') {
            $ratings['punt_pooch_yard'] = random_int(45, 55);
        }

        return $ratings;
    }
}
