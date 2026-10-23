<?php
namespace App\Console\Commands;

use App\Models\Exhibition;
use App\Models\Season;
use App\Models\Team;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RefreshChampionshipField extends Command
{
    protected $signature = 'football:refresh-championship-field {season : Season ID} {--world= : Required world ID} {--force : Skip confirmation}';
    protected $description = 'Refresh presentation only for an existing championship game';

    public function handle(): int
    {
        $worldId = filter_var($this->option('world'), FILTER_VALIDATE_INT);
        $seasonId = filter_var($this->argument('season'), FILTER_VALIDATE_INT);
        if (!$worldId || !$seasonId || $worldId < 1 || $seasonId < 1) {
            $this->error('Provide season ID and --world=ID.');
            return self::FAILURE;
        }
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = (int) $worldId;
            $season = Season::findOrFail($seasonId);
            $bracket = $season->settings['playoff_bracket'] ?? [];
            $gameId = null;
            foreach ($bracket['rounds'] ?? [] as $round) {
                if (!empty($round['championship'][0]['exhibition_id'])) {
                    $gameId = (int) $round['championship'][0]['exhibition_id'];
                }
            }
            if (!$gameId) {
                $this->error('This season has no started championship game.');
                return self::FAILURE;
            }
            $game = Exhibition::findOrFail($gameId);
            if ((int) ($game->state['playoff']['season_id'] ?? 0) !== (int) $seasonId ||
                ($game->state['playoff']['conference'] ?? '') !== 'championship' || $game->seasonFixture()->exists()) {
                throw new RuntimeException('Saved game does not match the championship bracket.');
            }
            $this->warn('This changes ONLY the saved game field appearance, including how old replays LOOK. Plays, scores, teams and history are untouched.');
            if (!$this->option('force') && !$this->confirm('Refresh championship artwork and venue?', false)) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
            DB::transaction(function () use ($seasonId, $gameId) {
                $season = Season::whereKey($seasonId)->lockForUpdate()->firstOrFail();
                $game = Exhibition::whereKey($gameId)->lockForUpdate()->firstOrFail();
                $state = $game->state;
                $appearance = $state['team_appearance'] ?? [];
                if (!isset($appearance['home'], $appearance['away'])) throw new RuntimeException('Missing saved appearance.');
                $settings = $season->settings;
                $venue = Team::find((int) ($settings['championship_venue_team_id'] ?? 0)) ?: Team::findOrFail($game->home_team_id);
                $builder = app(\App\Http\Controllers\PracticeController::class);
                $venueAppearance = $builder->appearance($venue, 'home');
                foreach (['stadium','stadium_style','stadium_seat_color','stadium_wall_color','stadium_roof_color'] as $key) {
                    $appearance['home'][$key] = $venueAppearance[$key];
                }
                foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $side => $teamId) {
                    $team = Team::findOrFail($teamId);
                    $normal = $builder->appearance($team, $side);
                    $design = $team->team_settings['championship_endzone'] ?? [];
                    foreach (['endzone_text','endzone_background','endzone_text_color','endzone_transparent'] as $key) {
                        $appearance[$side][$key] = $normal[$key];
                    }
                    if (!empty($design['enabled'])) {
                        $appearance[$side]['endzone_text'] = $design['text'] ?: $normal['endzone_text'];
                        $appearance[$side]['endzone_background'] = $design['background'] ?: $normal['endzone_background'];
                        $appearance[$side]['endzone_text_color'] = $design['text_color'] ?: $normal['endzone_text_color'];
                        $appearance[$side]['endzone_transparent'] = (bool) ($design['transparent'] ?? false);
                        $appearance[$side]['championship_endzone_art'] = !empty($design['art_left'])
                            ? route('teams.championship-art', ['team' => $team, 'side' => 'left']) : null;
                    } else {
                        $appearance[$side]['championship_endzone_art'] = $normal['endzone_logo_left'] ?: $normal['endzone_logo_right'];
                    }
                }
                $league = $season->league;
                $appearance['home']['midfield_logo'] = $league && $league->logo_path ? route('leagues.logo', $league) : null;
                $appearance['home']['championship_field'] = true;
                $state['team_appearance'] = $appearance;
                $game->update(['state' => $state]);
            }, 3);
            $this->info('Championship visual snapshot refreshed. Reload the game.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Refresh failed: '.$e->getMessage());
            return self::FAILURE;
        } finally {
            $context->id = $previous;
        }
    }
}
