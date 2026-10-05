<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\World;
use App\Services\Simulation\LeagueTemplateSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;

class SeedProWorld extends Command
{
    protected $signature = 'football:seed-pro-league {world? : Empty save ID}
        {--owner= : Existing account email, required when creating a new save}
        {--name=Pro Football : New save name}
        {--year=2026 : Roster season between 1900 and 2200}
        {--seed=2026 : Reproducible roster seed between 1 and 2147483647}';

    protected $description = 'Seed 32 NFL-inspired fictional teams with city colors and complete generated rosters';

    public function handle(LeagueTemplateSeeder $seeder): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);
        $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);
        $worldId = $this->argument('world');
        $name = trim((string) $this->option('name'));
        $owner = $this->option('owner') ? User::where('email', strtolower($this->option('owner')))->first() : null;
        if ($year === false || $year < 1900 || $year > 2200 || $seed === false || $seed < 1 || $seed > 2147483647 || $name === '' || mb_strlen($name) > 255 || ($worldId !== null && (! ctype_digit((string) $worldId) || (int) $worldId < 1)) || (! $worldId && ! $owner) || ($worldId && $this->option('owner'))) {
            $this->error('Use an empty save ID, or --owner with an existing account email to create a new save; --year 1900–2200, --seed 1–2147483647, and a name of 1–255 characters.');

            return self::FAILURE;
        }
        try {
            $world = DB::transaction(function () use ($worldId, $owner, $name, $year, $seed, $seeder) {
                $world = $worldId ? World::find($worldId) : World::create(['name' => $name, 'owner_user_id' => $owner->id]);
                if (! $world) {
                    throw new LogicException('That saved game does not exist.');
                }
                $seeder->pro($world, $year, $seed);

                return $world;
            });
            $this->info("Created {$world->name} (save #{$world->id}): 32 teams, 1696 fictional players. Open it in Saved games.");

            return self::SUCCESS;
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
