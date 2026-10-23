<?php

namespace App\Services\Seasons;

use App\Models\Exhibition;
use App\Models\Season;
use App\Models\Team;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\QuickSimulator;
use App\Services\Simulation\RosterBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Playoff games live in the saved bracket, never in regular-season fixtures. */
class PlayoffGames
{
    public function start(Season $season, string $conference, int $index, bool $quick = false, ?string $simulationToken = null): Exhibition
    {
        return DB::transaction(function () use ($season, $conference, $index, $quick, $simulationToken) {
            $season = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            $settings = $season->settings;
            $bracket = $settings['playoff_bracket'] ?? null;
            abort_unless(in_array($season->phase, ['playoffs_pending', 'playoffs'], true) && is_array($bracket), 409);
            $round = (int) ($bracket['current_round'] ?? 1);
            $games = $this->games($bracket, $conference, $round);
            abort_unless(isset($games[$index]), 404);
            $entry = $games[$index];
            abort_if(($entry['simulation_status'] ?? null) !== null && ($entry['simulation_token'] ?? null) !== $simulationToken, 409, 'This playoff game is being simulated in the background.');
            if (! empty($entry['exhibition_id'])) {
                $existing = Exhibition::findOrFail($entry['exhibition_id']);
                abort_unless((int) $existing->world_id === (int) $season->world_id, 404);
                return $existing;
            }
            abort_unless(($entry['status'] ?? 'scheduled') === 'scheduled', 409);
            $teams = Team::whereIn('id', [(int) $entry['home_team_id'], (int) $entry['away_team_id']])->get()->keyBy('id');
            abort_unless($teams->count() === 2, 422, 'A playoff team is missing.');
            $builder = app(RosterBuilder::class);
            $rosters = $controls = [];
            foreach (['home', 'away'] as $side) {
                $id = (int) $entry[$side.'_team_id'];
                $rosters[$side] = $builder->build($teams[$id], (string) $season->year, $settings['depth_charts'][$id] ?? []);
                $rosters[$side]['specialists'] = array_replace($rosters[$side]['specialists'] ?? [], $settings['specialists'][$id] ?? []);
                $rosters[$side]['team_plays'] = $settings['team_plays'][$id] ?? [];
                $controls[$side] = $settings['members'][$id]['control'] ?? 'cpu';
            }
            $state = app(ExhibitionEngine::class)->initial($settings['quarter_length'] ?? 900);
            // Record phase and bracket identity in the immutable game snapshot.
            $coin = random_int(0, 1) ? 'heads' : 'tails';
            $winner = $coin === 'heads' ? 'away' : 'home';
            $pending = ! $quick && $controls[$winner] === 'human';
            $choice = $pending ? null : app(CpuCoach::class)->coinChoice($state);
            $receiver = $choice === 'kick' ? ($winner === 'home' ? 'away' : 'home') : $winner;
            $state = array_merge($state, [
                'playoff' => ['season_id' => (int) $season->id, 'conference' => $conference, 'round' => $round, 'index' => $index],
                'coaching' => ['home' => $teams[$entry['home_team_id']]->team_settings['coaching'] ?? [],
                               'away' => $teams[$entry['away_team_id']]->team_settings['coaching'] ?? []],
                'team_appearance' => app(\App\Http\Controllers\PracticeController::class)->matchup($teams[$entry['home_team_id']], $teams[$entry['away_team_id']]),
                'controls' => $controls, 'opening_receiver' => $receiver,
                'possession' => $receiver === 'home' ? 'away' : 'home',
                'coin_toss' => ['call' => 'heads', 'result' => $coin, 'winner' => $winner, 'choice' => $choice, 'pending' => $pending],
                'rules' => ['overtime' => 'modern_playoff', 'penalties' => true, 'injuries' => true],
                'crowd' => ['fullness' => 90, 'visitors' => 10, 'seed' => random_int(1, 2147483647)],
            ]);
            $game = Exhibition::create(['home_team_id' => $entry['home_team_id'], 'away_team_id' => $entry['away_team_id'],
                'rosters' => $rosters, 'state' => $state, 'history' => []]);
            $entry['exhibition_id'] = $game->id;
            $entry['status'] = 'playing';
            $this->setGame($bracket, $conference, $round, $index, $entry);
            $settings['playoff_bracket'] = $bracket;
            $season->update(['settings' => $settings, 'phase' => 'playoffs']);
            if ($quick) {
                $result = app(QuickSimulator::class)->run($state, $rosters);
                $result['state']['controls'] = $controls;
                $game->update($result);
            }
            return $game;
        }, 3);
    }

