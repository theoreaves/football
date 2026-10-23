<?php

namespace App\Services\Seasons;

use App\Jobs\DispatchCpuPlayoffRound;
use App\Models\Exhibition;
use App\Models\Season;
use App\Services\Simulation\QuickSimulator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueuedPlayoffGames
{
    public function entries(Season $season): array
    {
        $bracket = $season->settings['playoff_bracket'] ?? [];
        $round = (int) ($bracket['current_round'] ?? 1);
        $groups = $round === 1 ? ($bracket['conferences'] ?? []) : ($bracket['rounds'][$round] ?? []);
        $entries = [];
        foreach ($groups as $conference => $division) {
            $games = $round === 1 ? ($division['games'] ?? []) : $division;
            foreach ($games as $index => $game) {
                $entries[] = ['conference' => (string) $conference, 'index' => (int) $index, 'round' => $round, 'game' => $game];
            }
        }
        return $entries;
    }

    public function eligible(Season $season): array
    {
        $members = $season->settings['members'] ?? [];
        return array_values(array_filter($this->entries($season), static function (array $entry) use ($members): bool {
            $game = $entry['game'];
            return (
                    (empty($game['exhibition_id']) && ($game['status'] ?? 'scheduled') === 'scheduled')
                    || (($game['simulation_status'] ?? null) === 'failed' && ($game['status'] ?? '') !== 'final')
                )
                && in_array($game['simulation_status'] ?? null, [null, 'failed'], true)
                && ($members[$game['home_team_id']]['control'] ?? 'human') === 'cpu'
                && ($members[$game['away_team_id']]['control'] ?? 'human') === 'cpu';
        }));
    }

    public function queue(Season $season, int $round): int
    {
        return DB::transaction(function () use ($season, $round) {
            $season = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            $settings = $season->settings;
            $bracket = $settings['playoff_bracket'] ?? null;
            abort_unless(is_array($bracket) && in_array($season->phase, ['playoffs_pending', 'playoffs'], true)
                && (int) ($bracket['current_round'] ?? 1) === $round, 409, 'The playoff round changed. Reload.');
            $tasks = [];
            foreach ($this->eligible($season) as $entry) {
                $token = (string) Str::uuid();
                $conference = $entry['conference'];
                $index = $entry['index'];
                $game = $entry['game'];
                $game['simulation_status'] = 'queued';
                $game['simulation_token'] = $token;
                $this->set($bracket, $round, $conference, $index, $game);
                $tasks[] = ['round' => $round, 'conference' => $conference, 'index' => $index, 'token' => $token];
            }
            if ($tasks) {
                $settings['playoff_bracket'] = $bracket;
                $season->update(['settings' => $settings]);
                DispatchCpuPlayoffRound::dispatch((int) $season->world_id, (int) $season->id, $tasks)
                    ->onConnection('database')->beforeCommit();
            }
            return count($tasks);
        }, 3);
    }

    public function run(int $seasonId, int $round, string $conference, int $index, string $token): void
    {
        // Claim under the short season lock; simulation runs without a lock.
        $game = DB::transaction(function () use ($seasonId, $round, $conference, $index, $token) {
            $season = Season::whereKey($seasonId)->lockForUpdate()->first();
            if (!$season || !in_array($season->phase, ['playoffs_pending', 'playoffs'], true)) return null;
            $settings = $season->settings;
            $bracket = $settings['playoff_bracket'] ?? [];
            if ((int) ($bracket['current_round'] ?? 1) !== $round) return null;
            $entry = $this->get($bracket, $round, $conference, $index);
            if (!$entry || ($entry['simulation_token'] ?? null) !== $token ||
                !in_array($entry['simulation_status'] ?? null, ['queued', 'running'], true)) return null;
            $members = $settings['members'];
            $cpu = ($members[$entry['home_team_id']]['control'] ?? 'human') === 'cpu' &&
                ($members[$entry['away_team_id']]['control'] ?? 'human') === 'cpu';
            if (!$cpu || ($entry['status'] ?? '') === 'final') {
                unset($entry['simulation_token'], $entry['simulation_status']);
                $this->set($bracket, $round, $conference, $index, $entry);
                $settings['playoff_bracket'] = $bracket;
                $season->update(['settings' => $settings]);
                return null;
            }
            // Reuse the immutable initial game on retries; never start a new game.
            $existingId = $entry['exhibition_id'] ?? null;
            if ($existingId) {
                $game = Exhibition::findOrFail($existingId);
                abort_unless((int) ($game->state['playoff']['season_id'] ?? 0) === $seasonId &&
                    (int) ($game->state['playoff']['round'] ?? 0) === $round, 409);
            } else {
                // PlayoffGames::start takes the same season lock inside a nested transaction.
                $game = app(PlayoffGames::class)->start($season, $conference, $index, false, $token);
                $season->refresh();
                $settings = $season->settings;
                $bracket = $settings['playoff_bracket'];
                $entry = $this->get($bracket, $round, $conference, $index);
            }
            $entry['simulation_status'] = 'running';
            $this->set($bracket, $round, $conference, $index, $entry);
            $settings['playoff_bracket'] = $bracket;
            $season->update(['settings' => $settings]);
            return $game;
        }, 3);
        if (!$game) return;
        if (($game->state['status'] ?? null) === 'final') {
            $this->finish($seasonId, $round, $conference, $index, $token, null);
            return;
        }
        $result = app(QuickSimulator::class)->run($game->state, $game->rosters, $game->history);
        $result['state']['controls'] = $game->state['controls'];
        $this->finish($seasonId, $round, $conference, $index, $token, $result);
    }

    private function finish(int $seasonId, int $round, string $conference, int $index, string $token, ?array $result): void
    {
        DB::transaction(function () use ($seasonId, $round, $conference, $index, $token, $result) {
            $season = Season::whereKey($seasonId)->lockForUpdate()->firstOrFail();
            $settings = $season->settings;
            $bracket = $settings['playoff_bracket'] ?? [];
            $entry = $this->get($bracket, $round, $conference, $index);
            if (!$entry || ($entry['simulation_token'] ?? null) !== $token) return;
            $game = Exhibition::whereKey($entry['exhibition_id'])->lockForUpdate()->firstOrFail();
            if ($result && ($game->state['status'] ?? null) !== 'final') $game->update($result);
            $state = $game->state;
            abort_unless(($state['status'] ?? null) === 'final' && !($state['penalty_pending'] ?? false), 409);
            $home = (int) $state['home_score'];
            $away = (int) $state['away_score'];
            abort_unless($home !== $away, 409, 'Playoff games may not end tied.');
            $entry['status'] = 'final';
            $entry['home_score'] = $home;
            $entry['away_score'] = $away;
            $entry['winner_team_id'] = (int) $entry[$home > $away ? 'home_team_id' : 'away_team_id'];
            unset($entry['simulation_token'], $entry['simulation_status']);
            $this->set($bracket, $round, $conference, $index, $entry);
            $settings['playoff_bracket'] = $bracket;
            $season->update(['settings' => $settings]);
        }, 3);
    }

    public function mark(int $seasonId, int $round, string $conference, int $index, string $token, string $status): void
    {
        DB::transaction(function () use ($seasonId, $round, $conference, $index, $token, $status) {
            $season = Season::whereKey($seasonId)->lockForUpdate()->first();
            if (!$season) return;
            $settings = $season->settings;
            $bracket = $settings['playoff_bracket'] ?? [];
            $entry = $this->get($bracket, $round, $conference, $index);
            if (!$entry || ($entry['simulation_token'] ?? null) !== $token || ($entry['status'] ?? '') === 'final') return;
            $entry['simulation_status'] = $status;
            $this->set($bracket, $round, $conference, $index, $entry);
            $settings['playoff_bracket'] = $bracket;
            $season->update(['settings' => $settings]);
        }, 3);
    }

    private function get(array $bracket, int $round, string $conference, int $index): ?array
    {
        return $round === 1 ? ($bracket['conferences'][$conference]['games'][$index] ?? null)
            : ($bracket['rounds'][$round][$conference][$index] ?? null);
    }

    private function set(array &$bracket, int $round, string $conference, int $index, array $game): void
    {
        if ($round === 1) $bracket['conferences'][$conference]['games'][$index] = $game;
        else $bracket['rounds'][$round][$conference][$index] = $game;
    }
}
