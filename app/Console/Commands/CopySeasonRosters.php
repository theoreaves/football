<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Models\TeamPlayer;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CopySeasonRosters extends Command
{
    protected $signature = 'football:copy-season-rosters {season : Destination season ID} {from : Source roster year} {--world= : Required saved-world ID} {--force : Skip confirmation}';
    protected $description = 'Copy existing team-player assignments into a new season roster year, without duplicating players';

    public function handle(): int
    {
        $world = filter_var($this->option('world'), FILTER_VALIDATE_INT);
        $seasonId = filter_var($this->argument('season'), FILTER_VALIDATE_INT);
        $from = (string) $this->argument('from');
        if (!$world || !$seasonId || !preg_match('/^(19|20|21)\d{2}$/', $from)) {
            $this->error('Supply a season ID, source year (1900-2199), and --world=ID.');
            return self::FAILURE;
        }
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = (int) $world;
            $season = Season::findOrFail($seasonId);
            $target = (string) $season->year;
            if ($target === $from || $season->phase !== 'regular_season' || !is_array($season->settings)) {
                $this->error('Target must be an uncompleted, configured season with a different roster year.');
                return self::FAILURE;
            }
            if ($season->fixtures()->where('status', '!=', 'scheduled')->exists()) {
                $this->error('Season has started games. Refusing to change roster assignments.');
                return self::FAILURE;
            }
            $teamIds = array_map('intval', array_keys($season->settings['members'] ?? []));
            if (!$teamIds) {
                $this->error('Season has no teams.');
                return self::FAILURE;
            }
            $existing = TeamPlayer::whereIn('team_id', $teamIds)->where('team_year', $target)->count();
            if ($existing) {
                $this->error("Destination year {$target} already contains {$existing} assignments. Refusing to overwrite or mix roster years.");
                return self::FAILURE;
            }
            $source = TeamPlayer::whereIn('team_id', $teamIds)->where('team_year', $from)->get();
            if ($source->isEmpty() || $source->pluck('team_id')->unique()->count() !== count($teamIds)) {
                $this->error('Source year does not contain assignments for every participating team.');
                return self::FAILURE;
            }
            $this->line("Copy {$source->count()} roster assignments from {$from} to {$target} for season {$seasonId}, world {$world}.");
            if (!$this->option('force') && !$this->confirm('Proceed? No players will be duplicated.', false)) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
            DB::transaction(function () use ($seasonId, $target, $from, $teamIds, $source) {
                $locked = Season::whereKey($seasonId)->lockForUpdate()->firstOrFail();
                if ((string) $locked->year !== $target || $locked->phase !== 'regular_season' ||
                    $locked->fixtures()->where('status', '!=', 'scheduled')->exists() ||
                    TeamPlayer::whereIn('team_id', $teamIds)->where('team_year', $target)->exists()) {
                    throw new RuntimeException('Destination changed. No rosters copied.');
                }
                foreach ($source as $row) {
                    TeamPlayer::create([
                        'team_id' => $row->team_id,
                        'player_id' => $row->player_id,
                        'team_year' => $target,
                        'position' => $row->position,
                        'depth_chart_position' => $row->depth_chart_position,
                        'jersey_number' => $row->jersey_number,
                    ]);
                }
            }, 3);
            $this->info('Assignments copied. Failed simulations can now be retried from the season screen.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('No changes committed: '.$e->getMessage());
            return self::FAILURE;
        } finally {
            $context->id = $previous;
        }
    }
}
