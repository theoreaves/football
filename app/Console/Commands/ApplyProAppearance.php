<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\ProTeamAppearance;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyProAppearance extends Command
{
    protected $signature = 'football:apply-pro-appearance {world : Saved-game ID}';

    protected $description = 'Apply Pro template stripe, jersey-name, and stadium defaults to matching teams in an existing save';

    public function handle(ProTeamAppearance $appearance): int
    {
        $id = filter_var($this->argument('world'), FILTER_VALIDATE_INT);
        if (! $id || $id < 1 || ! World::whereKey($id)->exists()) {
            $this->error('Provide an existing saved-game ID.');

            return self::FAILURE;
        }
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = $id;
            $count = DB::transaction(function () use ($appearance) {
                $count = 0;
                foreach (config('pro-football.teams') as $entry) {
                    $team = Team::where('abbr', $entry[2])->where('city', $entry[0])->where('name', $entry[1])->first();
                    if ($team) {
                        $team->update($appearance->defaults($entry));
                        $count++;
                    }
                }

                return $count;
            });
            $this->info("Applied stripe, name, and stadium defaults to {$count} Pro teams in save #{$id}.");
        } finally {
            $context->id = $previous;
        }

        return self::SUCCESS;
    }
}
