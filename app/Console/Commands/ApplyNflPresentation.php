<?php

namespace App\Console\Commands;

use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Models\World;
use App\Services\Simulation\NflPresentationDefaults;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplyNflPresentation extends Command
{
    protected $signature = 'football:apply-nfl-presentation {world : Existing NFL saved-game ID} {--replace-custom : Replace custom stadium and mascot settings and group labels}';

    protected $description = 'Set NFL stadiums, mascots and league labels in one imported save';

    public function handle(NflPresentationDefaults $defaults): int
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $id = filter_var($this->argument('world'), FILTER_VALIDATE_INT);
            if (! $id || $id < 1) {
                throw new \LogicException('Use a valid saved-game ID.');
            }
            $count = DB::transaction(function () use ($id, $context, $defaults) {
                World::whereKey($id)->lockForUpdate()->firstOrFail();
                $context->id = $id;
                $teams = Team::get()->filter(fn ($team) => ($team->team_settings['nfl_import']['source'] ?? '') === 'ESPN')->keyBy('id');
                if ($teams->isEmpty()) {
                    throw new \LogicException('This save has no imported NFL teams.');
                }
                $replace = (bool) $this->option('replace-custom');
                foreach ($teams as $team) {
                    $defaults->apply($team, $replace);
                }
                // Only the importer-created league is eligible; do not rename other leagues.
                foreach (League::whereIn('name', ['NFL', 'National Football League'])->get() as $league) {
                    if ($league->name === 'NFL') {
                        $league->update(['name' => 'National Football League']);
                    }
                    foreach (Season::where('league_id', $league->id)->get() as $season) {
                        $settings = $season->settings;
                        if (! $settings || empty($settings['members'])) {
                            continue;
                        }
                        // A label may be repaired only when the existing grouping already agrees.
                        // Never move teams: doing that could invalidate a schedule or playoff bracket.
                        $members = collect($settings['members']);
                        foreach ($settings['members'] as $teamId => &$member) {
                            $team = $teams->get($teamId);
                            if (! $team) {
                                continue;
                            }
                            $conferenceTeams = $members->where('conference', $member['conference'])->keys()->map(fn ($key) => $teams->get($key));
                            $groupTeams = $members->where('group', $member['group'])->keys()->map(fn ($key) => $teams->get($key));
                            if ($conferenceTeams->every(fn ($other) => $other && $other->conference === $team->conference)
                                && ($replace || preg_match('/^Conference [AB]$/', $member['conference_name']))) {
                                $member['conference_name'] = $team->conference;
                            }
                            if ($groupTeams->every(fn ($other) => $other && $other->conference === $team->conference && $other->division === $team->division)
                                && ($replace || preg_match('/^Division [1-4]$/', $member['division']))) {
                                $member['division'] = $team->division;
                            }
                        }
                        unset($member);
                        $season->settings = $settings;
                        if ($season->isDirty()) {
                            $season->save();
                        }
                    }
                }

                return $teams->count();
            });
            $this->info("Updated {$count} NFL teams in save #{$id}. New games capture stadium and mascot profiles. Season setup now offers AFC/NFC divisions.");
            $this->line('Existing season labels are repaired where their current team groups match the NFL; schedules and team assignments are preserved.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $context->id = $previous;
        }
    }
}
