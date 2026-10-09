<?php

namespace App\Services\Seasons;

use App\Models\Exhibition;
use App\Models\Season;
use App\Models\SeasonFixture;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\QuickSimulator;
use App\Services\Simulation\RosterBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SeasonGames
{
    public function start(Season $season, SeasonFixture $fixture, bool $quick): Exhibition
    {
        return DB::transaction(function () use ($season, $fixture, $quick) {
            $season = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            $fixture = $season->fixtures()->whereKey($fixture->id)->lockForUpdate()->firstOrFail();
            if ($fixture->exhibition_id) {
                return $fixture->exhibition()->firstOrFail();
            }
            abort_unless($season->phase === 'regular_season' && (int) $fixture->week === (int) $season->current_week, 409, 'Play games in the current season week.');
            $builder = app(RosterBuilder::class);
            $rosters = $controls = [];
            foreach (['home', 'away'] as $side) {
                $team = $side === 'home' ? $fixture->homeTeam : $fixture->awayTeam;
                $rosters[$side] = $builder->build($team, (string) $season->year, $season->settings['depth_charts'][$team->id] ?? []);
                $controls[$side] = $season->settings['members'][$team->id]['control'];
            }
            $state = app(ExhibitionEngine::class)->initial($season->settings['quarter_length'] ?? 900);
            $coin = random_int(0, 1) ? 'heads' : 'tails';
            $winner = $coin === 'heads' ? 'away' : 'home';
            $pending = ! $quick && $controls[$winner] === 'human';
            $choice = $pending ? null : app(CpuCoach::class)->coinChoice($state);
            $receiver = $choice === 'kick' ? ($winner === 'home' ? 'away' : 'home') : $winner;
            $state = array_merge($state, ['controls' => $controls, 'opening_receiver' => $receiver,
                'possession' => $receiver === 'home' ? 'away' : 'home',
                'coin_toss' => ['call' => 'heads', 'result' => $coin, 'winner' => $winner, 'choice' => $choice, 'pending' => $pending],
                'rules' => ['overtime' => 'modern', 'penalties' => true, 'injuries' => true],
                'crowd' => ['fullness' => 80, 'visitors' => 10, 'seed' => random_int(1, 2147483647)]]);
            $game = Exhibition::create(['home_team_id' => $fixture->home_team_id, 'away_team_id' => $fixture->away_team_id,
                'rosters' => $rosters, 'state' => $state, 'history' => []]);
            $fixture->update(['exhibition_id' => $game->id, 'status' => 'playing']);
            if ($quick) {
                $result = app(QuickSimulator::class)->run($state, $rosters);
                // The CPU simulates this game; retain the configured owners for its summary.
                $result['state']['controls'] = $controls;
                $game->update($result);
                $this->record($game);
            }

            return $game;
        }, 3);
    }

    public function simCpuWeek(Season $season, int $week): int
    {
        return DB::transaction(function () use ($season, $week) {
            $season = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            abort_unless($season->phase === 'regular_season' && (int) $season->current_week === $week, 409, 'Reload the current season week before simulating.');
            $count = 0;
            foreach ($season->fixtures()->where('week', $week)->whereNull('exhibition_id')->where('status', 'scheduled')->orderBy('id')->get() as $fixture) {
                $members = $season->settings['members'];
                if (($members[$fixture->home_team_id]['control'] ?? 'human') !== 'cpu' || ($members[$fixture->away_team_id]['control'] ?? 'human') !== 'cpu') {
                    continue;
                }
                $this->start($season, $fixture, true);
                $count++;
            }

            return $count;
        }, 3);
    }

    public function record(Exhibition $game): void
    {
        if ($game->state['status'] !== 'final' || ($game->state['penalty_pending'] ?? false)) {
            return;
        }
        SeasonFixture::where('exhibition_id', $game->id)->where('status', '!=', 'final')->update([
            'status' => 'final', 'home_score' => $game->state['home_score'], 'away_score' => $game->state['away_score'],
        ]);
    }

    public function advance(Season $season, int $week): void
    {
        DB::transaction(function () use ($season, $week) {
            $season = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            abort_unless($season->phase === 'regular_season' && (int) $season->current_week === $week, 409, 'This week was already advanced. Reload the season.');
            $games = $season->fixtures()->where('week', $week)->get();
            if ($games->isEmpty() || $games->contains(fn ($game) => $game->status !== 'final')) {
                throw ValidationException::withMessages(['week' => 'Finish every game in this week before advancing.']);
            }
            if ($season->fixtures()->where('week', '>', $week)->exists()) {
                $season->update(['current_week' => $week + 1]);
            } else {
                $season->update(['phase' => $season->settings['playoffs'] === 'none' ? 'completed' : 'playoffs_pending']);
            }
        }, 3);
    }

    public function standings(Season $season): array
    {
        $rows = [];
        foreach ($season->settings['members'] as $id => $member) {
            $rows[$id] = ['wins' => 0, 'losses' => 0, 'ties' => 0, 'points_for' => 0, 'points_against' => 0, 'pct' => 0];
        }
        foreach ($season->fixtures()->where('status', 'final')->get() as $game) {
            foreach (['home', 'away'] as $side) {
                $other = $side === 'home' ? 'away' : 'home';
                $id = $game->{$side.'_team_id'};
                $score = $game->{$side.'_score'};
                $against = $game->{$other.'_score'};
                $rows[$id][$score === $against ? 'ties' : ($score > $against ? 'wins' : 'losses')]++;
                $rows[$id]['points_for'] += $score;
                $rows[$id]['points_against'] += $against;
            }
        }
        foreach ($rows as &$row) {
            $games = $row['wins'] + $row['losses'] + $row['ties'];
            $row['pct'] = $games ? ($row['wins'] + .5 * $row['ties']) / $games : 0;
        }

        return $rows;
    }
}
