<?php

namespace App\Console\Commands;

use App\Models\Exhibition;
use App\Models\Season;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetSeasonPlayoffs extends Command
{
    protected $signature = 'football:reset-playoffs {season : Season ID} {--world= : Required saved-world ID} {--force : Skip the confirmation prompt}';

    protected $description = 'Reset a saved postseason without touching regular-season fixtures or statistics';

    public function handle(): int
    {
        $worldId = filter_var($this->option('world'), FILTER_VALIDATE_INT);
        $seasonId = filter_var($this->argument('season'), FILTER_VALIDATE_INT);
        if (!$worldId || $worldId < 1 || !$seasonId || $seasonId < 1) {
            $this->error('Provide a valid season ID and --world=ID. Example: football:reset-playoffs 1 --world=1');
            return self::FAILURE;
        }

        $context = app(CurrentWorld::class);
        $previousWorld = $context->id;
        try {
            $context->id = (int) $worldId;
            $season = Season::find($seasonId);
            if (!$season || !is_array($season->settings)) {
                $this->error('No configured season with that ID exists in this saved world.');
                return self::FAILURE;
            }
            if (($season->settings['playoffs'] ?? 'none') === 'none') {
                $this->error('This season does not have a playoff format.');
                return self::FAILURE;
            }
            if (!in_array($season->phase, ['playoffs_pending', 'playoffs', 'completed'], true)) {
                $this->error('The regular season has not finished; nothing to reset.');
                return self::FAILURE;
            }
            $bracket = $season->settings['playoff_bracket'] ?? null;
            if (!is_array($bracket)) {
                $this->info('No saved playoff bracket exists. Nothing changed.');
                return self::SUCCESS;
            }
            $ids = $this->gameIds($bracket);
            $this->warn("World {$worldId}, season {$seasonId}: reset postseason bracket and delete ".count($ids)." linked playoff game(s).");
            if (!$this->option('force') && !$this->confirm('Proceed? This deletes playoff game histories and replays.', false)) {
                $this->info('Cancelled; nothing changed.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($seasonId, $ids) {
                $locked = Season::whereKey($seasonId)->lockForUpdate()->firstOrFail();
                $settings = $locked->settings;
                if (!is_array($settings['playoff_bracket'] ?? null) ||
                    $this->gameIds($settings['playoff_bracket']) !== $ids) {
                    throw new RuntimeException('Bracket changed after confirmation. Retry the command.');
                }

                foreach ($ids as $id) {
                    $game = Exhibition::whereKey($id)->lockForUpdate()->firstOrFail();
                    if ((int) ($game->state['playoff']['season_id'] ?? 0) !== (int) $seasonId) {
                        throw new RuntimeException("Game {$id} is not owned by this season's postseason.");
                    }
                    if ($game->seasonFixture()->exists()) {
                        throw new RuntimeException("Game {$id} belongs to a regular-season fixture.");
                    }
                    $game->delete();
                }
                unset($settings['playoff_bracket']);
                $locked->update(['settings' => $settings, 'phase' => 'playoffs_pending']);
            }, 3);
            $this->info('Playoffs reset. Regular season and its statistics were preserved.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Reset failed; transaction rolled back: '.$e->getMessage());
            return self::FAILURE;
        } finally {
            $context->id = $previousWorld;
        }
    }

    private function gameIds(array $bracket): array
    {
        $ids = [];
        foreach ($bracket['conferences'] ?? [] as $conference) {
            foreach ($conference['games'] ?? [] as $game) {
                if (!empty($game['exhibition_id'])) $ids[] = (int) $game['exhibition_id'];
            }
        }
        foreach ($bracket['rounds'] ?? [] as $groups) {
            foreach ($groups as $games) {
                foreach ($games as $game) {
                    if (!empty($game['exhibition_id'])) $ids[] = (int) $game['exhibition_id'];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }
}
