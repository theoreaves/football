<?php

namespace App\Console\Commands;

use App\Http\Controllers\PracticeController;
use App\Models\Exhibition;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateClockTestGame extends Command
{
    protected $signature = 'game:test-clock {world? : World ID; omit to list worlds}
        {--scenario=kneel : kneel, run, warning, fourth, trailing or safety}
        {--home= : Home team ID; defaults to the first team in this world}
        {--away= : Away team ID; defaults to another team in this world}
        {--clock= : Seconds left in Q4, 1 to 900}
        {--timeouts= : Timeouts remaining for the defense, 0 to 3}
        {--down= : Down, 1 to 4}
        {--distance= : Yards to gain, 1 to 99}
        {--spot= : Offense-relative yard line, 1 to 99 (1 is own 1)}
        {--margin= : Possessing team score margin, -50 to 50}
        {--possession=home : home or away; both teams are CPU controlled}
        {--stopped : Start with the game clock stopped}
        {--seed=42 : Repeatable simulation seed, 1 to 2147483647}
        {--penalties : Enable penalties; disabled by default for focused testing}';

    protected $description = 'Create a separate Q4 CPU clock-management test exhibition without modifying existing games';

    private const SCENARIOS = [
        'kneel' => ['clock' => 90, 'timeouts' => 0, 'down' => 1, 'spot' => 50, 'margin' => 7],
        'run' => ['clock' => 175, 'timeouts' => 3, 'down' => 1, 'spot' => 50, 'margin' => 7],
        'warning' => ['clock' => 150, 'timeouts' => 0, 'down' => 1, 'spot' => 50, 'margin' => 7],
        'fourth' => ['clock' => 90, 'timeouts' => 3, 'down' => 4, 'spot' => 45, 'margin' => 7],
        'trailing' => ['clock' => 90, 'timeouts' => 3, 'down' => 1, 'spot' => 50, 'margin' => -7],
        'safety' => ['clock' => 90, 'timeouts' => 0, 'down' => 1, 'spot' => 1, 'margin' => 7],
    ];

    public function handle(RosterBuilder $builder, ExhibitionEngine $engine, PracticeController $appearance): int
    {
        if ($this->argument('world') === null) {
            $this->table(['World ID', 'World', 'Owner user ID'], World::orderBy('id')->get(['id', 'name', 'owner_user_id'])->toArray());
            $this->line('Choose your own world: php artisan game:test-clock WORLD_ID --scenario=kneel');

            return self::SUCCESS;
        }
        $input = array_merge($this->options(), ['world' => $this->argument('world')]);
        $rules = ['world' => 'required|integer|min:1', 'scenario' => 'required|in:'.implode(',', array_keys(self::SCENARIOS)),
            'home' => 'nullable|integer|min:1', 'away' => 'nullable|integer|min:1',
            'clock' => 'nullable|integer|between:1,900', 'timeouts' => 'nullable|integer|between:0,3',
            'down' => 'nullable|integer|between:1,4', 'distance' => 'nullable|integer|between:1,99',
            'spot' => 'nullable|integer|between:1,99', 'margin' => 'nullable|integer|between:-50,50',
            'possession' => 'required|in:home,away', 'seed' => 'required|integer|between:1,2147483647'];
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $world = World::find((int) $input['world']);
        if (! $world) {
            $this->error('World not found. Run game:test-clock without arguments to list worlds.');

            return self::FAILURE;
        }
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        $context->id = $world->id;
        try {
            $home = $input['home'] !== null ? Team::find((int) $input['home']) : Team::orderBy('id')->first();
            $away = $input['away'] !== null ? Team::find((int) $input['away']) : Team::where('id', '!=', $home?->id)->orderBy('id')->first();
            if (! $home || ! $away || $home->id === $away->id) {
                $this->error('Choose two different teams from this world, each with a complete roster.');

                return self::FAILURE;
            }
            $scenario = self::SCENARIOS[$input['scenario']] + ['distance' => 10];
            foreach (array_keys($scenario) as $key) {
                if ($input[$key] !== null) {
                    $scenario[$key] = (int) $input[$key];
                }
            }
            $side = $input['possession'];
            $other = $side === 'home' ? 'away' : 'home';
            $state = array_replace($engine->initial(900, (int) $input['seed'], false), [
                'quarter' => 4, 'clock' => $scenario['clock'], 'clock_running' => ! $this->option('stopped'),
                'warnings' => ['2' => true, '4' => $scenario['clock'] <= 120],
                'possession' => $side, 'down' => $scenario['down'], 'distance' => $scenario['distance'], 'spot' => $scenario['spot'],
                $side.'_score' => 17 + max(0, $scenario['margin']), $other.'_score' => 17 + max(0, -$scenario['margin']),
                'timeouts' => [$side => 3, $other => $scenario['timeouts']],
                'controls' => ['home' => 'cpu', 'away' => 'cpu'],
                'rules' => ['kickoff_profile' => 'traditional', 'overtime' => 'none', 'penalties' => (bool) $this->option('penalties'), 'injuries' => false],
                'coaching' => ['home' => $home->team_settings['coaching'] ?? [], 'away' => $away->team_settings['coaching'] ?? []],
                'team_appearance' => $appearance->matchup($home, $away),
                'crowd' => ['fullness' => 80, 'visitors' => 10, 'seed' => (int) $input['seed']],
                'clock_test' => ['scenario' => $input['scenario']],
            ]);
            $game = DB::transaction(fn () => Exhibition::create([
                'home_team_id' => $home->id, 'away_team_id' => $away->id, 'state' => $state,
                'rosters' => ['home' => $builder->build($home), 'away' => $builder->build($away)], 'history' => [],
            ]));
            $this->info("Created test exhibition #{$game->id} in {$world->name}: {$away->name} at {$home->name}.");
            $this->line("Q4, {$scenario['clock']} seconds, {$side} possession, margin {$scenario['margin']}, defense timeouts {$scenario['timeouts']}.");
            $this->line(route('exhibitions.show', $game));
            $this->line('Open this world while signed in as its owner, open the link and select Next CPU play or Start CPU game.');
            $this->warn('Synthetic test score: earlier quarters and player statistics were not simulated. Existing games and season fixtures are untouched.');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } finally {
            $context->id = $previous;
        }
    }
}
