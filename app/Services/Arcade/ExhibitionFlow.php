<?php

namespace App\Services\Arcade;

use App\Http\Controllers\ExhibitionController;
use App\Models\ArcadeFlowReceipt;
use App\Models\ArcadeGameFlow;
use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\TimeoutRules;
use App\Support\ExhibitionHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Coordinates existing football actions, never resolves an interactive play twice. */
class ExhibitionFlow
{
    public function fingerprint(Exhibition $game): string
    {
        return ArcadeContext::exhibitionHash($game);
    }

    public function status(Exhibition $game): array
    {
        $meta = ArcadeGameFlow::where('exhibition_id', $game->id)->first();
        $s = $game->state;
        $human = $meta?->human_side;
        $required = ($s['penalty_pending'] ?? false) ? 'penalty' : (($s['coin_toss']['pending'] ?? false) ? 'coin' : (($s['overtime']['toss']['call_pending'] ?? false) ? 'ot_call' : (($s['overtime']['toss']['pending'] ?? false) ? 'ot_choice' : null)));
        $final = ($s['status'] ?? '') !== 'playing';
        $offense = $s['possession'] === $human;
        $special = ($s['phase'] ?? '') !== 'scrimmage';
        if (! $required && ! $final && $offense && $special) {
            $required = 'special';
        }
        $active = ArcadePlaySession::where('active_slot', $game->id)->where('expires_at', '>', now())->exists();
        $advance = $meta && ! $final && ! $required && ! $offense && ! $active;
        $coach = route('exhibitions.show', ['exhibition' => $game, 'arcade' => 1]);
        $arcade = route('exhibitions.arcade.show', $game);
        $watch = route('exhibitions.show', ['exhibition' => $game, 'arcade' => 1, 'watch' => 1]);
        $last = ExhibitionHistory::last($game);
        $notices = [];
        if ($s['penalty_pending'] ?? false) {
            // The finalizer's accepted result is a preview until the human decides.
            $notices[] = ['type' => 'penalty', 'message' => 'Penalty awaiting decision. Accept or decline before the next play.'];
        } elseif ($last) {
            $notices[] = ['type' => 'result', 'message' => $last['summary'] ?? 'Play completed.'];
            $before = $last['before'] ?? [];
            $after = $last['after'] ?? $s;
            if (($before['quarter'] ?? null) !== ($after['quarter'] ?? null)) {
                $notices[] = ['type' => 'quarter', 'message' => 'Quarter changed. Check the game clock and field direction.'];
            }
            if (($before['possession'] ?? null) !== ($after['possession'] ?? null)) {
                $notices[] = ['type' => 'possession', 'message' => 'Possession changed.'];
            }
            if (isset($before['quarter'], $before['possession'], $after['quarter'], $after['possession']) && \App\Services\Simulation\FieldOrientation::direction($before) !== \App\Services\Simulation\FieldOrientation::direction($after)) {
                $notices[] = ['type' => 'direction', 'message' => 'The offense now attacks the opposite end of the field.'];
            }
            if (isset($last['nullified_summary'])) {
                $notices[] = ['type' => 'nullified', 'message' => 'Play nullified by the accepted penalty. The official down and distance include enforcement.'];
            }
            if (isset($last['penalty'])) {
                $notices[] = ['type' => 'penalty', 'message' => $last['penalty']['summary'] ?? 'Penalty recorded. See the official play result and enforcement.'];
            }
        }
        unset($last);
        $flow = ['enabled' => (bool) $meta, 'revision' => $meta?->revision ?? 0, 'state_hash' => $this->fingerprint($game), 'human_side' => $human,
            'defense_mode' => $meta?->defense_mode ?? 'coach', 'phase' => $final ? 'final' : ($required ? 'decision' : ($offense ? 'offense' : ($special ? 'special' : 'defense'))),
            'required_decision' => $required, 'can_advance' => (bool) $advance, 'can_fast' => (bool) $advance,
            'can_spike' => (bool) ($meta && ! $active && ! $final && ! $required && $offense && in_array('spike', ExhibitionEngine::callsForState($s), true)),
            'can_timeout' => (bool) ($meta && ! $active && app(TimeoutRules::class)->canCall($s, $human)), 'timeout_team' => $human,
            'intro_seen' => $meta?->intro_seen ?? true, 'opening_enabled' => $meta?->opening_enabled ?? false,
            'coach_url' => $coach, 'arcade_url' => $arcade, 'watch_url' => $watch, 'notices' => $notices];

        return ['state' => $s, 'available' => app(ExhibitionSessions::class)->available($game), 'flow' => $flow, 'coach_url' => $coach, 'watch_url' => $watch];
    }

