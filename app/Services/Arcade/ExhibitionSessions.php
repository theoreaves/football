<?php

namespace App\Services\Arcade;

use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Models\World;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\FieldOrientation;
use App\Services\Simulation\GamePersonnel;
use App\Services\Simulation\HurryUp;
use App\Services\Simulation\Overtime;
use App\Services\Simulation\PlayFinalizer;
use App\Services\Simulation\PreSnapPolicy;
use App\Services\Simulation\ScrimmageAccounting;
use App\Support\CurrentWorld;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExhibitionSessions
{
    public function authorize(Exhibition $game, int $userId, bool $lock = false): void
    {
        abort_unless(config('arcade.enabled'), 404);
        abort_unless((int) $game->world_id === app(CurrentWorld::class)->id, 404);
        $world = World::query()->whereKey($game->world_id);
        if ($lock) {
            $world->lockForUpdate();
        }
        abort_unless((int) $world->firstOrFail()->owner_user_id === $userId, 403);
        abort_if($game->seasonFixture()->exists() || isset($game->state['playoff']) || isset($game->state['season_id']), 409, 'Arcade is available only for standalone exhibitions.');
    }

    public function available(Exhibition $game): bool
    {
        $s = $game->state;

        return ($s['status'] ?? null) === 'playing' && ($s['phase'] ?? null) === 'scrimmage'
            && app(CpuCoach::class)->controls($s)[$s['possession']] === 'human'
            && ! ($s['penalty_pending'] ?? false) && ! ($s['coin_toss']['pending'] ?? false)
            && ! app(Overtime::class)->pending($s);
    }

    public function status(Exhibition $game): array
    {
        return app(ExhibitionFlow::class)->status($game);
    }

    public function start(Exhibition $game, int $userId, array $data): array
    {
        return $this->locked($game, $userId, function (Exhibition $game) use ($userId, $data): array {
            abort_unless($this->available($game), 409, 'Continue this decision or phase in Coach mode.');
            abort_unless($game->state['version'] === (int) $data['version'], 409, 'The game has changed. Reload before starting.');
            $active = ArcadePlaySession::where('active_slot', $game->id)->lockForUpdate()->first();
            if ($active && ($active->expires_at->isPast() || $active->snapshot_hash !== $this->fingerprint($game))) {
                $active->update(['status' => $active->expires_at->isPast() ? 'expired' : 'stale', 'active_slot' => null]);
                $active = null;
            }
            if ($active) {
                abort_unless((int) $active->user_id === $userId && $active->snapshot['kind'] === $data['kind'] && $active->snapshot['tempo'] === $data['tempo'], 409, 'An Arcade play is already reserved.');

                return $this->status($game) + ['session' => $this->publicSession($active)];
            }
            $call = $data['kind'] === 'run' ? 'inside_run' : 'short_pass';
            $state = $game->state;
            $state['controls'] = app(CpuCoach::class)->controls($state);
            $offenseFormation = 'shotgun';
            $defenseFormation = 'base_4_3';
            $state = HurryUp::prepare($state, $data['tempo'], $call, $offenseFormation, $defenseFormation);
            $rosters = app(GamePersonnel::class)->active($game->rosters, $state, $call, $offenseFormation);
            $prepared = app(PreSnapPolicy::class)->prepare($state, $rosters, $data['tempo']);
            if ($prepared['result'] !== null) {
                $this->saveResult($game, $prepared['result']);

                return $this->status($game) + ['session' => null, 'play' => $prepared['result']['play']];
            }
            $state = $prepared['state'];
            $setup = $this->setup($game, $state, $rosters, $data['kind']);
            $context = new ArcadeContext($game->id, $userId, $game->world_id, $state['possession'], $state, $rosters, $setup['actors']);
            $context->assertPlayable();
            $id = (string) Str::uuid();
            $expires = now()->addSeconds((int) config('arcade.session_ttl', 180));
            $binding = $context->binding() + ['contract_version' => 'live-recording-1', 'session_id' => $id, 'result_id' => (string) Str::uuid(), 'expires_at' => $expires->timestamp,
                'setup_hash' => ArcadeContext::hash($setup)];
            $session = ArcadePlaySession::create(['id' => $id, 'result_id' => $binding['result_id'], 'exhibition_id' => $game->id,
                'world_id' => $game->world_id, 'user_id' => $userId, 'active_slot' => $game->id, 'status' => 'active',
                'snapshot_hash' => $this->fingerprint($game), 'binding' => $binding, 'expires_at' => $expires,
                'snapshot' => ['state' => $state, 'rosters' => $rosters, 'kind' => $data['kind'], 'tempo' => $data['tempo'], 'offense_formation' => $offenseFormation, 'defense_formation' => $defenseFormation], 'setup' => $setup]);

            return $this->status($game) + ['session' => $this->publicSession($session)];
        });
    }

    public function submit(Exhibition $game, int $userId, string $sessionId, array $data): array
    {
        return $this->locked($game, $userId, function (Exhibition $game) use ($userId, $sessionId, $data): array {
            $session = $this->session($game, $userId, $sessionId);
            $hash = ArcadeContext::hash($data);
            if ($session->status === 'accepted') {
                abort_unless(hash_equals($session->accepted_hash, $hash), 409, 'This result was already submitted with different evidence.');

                return array_replace($this->status($game), ['available' => $this->available($game) && $game->state['possession'] === $session->snapshot['state']['possession']]) + ['receipt' => $session->receipt, 'recording' => $session->recording, 'play' => $session->receipt['play']];
            }
            abort_unless($session->status === 'active', 409, 'This Arcade session is no longer active.');
            if ($session->expires_at->timestamp <= now()->timestamp) {
                $session->update(['status' => 'expired', 'active_slot' => null]);

                return ['error' => 410, 'message' => 'This Arcade session has expired.'];
            }
            if (! hash_equals($session->snapshot_hash, $this->fingerprint($game)) || ! $this->available($game)) {
                $session->update(['status' => 'stale', 'active_slot' => null]);

                return ['error' => 409, 'message' => 'The game changed while this play was active.'];
            }
            abort_unless(ArcadeContext::hash($data['envelope']) === ArcadeContext::hash($session->binding), 422, 'Session binding does not match.');
            $snap = $session->snapshot;
            $context = new ArcadeContext($game->id, $userId, $game->world_id, $snap['state']['possession'], $snap['state'], $snap['rosters'], $session->setup['actors']);
            $context->assertPlayable();
            abort_unless(($data['recording']['seed'] ?? null) === $session->setup['seed']
                && ($data['recording']['routeSet'] ?? null) === ($snap['kind'] === 'pass' ? $session->setup['routeSet'] : null)
                && ($data['recording']['speedScale'] ?? null) === $session->setup['speedScale'], 422, 'Recording setup does not match the reservation.');
            $resolved = app(LivePlayContract::class)->derive($context, $data['recording']);
            abort_unless(($resolved['kind'] ?? ($resolved['call'] === 'inside_run' ? 'run' : 'pass')) === $snap['kind'], 422, 'Recording play kind does not match.');
            $accounted = app(ScrimmageAccounting::class)->apply($snap['state'], $snap['state'], $resolved['outcome'], $resolved['gain'], $resolved['flip'], $resolved['summary']);
            $play = $resolved;
            unset($play['recording'], $play['animation'], $play['elapsed_seconds']);
            $play['outcome'] = $accounted['outcome'];
            $play['summary'] = $accounted['summary'];
            $play['arcade_recording'] = $resolved['recording'];
            $play += ['defense' => 'man_to_man', 'offense_formation' => $snap['offense_formation'], 'defense_formation' => $snap['defense_formation'], 'target' => 0];
            $result = app(PlayFinalizer::class)->finish($accounted['state'], $snap['state'], $play, $snap['rosters'], $resolved['elapsed_seconds'], $resolved['animation']);
            $this->saveResult($game, $result);
            $receipt = ['result_id' => $session->result_id, 'session_id' => $session->id, 'version' => $game->state['version'], 'play' => $result['play']];
            $session->update(['status' => 'accepted', 'active_slot' => null, 'accepted_hash' => $hash, 'receipt' => $receipt, 'recording' => $resolved['recording']]);

            return array_replace($this->status($game), ['available' => $this->available($game) && $game->state['possession'] === $session->snapshot['state']['possession']]) + ['receipt' => $receipt, 'recording' => $resolved['recording'], 'play' => $result['play']];
        });
    }

    public function cancel(Exhibition $game, int $userId, string $sessionId): array
    {
        return $this->locked($game, $userId, function (Exhibition $game) use ($userId, $sessionId): array {
            $session = $this->session($game, $userId, $sessionId);
            if ($session->status === 'active') {
                $session->update(['status' => 'cancelled', 'active_slot' => null]);
            }

            return $this->status($game) + ['cancelled' => $session->status === 'cancelled', 'session_status' => $session->status, 'receipt' => $session->receipt];
        });
    }

    private function locked(Exhibition $game, int $userId, callable $callback): array
    {
        try {
            $result = DB::transaction(function () use ($game, $userId, $callback): array {
                // SQLite has no SELECT FOR UPDATE. Acquire its writer lock before reading any mutable snapshot.
                if (DB::connection()->getDriverName() === 'sqlite') {
                    Exhibition::whereKey($game->id)->toBase()->update(['id' => DB::raw('id')]);
                }
                $locked = Exhibition::whereKey($game->id)->lockForUpdate()->firstOrFail();
                $this->authorize($locked, $userId, true);

                return $callback($locked);
            }, 5);
        } catch (DomainException $e) {
            abort(422, $e->getMessage());
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['40001', '40P01', '23000'], true) || str_contains($e->getMessage(), 'database is locked')) {
                abort(409, 'Another request is updating this game. Retry the same request.');
            }
            throw $e;
        }
        if (isset($result['error'])) {
            abort($result['error'], $result['message']);
        }

        return $result;
    }

    private function session(Exhibition $game, int $userId, string $id): ArcadePlaySession
    {
        return ArcadePlaySession::whereKey($id)->where('exhibition_id', $game->id)->where('user_id', $userId)->where('world_id', $game->world_id)->lockForUpdate()->firstOrFail();
    }

    private function fingerprint(Exhibition $game): string
    {
        return ArcadeContext::hash([$game->state, $game->rosters, $game->history, $game->home_team_id, $game->away_team_id, $game->world_id]);
    }

    private function saveResult(Exhibition $game, array $result): void
    {
        $history = $game->history;
        $history[] = $result['play'];
        $game->update(['state' => $result['state'], 'history' => $history, 'box_score_cache' => null]);
    }

    public function publicSession(ArcadePlaySession $session): array
    {
        return ['envelope' => $session->binding, 'setup' => $session->setup, 'prepared_state' => $session->snapshot['state'],
            'submit_url' => route('exhibitions.arcade.submit', ['exhibition' => $session->exhibition_id, 'session' => $session->id]),
            'cancel_url' => route('exhibitions.arcade.cancel', ['exhibition' => $session->exhibition_id, 'session' => $session->id])];
    }

    private function setup(Exhibition $game, array $state, array $rosters, string $kind): array
    {
        $side = $state['possession'];
        $other = $side === 'home' ? 'away' : 'home';
        $direction = FieldOrientation::direction($state);
        $line = FieldPosition::physical($state['spot'], $state);
        $center = 53.33 / 2;
        $actors = [];
        $add = function (string $key, string $side, string $role, float $x, float $y) use (&$actors, $rosters): array {
            $player = $rosters[$side]['players'][$role] ?? null;
            abort_unless($player && $player['id'] > 0 && empty($player['emergency']), 409, 'This active lineup needs real players for Arcade. Continue in Coach mode.');
            $actors[str_replace(':', '_', $key)] = ['replay_key' => $key, 'player_id' => $player['id'], 'side' => $side, 'role' => $role, 'x' => $x, 'y' => $y];

            return $player + ['side' => $side];
        };
        // Official recording contract v1 pins approved pacing independently of configurable local drills.
        $setup = ['kind' => $kind, 'mode' => $kind === 'run' ? 'running' : 'passing', 'direction' => $direction,
            'seed' => LivePlaySeed::forState($state), 'speedScale' => .925, 'defenderCount' => 2, 'routeSet' => 'slants',
            'appearance' => $game->state['team_appearance'] ?? [],
            'exhibition' => ['startX' => $line, 'goalX' => FieldPosition::physical(100, $state), 'rearX' => FieldPosition::physical(0, $state), 'firstDownX' => FieldPosition::physical(min(100, $state['spot'] + $state['distance']), $state)]];
        if ($kind === 'run') {
            $setup['runner'] = $add('runner', $side, 'RB', $line, $center);
        } else {
            $setup['quarterback'] = $add('qb', $side, 'QB', max(0, min(120, $line - 3 * $direction)), $center);
            $setup['receivers'] = [$add('receiver:0', $side, 'WR1', max(10, min(110, $line + $direction)), $center - 8),
                $add('receiver:1', $side, 'WR2', max(10, min(110, $line + $direction)), $center + 8)];
        }
        foreach (($kind === 'run' ? ['LB1', 'CB1'] : ['CB1', 'CB2']) as $index => $role) {
            $setup['defenders'][] = $add('defender:'.$index, $other, $role,
                max(10, min(110, $line + $direction * ($kind === 'run' ? 10 + 8 * $index : 5 + $index))),
                $center + ($kind === 'run' ? ($index ? 6 : 0) : ($index ? 8 : -8)));
        }
        $setup['actors'] = $actors;

        return $setup;
    }
}
