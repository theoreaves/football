<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Simulation\NflRosterImport;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

class SeedNflWorld extends Command
{
    protected $signature = 'football:seed-nfl {user : Existing account name or email}
        {--name=NFL 2026 : Name of the new private save}
        {--dry-run : Download and validate without creating a save}
        {--snapshot= : Write the downloaded roster/depth JSON to a new file}
        {--from= : Import a previously downloaded snapshot instead of requesting rosters}
        {--include-practice : Also import practice squad players}
        {--no-logos : Skip team logo downloads}';

    protected $description = 'Create a private 2026 NFL save with source rosters and estimated editable gameplay ratings';

    public function handle(NflRosterImport $importer): int
    {
        ini_set('memory_limit', '-1');
        try {
            $identity = mb_strtolower(trim((string) $this->argument('user')));
            $owners = User::whereRaw('LOWER(name) = ?', [$identity])->orWhereRaw('LOWER(email) = ?', [$identity])->get();
            if ($identity === '' || $owners->count() !== 1) {
                throw new LogicException('Account name/email must match exactly one existing user. Use the account email if names are duplicated.');
            }
            $name = trim((string) $this->option('name'));
            if ($name === '' || mb_strlen($name) > 255) {
                throw new LogicException('Save name must contain 1–255 characters.');
            }
            if ($this->option('snapshot') && file_exists($this->option('snapshot'))) {
                throw new LogicException('Snapshot output already exists; choose a new filename.');
            }
            if ($this->option('from')) {
                $bytes = @file_get_contents($this->option('from'));
                if ($bytes === false) {
                    throw new LogicException('Could not read the snapshot.');
                }
                $bundle = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $bundle = $importer->download(fn ($team) => $this->line('Fetching 2026 roster and depth: '.$team));
            }
            $prepared = $importer->prepare($bundle, (bool) $this->option('include-practice'));
            $count = array_sum(array_map(fn ($team) => count($team['players']), $prepared));
            $unassigned = array_sum(array_map(fn ($team) => count(array_filter($team['players'], fn ($entry) => $entry['roster']['jersey_number'] === null)), $prepared));
            if ($unassigned > 0) {
                $this->warn("{$unassigned} missing/conflicting reserve jersey numbers left unassigned; original numbers retained in player appearance metadata.");
            }
            $this->line("Validated 32 teams and {$count} players. Gameplay ratings are estimates from position, physical data, experience and depth, not official NFL ratings.");
            if ($this->option('snapshot')) {
                $file = fopen($this->option('snapshot'), 'x');
                if (! $file) {
                    throw new LogicException('Could not create the snapshot file.');
                }
                try {
                    $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    if (fwrite($file, $json) !== strlen($json)) {
                        throw new LogicException('Could not write the complete snapshot.');
                    }
                } finally {
                    fclose($file);
                }
                $this->info('Snapshot saved: '.$this->option('snapshot'));
            }
            if ($this->option('dry-run')) {
                $this->info('Dry run complete. No save was created.');

                return self::SUCCESS;
            }
            if (! $this->option('no-logos')) {
                $this->line('Downloading 32 team logos before creating the save…');
            }
            $world = $importer->create($owners->first(), $name, $prepared, (string) ($bundle['captured_at'] ?? now()->toIso8601String()), ! $this->option('no-logos'));
            $this->info("Created {$world->name} (save #{$world->id}) for {$owners->first()->email}: 32 teams, {$count} players. Open it in Saved games and create your season using roster year 2026.");

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
