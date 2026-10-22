<?php

namespace App\Services\Seasons;

use App\Jobs\DispatchCpuWeek;
use App\Models\Exhibition;
use App\Models\Season;
use App\Models\SeasonFixture;
use App\Services\Simulation\QuickSimulator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueuedSeasonGames
{
    public function eligible(Season $season)
    {
        $cpu = array_keys(array_filter($season->settings['members'], fn ($member) => ($member['control'] ?? 'human') === 'cpu'));

        return $season->fixtures()->where('week', $season->current_week)
            ->whereIn('home_team_id', $cpu)->whereIn('away_team_id', $cpu)
            ->where(function ($query) {
                $query->where(fn ($q) => $q->where('status', 'scheduled')->whereNull('exhibition_id')->whereNull('simulation_status'))
                    ->orWhere(fn ($q) => $q->where('simulation_status', 'failed')->where('status', '!=', 'final'));
            });
    }

    public function queue(Season $season, int $week): int
    {
        return DB::transaction(function () use ($season, $week) {
            $season = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            abort_unless($season->phase === 'regular_season' && (int) $season->current_week === $week, 409, 'Reload the current season week before simulating.');
            $tasks = [];
            foreach ($this->eligible($season)->lockForUpdate()->get() as $fixture) {
                $token = (string) Str::uuid();
                $fixture->update(['simulation_status' => 'queued', 'simulation_token' => $token]);
                $tasks[] = ['fixture' => (int) $fixture->id, 'token' => $token];
            }
            if ($tasks) {
                // The database queue insert shares this transaction on the normal
                // app connection, so an enqueue failure cannot strand the fixtures.
                DispatchCpuWeek::dispatch((int) $season->world_id, (int) $season->id, $tasks)->onConnection('database')->beforeCommit();
            }

            return count($tasks);
        }, 3);
    }

    public function run(int $seasonId, int $fixtureId, string $token): void
    {
        // Only setup/finalization hold database locks. Simulation is CPU work and
        // must not lock the season for other workers or the human-controlled game.
        $game = DB::transaction(function () use ($seasonId, $fixtureId, $token) {
            $season = Season::whereKey($seasonId)->lockForUpdate()->first();
            if (! $season) {
                return null;
            }
            $fixture = $season->fixtures()->whereKey($fixtureId)->lockForUpdate()->first();
            if (! $fixture || $fixture->simulation_token !== $token || ! in_array($fixture->simulation_status, ['queued', 'running'], true)) {
                return null;
            }
            $cpu = collect([$fixture->home_team_id, $fixture->away_team_id])->every(fn ($id) => ($season->settings['members'][$id]['control'] ?? 'human') === 'cpu');
            if ($fixture->status === 'final' || ! $cpu || $season->phase !== 'regular_season' || (int) $season->current_week !== (int) $fixture->week) {
                $fixture->update(['simulation_status' => null, 'simulation_token' => null]);

                return null;
            }
            $fixture->update(['simulation_status' => 'running']);

            // On retry this returns the original initial state and roster snapshot.
            return app(SeasonGames::class)->start($season, $fixture, false, $token);
        }, 3);
        if (! $game) {
            return;
        }
        $result = app(QuickSimulator::class)->run($game->state, $game->rosters, $game->history);
        DB::transaction(function () use ($fixtureId, $token, $game, $result) {
            $fixture = SeasonFixture::whereKey($fixtureId)->lockForUpdate()->first();
            if (! $fixture || $fixture->simulation_token !== $token || $fixture->status === 'final') {
                return;
            }
            $locked = Exhibition::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $locked->update($result);
            app(SeasonGames::class)->record($locked);
            $fixture->refresh()->update(['simulation_status' => null, 'simulation_token' => null]);
        }, 3);
    }

    public function mark(int $fixtureId, string $token, string $status): void
    {
        SeasonFixture::whereKey($fixtureId)->where('simulation_token', $token)->where('status', '!=', 'final')
            ->whereIn('simulation_status', ['queued', 'running'])->update(['simulation_status' => $status]);
    }
}