    /** Read scores from finalized exhibitions; advancing is explicit and idempotent. */
    public function advance(Season $season): void
    {
        DB::transaction(function () use ($season) {
            $season = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            $settings = $season->settings;
            $bracket = $settings['playoff_bracket'] ?? null;
            abort_unless(is_array($bracket) && in_array($season->phase, ['playoffs_pending', 'playoffs'], true), 409);
            $round = (int) ($bracket['current_round'] ?? 1);
            $survivors = [];
            $roundGroups = $round === 1 ? $bracket['conferences'] : ($bracket['rounds'][$round] ?? []);
            foreach ($roundGroups as $conference => $division) {
                $games = $this->games($bracket, (string) $conference, $round);
                $survivors[$conference] = $round === 1 ? array_map('intval', $division['byes'] ?? []) : [];
                if (count($games) === 0) {
                    throw ValidationException::withMessages(['playoffs' => 'No games exist in this playoff round.']);
                }
                foreach ($games as $index => $entry) {
                    $game = empty($entry['exhibition_id']) ? null : Exhibition::find($entry['exhibition_id']);
                    $state = $game?->state ?? [];
                    if (! $game || ($state['status'] ?? null) !== 'final' || ($state['penalty_pending'] ?? false)) {
                        throw ValidationException::withMessages(['playoffs' => 'Finish all games in this round before advancing.']);
                    }
                    $home = (int) ($state['home_score'] ?? 0);
                    $away = (int) ($state['away_score'] ?? 0);
                    if ($home === $away) {
                        throw ValidationException::withMessages(['playoffs' => 'A postseason game cannot finish tied.']);
                    }
                    $entry['status'] = 'final';
                    $entry['home_score'] = $home;
                    $entry['away_score'] = $away;
                    $entry['winner_team_id'] = (int) $entry[$home > $away ? 'home_team_id' : 'away_team_id'];
                    $survivors[$conference][] = $entry['winner_team_id'];
                    $this->setGame($bracket, (string) $conference, $round, (int) $index, $entry);
                }
            }
            // Conference champions play a neutral-path final; saved seeds determine home side.
            $next = [];
            if (count($survivors) === 2 && count(array_unique(array_map('count', $survivors))) === 1 && count(reset($survivors)) === 1) {
                $winners = array_values(array_map(fn ($ids) => $ids[0], $survivors));
                $allSeeds = [];
                foreach ($bracket['conferences'] as $division) {
                    foreach ($division['seeds'] as $seed) {
                        $allSeeds[(int) $seed['team_id']] = (int) $seed['seed'];
                    }
                }
                usort($winners, fn ($a, $b) => ($allSeeds[$a] <=> $allSeeds[$b]) ?: ($a <=> $b));
                $next['championship'] = [$this->matchup($winners[0], $winners[1], $allSeeds)];
            } elseif (isset($survivors['championship']) && count($survivors['championship']) === 1) {
                $bracket['status'] = 'completed';
                $bracket['champion_team_id'] = $survivors['championship'][0];
                $settings['playoff_bracket'] = $bracket;
                $season->update(['settings' => $settings, 'phase' => 'completed']);
                return;
            } elseif (count($survivors) === 1 && count(reset($survivors)) === 1) {
                $bracket['status'] = 'completed';
                $bracket['champion_team_id'] = reset($survivors)[0];
                $settings['playoff_bracket'] = $bracket;
                $season->update(['settings' => $settings, 'phase' => 'completed']);
                return;
            } else {
                foreach ($survivors as $conference => $ids) {
                    $seeds = [];
                    foreach ($bracket['conferences'][$conference]['seeds'] as $entry) {
                        $seeds[(int) $entry['team_id']] = (int) $entry['seed'];
                    }
                    usort($ids, fn ($a, $b) => ($seeds[$a] <=> $seeds[$b]) ?: ($a <=> $b));
                    $games = [];
                    while (count($ids) > 1) {
                        $home = array_shift($ids);
                        $away = array_pop($ids);
                        $games[] = $this->matchup($home, $away, $seeds);
                    }
                    $next[$conference] = $games;
                }
            }
            $bracket['current_round'] = $round + 1;
            $bracket['status'] = 'round_'.$bracket['current_round'];
            $bracket['rounds'][$bracket['current_round']] = $next;
            $settings['playoff_bracket'] = $bracket;
            $season->update(['settings' => $settings, 'phase' => 'playoffs']);
        }, 3);
    }

    public function games(array $bracket, string $conference, int $round): array
    {
        return $round === 1 ? ($bracket['conferences'][$conference]['games'] ?? []) : ($bracket['rounds'][$round][$conference] ?? []);
    }

    private function setGame(array &$bracket, string $conference, int $round, int $index, array $game): void
    {
        if ($round === 1) {
            $bracket['conferences'][$conference]['games'][$index] = $game;
        } else {
            $bracket['rounds'][$round][$conference][$index] = $game;
        }
    }

    private function matchup(int $home, int $away, array $seeds): array
    {
        return ['home_team_id' => $home, 'away_team_id' => $away, 'home_seed' => $seeds[$home],
            'away_seed' => $seeds[$away], 'status' => 'scheduled'];
    }
}