    public function execute(Exhibition $game, Request $request): array
    {
        $sessions = app(ExhibitionSessions::class);
        $sessions->authorize($game, $request->user()->id);
        // Only whitelisted ordinary Coach inputs cross the boundary; local dev force flags never do.
        $data = $request->except(['_token']);
        $hash = ArcadeContext::hash($data);

        return DB::transaction(function () use ($game, $request, $sessions, $data, $hash) {
            if (DB::getDriverName() === 'sqlite') {
                Exhibition::whereKey($game->id)->toBase()->update(['id' => DB::raw('id')]);
            }
            $sessions->authorize($game, $request->user()->id, true);
            $game = Exhibition::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $receipt = ArcadeFlowReceipt::where('exhibition_id', $game->id)->where('command_id', $data['command_id'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This command was already used with different inputs.');

                return $this->response($game, $receipt->result, true);
            }
            $status = $this->status($game);
            $flow = $status['flow'];
            abort_unless((int) $data['version'] === $game->state['version'] && (int) $data['flow_revision'] === $flow['revision'] && hash_equals($flow['state_hash'], $data['state_hash']), 409, 'The game changed. Reload before continuing.');
            $active = ArcadePlaySession::where('active_slot', $game->id)->lockForUpdate()->first();
            if ($active && $active->expires_at->isPast()) {
                $active->update(['status' => 'expired', 'active_slot' => null]);
                $active = null;
            }
            abort_if($active && $data['action'] !== 'intro_seen', 409, 'Finish or cancel the reserved Arcade play first.');
            $meta = ArcadeGameFlow::where('exhibition_id', $game->id)->first();
            $action = $data['action'];
            $legacyBefore = ExhibitionHistory::isList($game) ? null : iterator_to_array(ExhibitionHistory::entries($game));
            $beforeCount = $legacyBefore === null ? ExhibitionHistory::count($game) : count($legacyBefore);
            $lastIndex = $beforeCount > 0 ? $beforeCount - 1 : null;
            $beforeLast = $action === 'penalty' || ($flow['required_decision'] === 'penalty' && ! $meta)
                ? ExhibitionHistory::last($game) : null;
            $penaltyBeneficiary = $beforeLast['penalty']['beneficiary'] ?? null;
            if ($action === 'preferences') {
                abort_unless(isset($data['defense_mode']), 422, 'Choose a defense mode.');
                $human = $meta?->human_side ?? ($data['human_side'] ?? null);
                abort_unless(in_array($human, ['home', 'away'], true), 422, 'Choose your team.');
                abort_if(isset($data['human_side']) && $human !== $data['human_side'], 409, 'The player team cannot change during a game.');
                if (! $meta) {
                    $pendingOwner = match ($flow['required_decision']) {
                        'coin' => $game->state['coin_toss']['winner'] ?? null,
                        'ot_call' => 'away',
                        'ot_choice' => $game->state['overtime']['toss']['winner'] ?? null,
                        'penalty' => $penaltyBeneficiary,
                        default => null,
                    };
                    $controls = app(CpuCoach::class)->controls($game->state);
                    $other = $human === 'home' ? 'away' : 'home';
                    abort_if($pendingOwner !== null && ($controls[$human] !== 'human' || $controls[$other] !== 'cpu'), 409, 'Finish the pending Coach decision before changing team control.');
                }
                $meta ??= ArcadeGameFlow::create(['exhibition_id' => $game->id, 'human_side' => $human, 'defense_mode' => $data['defense_mode']]);
                $meta->defense_mode = $data['defense_mode'];
                $state = $game->state;
                $state['controls'] = [$human => 'human', ($human === 'home' ? 'away' : 'home') => 'cpu'];
                $game->update(['state' => $state]);
            } else {
                abort_unless($meta, 409, 'Choose your team and defense mode first.');
                if ($action === 'intro_seen') {
                    $meta->intro_seen = true;
                } elseif (in_array($action, ['advance', 'fast'], true)) {
                    abort_unless($flow['can_advance'], 409, 'A player decision is required.');
                    if ($action === 'fast') {
                        $meta->defense_mode = 'fast';
                    }
                    for ($i = 0; $i < 512; $i++) {
                        if (! $this->cpuBoundary($game->state, $meta->human_side)) {
                            break;
                        }
                        $coach = app(CpuCoach::class);
                        $cpuTimeout = $coach->timeoutTeam($game->state);
                        if ($cpuTimeout !== null && $cpuTimeout !== $meta->human_side) {
                            $args = ['action' => 'cpu_timeout'];
                        } else {
                            $plan = $coach->offense($game->state, app(\App\Services\Simulation\GamePersonnel::class)->active($game->rosters, $game->state));
                            $defense = $coach->defense($game->state, $plan['call']);
                            $args = ['action' => 'play', 'defense' => $defense['call'], 'defense_formation' => $defense['formation'], 'expect' => $game->state['distance'] >= 8 ? 'pass' : ($game->state['distance'] <= 2 ? 'run' : 'balanced')];
                        }
                        $this->coachAction($game, $request, $args);
                        if ($action === 'advance') {
                            break;
                        }
                    }
                } else {
                    $args = $request->only(['action', 'call', 'defense', 'offense_formation', 'defense_formation', 'tempo', 'clock_strategy', 'timeout_team', 'timeout_timing', 'decision', 'choice', 'toss_call', 'team', 'role', 'player', 'expect', 'blitz', 'motion', 'one_play', 'team_play']);
                    foreach (['one_play', 'team_play'] as $field) {
                        if (isset($args[$field])) {
                            abort_unless(is_array($args[$field]) && ! array_diff(array_keys($args[$field]), ['home', 'away']), 422, 'Only your team personnel may be selected.');
                            $other = $meta->human_side === 'home' ? 'away' : 'home';
                            if (array_key_exists($other, $args[$field])) {
                                $value = $args[$field][$other];
                                // Shared Coach forms include both teams' Automatic defaults. They
                                // confer no selection authority and must not block a human call.
                                $empty = $value === null || $value === '';
                                if ($field === 'one_play' && is_array($value)) {
                                    $empty = ! array_diff(array_keys($value), array_keys(\App\Services\Simulation\RosterBuilder::GROUPS))
                                        && ! array_filter($value, fn ($id) => $id !== null && $id !== '');
                                }
                                abort_unless($empty, 422, 'Only your team personnel may be selected.');
                                unset($args[$field][$other]);
                            }
                        }
                    }
                    if ($action === 'spike') {
                        abort_unless($flow['can_spike'], 409, 'Spike is unavailable.');
                        $args = ['action' => 'play', 'call' => 'spike'];
                    }
                    if ($action === 'timeout') {
                        abort_unless($flow['can_timeout'], 409, 'Timeout is unavailable.');
                        $args['timeout_team'] = $meta->human_side;
                    }
                    if ($action === 'cpu_timeout') {
                        abort_unless(app(CpuCoach::class)->timeoutTeam($game->state) !== null && app(CpuCoach::class)->timeoutTeam($game->state) !== $meta->human_side, 409, 'No CPU timeout is due.');
                    }
                    if ($action === 'lineup') {
                        abort_unless(($args['team'] ?? null) === $meta->human_side, 422, 'Only your lineup can be changed.');
                    }
                    if ($action === 'play' && $game->state['possession'] === $meta->human_side && $game->state['phase'] === 'scrimmage') {
                        abort_unless(in_array($args['call'] ?? null, ['spike', 'kneel', 'punt', 'punt_distance', 'punt_hang', 'punt_pooch', 'punt_coffin', 'field_goal', 'fake_punt_run', 'fake_punt_pass', 'fake_field_goal_run', 'fake_field_goal_pass'], true), 422, 'Run and pass plays must be played in Arcade.');
                    }
                    $this->coachAction($game, $request, $args);
                    if ($action === 'coin') {
                        $meta->intro_seen = true;
                    }
                }
            }
            $meta->revision++;
            $meta->save();
            // Coach actions append history; only a penalty decision replaces its last play.
            $afterCount = ExhibitionHistory::count($game);
            $indices = [];
            if ($legacyBefore !== null) {
                foreach (ExhibitionHistory::entries($game) as $index => $play) {
                    if (! isset($legacyBefore[$index]) || $legacyBefore[$index] !== $play) {
                        $indices[] = $index;
                    }
                }
            } else {
                if ($action === 'penalty' && $lastIndex !== null && $beforeLast !== ExhibitionHistory::at($game, $lastIndex)) {
                    $indices[] = $lastIndex;
                }
                if ($afterCount > $beforeCount) {
                    $indices = array_merge($indices, range($beforeCount, $afterCount - 1));
                }
            }
            unset($beforeLast, $legacyBefore, $play);
            $result = ['version' => $game->state['version'], 'revision' => $meta->revision, 'indices' => $indices, 'watch' => $action === 'advance' || ($action === 'play' && count($indices) > 0), 'command_id' => $data['command_id']];
            ArcadeFlowReceipt::create(['exhibition_id' => $game->id, 'command_id' => $data['command_id'], 'request_hash' => $hash, 'result' => $result]);

            return $this->response($game, $result, false);
        }, 3);
    }

    private function cpuBoundary(array $state, string $human): bool
    {
        return ($state['status'] ?? '') === 'playing' && $state['possession'] !== $human
            && ! ($state['penalty_pending'] ?? false) && ! ($state['coin_toss']['pending'] ?? false)
            && ! app(\App\Services\Simulation\Overtime::class)->pending($state);
    }

    private function coachAction(Exhibition $game, Request $original, array $args): void
    {
        $request = Request::create('/', 'POST', $args + ['version' => $game->state['version']]);
        $request->attributes->set('arcade_flow_dispatch', true);
        $request->setUserResolver($original->getUserResolver());
        if ($original->hasSession()) {
            $request->setLaravelSession($original->session());
        }
        app(ExhibitionController::class)->play($request, $game, app(ExhibitionEngine::class));
        $game->refresh();
    }

    private function response(Exhibition $game, array $receipt, bool $replayed): array
    {
        $status = $this->status($game);
        $flow = $status['flow'];
        $plays = array_values(ExhibitionHistory::select($game, $receipt['indices']));
        $showAnimation = $receipt['watch'] && (! $replayed || (($receipt['version'] ?? -1) === $game->state['version'] && ($receipt['revision'] ?? -1) === $flow['revision']));
        $url = $showAnimation ? $flow['watch_url'] : (($flow['phase'] === 'offense' || $flow['phase'] === 'final') ? $flow['arcade_url'] : $flow['coach_url']);

        return $status + ['plays' => $plays, 'play' => $plays ? end($plays) : null, 'receipt' => $receipt, 'replayed' => $replayed, 'navigate_url' => $url, 'navigation_url' => $url];
    }
}
