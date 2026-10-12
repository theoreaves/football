<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\NflHelmetDefaults;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ApplyNflHelmets extends Command
{
    protected $signature = 'football:apply-nfl-helmets {world : Existing NFL saved-game ID} {--replace-custom : Also replace manually configured helmet profiles and decals}';

    protected $description = 'Apply NFL helmet defaults to imported teams in one save without changing its rosters or games';

    public function handle(NflHelmetDefaults $helmets): int
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        $written = [];
        try {
            $id = filter_var($this->argument('world'), FILTER_VALIDATE_INT);
            if (! $id || $id < 1) {
                throw new \LogicException('Use a valid saved-game ID.');
            }
            $count = DB::transaction(function () use ($id, $context, $helmets, &$written) {
                World::whereKey($id)->lockForUpdate()->firstOrFail();
                $context->id = $id;
                $count = 0;
                foreach (Team::get() as $team) {
                    if (($team->team_settings['nfl_import']['source'] ?? '') !== 'ESPN') {
                        continue;
                    }
                    $written = array_merge($written, $helmets->apply($team, (bool) $this->option('replace-custom')));
                    $count++;
                }

                return $count;
            });
            $this->info("Applied helmet defaults to {$count} NFL teams in save #{$id}. Open Team Settings → Uniforms to preview; new games capture the updated profiles.");

            return self::SUCCESS;
        } catch (Throwable $error) {
            if ($written) {
                Storage::disk('team_art')->delete($written);
            }
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $context->id = $previous;
        }
    }
}
