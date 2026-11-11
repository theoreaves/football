<?php

use App\Services\Arcade\ArcadeContext;
use App\Services\Arcade\ArcadeResultContract;
use App\Services\Arcade\ArcadeSession;
use App\Services\Arcade\FieldPosition;

function arcadeContext(array $replace = []): ArcadeContext
{
    $state = ['status' => 'playing', 'phase' => 'scrimmage', 'possession' => 'home', 'quarter' => 1, 'spot' => 30, 'version' => 4, 'clock' => 120, 'home_score' => 0, 'away_score' => 0, 'stats' => ['home' => ['yards' => 7]], 'controls' => ['home' => 'human', 'away' => 'cpu']];
    $roster = ['home' => ['players' => ['RB' => ['id' => 101], 'QB' => ['id' => 102], 'WR1' => ['id' => 103]]], 'away' => ['players' => ['CB1' => ['id' => 201]]]];
    $actors = [
        'runner' => ['player_id' => 101, 'side' => 'home', 'role' => 'RB', 'x' => 40.0, 'y' => 20.0],
        'qb' => ['player_id' => 102, 'side' => 'home', 'role' => 'QB', 'x' => 40.0, 'y' => 20.0],
        'receiver' => ['player_id' => 103, 'side' => 'home', 'role' => 'WR1', 'x' => 41.0, 'y' => 20.0],
        'defender' => ['player_id' => 201, 'side' => 'away', 'role' => 'CB1', 'x' => 42.0, 'y' => 20.0],
    ];

    return new ArcadeContext(...array_replace(['gameId' => 10, 'userId' => 20, 'worldId' => 30, 'side' => 'home', 'state' => $state, 'roster' => $roster, 'actors' => $actors], $replace));
}

function arcadeRun(ArcadeSession $session, int $ticks = 6, float $step = 0.1): array
{
    $frames = [];
    for ($tick = 0; $tick <= $ticks; $tick++) {
        $actors = array_map(fn ($actor) => [$actor['x'], $actor['y']], $session->context->actors);
        $actors['runner'][0] += $tick * $step;
        $frames[] = ['tick' => $tick, 'actors' => $actors, 'ball' => [...$actors['runner'], 1.5]];
    }

    return $session->envelope() + ['evidence' => ['kind' => 'run', 'carrier' => 'runner', 'receiver' => null, 'tackler' => 'defender', 'interceptor' => null, 'release_tick' => null, 'end' => 'tackle', 'frames' => $frames]];
}

function arcadePass(ArcadeSession $session, string $ending): array
{
    $e = arcadeRun($session, 3);
    $e['evidence'] = array_replace($e['evidence'], ['kind' => 'pass', 'carrier' => 'qb', 'receiver' => $ending === 'complete' ? 'receiver' : null, 'tackler' => null, 'interceptor' => $ending === 'intercepted' ? 'defender' : null, 'release_tick' => 1, 'end' => $ending]);
    foreach ($e['evidence']['frames'] as $tick => &$frame) {
        $frame['actors']['runner'] = [40.0, 20.0];
        $frame['ball'] = [$tick <= 1 ? 40.0 : ($tick === 2 ? 40.5 : 41.0), 20.0, $ending === 'incomplete' ? max(0, 1.5 - max(0, $tick - 1) * 0.75) : 1.5];
    }

    return $e;
}

test('arcade derives a proposal with saved participant identities and no input mutation', function () {
    $context = arcadeContext();
    $before = serialize($context);
    $session = ArcadeSession::start($context, 100);
    $envelope = arcadeRun($session);
    $input = $envelope;
    $receipt = $session->accept($envelope, $context, 101);
    expect($receipt['proposal_only'])->toBeTrue()
        ->and($receipt['spot'])->toBe(31)->and($receipt['yards'])->toBe(1)
        ->and($receipt['elapsed_seconds'])->toBe(1)
        ->and($receipt['participants']['carrier']['player_id'])->toBe(101)
        ->and($receipt['participants']['tackler']['side'])->toBe('away')
        ->and($receipt)->not->toHaveKey('state')->not->toHaveKey('score')
        ->and(serialize($context))->toBe($before)->and($envelope)->toBe($input);
});

test('arcade orientation is invertible for either side in every quarter', function () {
    foreach (['home', 'away'] as $side) {
        foreach ([1, 2, 3, 4, 5, 6] as $quarter) {
            $state = ['possession' => $side, 'quarter' => $quarter];
            foreach ([0.0, 0.1, 30.5, 99.9, 100.0] as $spot) {
                expect(FieldPosition::official(FieldPosition::physical($spot, $state), $state))->toEqualWithDelta($spot, 0.000001);
            }
        }
    }
});

test('arcade rejects mismatched envelope identity versions and hashes', function (string $key, mixed $value) {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $e = arcadeRun($session);
    $e[$key] = $value;
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
})->with([
    ['user_id', 21], ['world_id', 31], ['game_id', 11], ['side', 'away'], ['state_version', 5],
    ['snapshot_hash', 'fake'], ['rules_version', 'other'], ['tuning_version', 'other'], ['schema_version', 2],
    ['session_id', 'fake'], ['result_id', 'fake'], ['expires_at', 999],
]);

test('arcade checks separately supplied trusted context including same-version lineup ownership and state', function (string $change) {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $values = get_object_vars($context);
    match ($change) {
        'userId', 'worldId', 'gameId' => $values[$change]++,
        'side' => $values['side'] = 'away',
        'version' => $values['state']['version']++,
        'clock' => $values['state']['clock']--,
        'roster' => $values['roster']['home']['players']['RB']['rating'] = 90,
        'lineup' => $values['actors']['runner']['x'] = 40.1,
        'queued' => $values['queuedOwnerId'] = 21,
        'pending' => $values['pendingDecision'] = true,
    };
    $current = new ArcadeContext(...$values);
    expect(fn () => $session->accept(arcadeRun($session), $current, 101))->toThrow(DomainException::class);
})->with(['userId', 'worldId', 'gameId', 'side', 'version', 'clock', 'roster', 'lineup', 'queued', 'pending']);

test('arcade identical retry returns receipt after state advancement and expiry but refuses changed payload or caller', function () {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $e = arcadeRun($session);
    $receipt = $session->accept($e, $context, 101);
    $state = $context->state;
    $state['version']++;
    $state['clock']--;
    $session->cancel(); // Accepted receipt is final; cancellation only affects open sessions.
    expect($session->accept($e, arcadeContext(['state' => $state]), 999))->toBe($receipt);
    $changed = $e;
    $changed['result_id'] = 'other';
    expect(fn () => $session->accept($changed, $context, 102))->toThrow(DomainException::class);
    $changed = $e;
    $changed['evidence']['frames'][1]['ball'][0] += 0.01;
    expect(fn () => $session->accept($changed, $context, 102))->toThrow(DomainException::class)
        ->and(fn () => $session->accept($e, arcadeContext(['userId' => 21]), 102))->toThrow(DomainException::class);
});

test('arcade cancelled and expired sessions cannot be revived', function (string $reason) {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $e = arcadeRun($session);
    if ($reason === 'cancel') {
        $session->cancel();
        $session->cancel();
    }
    $now = $reason === 'cancel' ? 101 : 220;
    expect(fn () => $session->accept($e, $context, $now))->toThrow(DomainException::class)
        ->and(fn () => $session->accept($e, $context, $now))->toThrow(DomainException::class)
        ->and($context->state['clock'])->toBe(120);
})->with(['cancel', 'expiry']);

test('arcade rejects pending and unsupported server states before starting', function (string $field) {
    $context = arcadeContext();
    $state = $context->state;
    match ($field) {
        'phase' => $state['phase'] = 'kickoff',
        'status' => $state['status'] = 'final',
        'controls' => $state['controls']['home'] = 'cpu',
        'penalty' => $state['penalty_pending'] = true,
        'coin' => $state['coin_toss']['pending'] = true,
        'overtime' => $state['overtime']['toss']['call_pending'] = true,
    };
    expect(fn () => ArcadeSession::start(arcadeContext(['state' => $state]), 100))->toThrow(DomainException::class);
})->with(['phase', 'status', 'controls', 'penalty', 'coin', 'overtime']);

test('arcade rejects forged actor identity role and duplicates', function (string $change) {
    $actors = arcadeContext()->actors;
    match ($change) {
        'id' => $actors['runner']['player_id'] = 999,
        'role' => $actors['runner']['role'] = 'QB',
        'side' => $actors['runner']['side'] = 'away',
        'duplicate' => $actors['duplicate'] = $actors['runner'],
    };
    expect(fn () => ArcadeSession::start(arcadeContext(['actors' => $actors]), 100))->toThrow(DomainException::class);
})->with(['id', 'role', 'side', 'duplicate']);

test('arcade rejects impossible or malformed submitted evidence', function (string $change) {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $e = arcadeRun($session);
    match ($change) {
        'score' => $e['score'] = 7,
        'gain' => $e['evidence']['yards'] = 99,
        'state' => $e['evidence']['state'] = [],
        'kind' => $e['evidence']['kind'] = 'kick',
        'carrier' => $e['evidence']['carrier'] = 'defender',
        'tackler' => $e['evidence']['tackler'] = 'runner',
        'unknown' => $e['evidence']['tackler'] = 'ghost',
        'contact' => $e['evidence']['frames'][6]['actors']['defender'] = [50, 20],
        'teleport' => $e['evidence']['frames'][2]['actors']['runner'] = [70, 20],
        'ball' => $e['evidence']['frames'][2]['ball'] = [40.2, 20.5, 1.5],
        'tick' => $e['evidence']['frames'][2]['tick'] = 5,
        'coordinate' => $e['evidence']['frames'][2]['actors']['runner'][0] = 121,
        'string' => $e['evidence']['frames'][2]['actors']['runner'][0] = '40.2',
        'missing' => $e['evidence']['frames'][2]['actors'] = [],
        'size' => $e['evidence']['frames'] = array_fill(0, ArcadeResultContract::MAX_TICKS + 2, $e['evidence']['frames'][0]),
    };
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
})->with(['score', 'gain', 'state', 'kind', 'carrier', 'tackler', 'unknown', 'contact', 'teleport', 'ball', 'tick', 'coordinate', 'string', 'missing', 'size']);

test('arcade finite coordinate validator rejects nan and infinity', function (float $value) {
    expect(fn () => ArcadeResultContract::position([$value, 20]))->toThrow(DomainException::class);
})->with([NAN, INF, -INF]);

test('arcade converts elapsed ticks using ceil exactly once and never changes official clock', function (int $ticks, int $seconds) {
    $context = arcadeContext();
    $actors = $context->actors;
    $actors['defender']['x'] = 41.0;
    $context = arcadeContext(['actors' => $actors]);
    $session = ArcadeSession::start($context, 100);
    $e = arcadeRun($session, $ticks, 0);
    $receipt = $session->accept($e, $context, 101);
    expect($receipt['elapsed_seconds'])->toBe($seconds)->and($context->state['clock'])->toBe(120)
        ->and($session->accept($e, $context, 102)['elapsed_seconds'])->toBe($seconds);
})->with([[1, 1], [59, 1], [60, 1], [61, 2], [120, 2], [1800, 30]]);

test('arcade detects goal and safety continuously before integer rounding for both directions', function (string $side, int $quarter, float $start, float $finish, string $outcome, int $spot) {
    $base = arcadeContext();
    $state = array_replace($base->state, ['possession' => $side, 'quarter' => $quarter, 'spot' => $start, 'controls' => ['home' => 'human', 'away' => 'human']]);
    $roster = $base->roster;
    $actors = $base->actors;
    if ($side === 'away') {
        $roster = ['home' => $base->roster['away'], 'away' => $base->roster['home']];
        foreach ($actors as &$a) {
            $a['side'] = $a['side'] === 'home' ? 'away' : 'home';
        }
        unset($a);
    }
    $x = FieldPosition::physical($start, $state);
    foreach ($actors as &$a) {
        $a['x'] = $x;
    }
    unset($a);
    $context = arcadeContext(['state' => $state, 'side' => $side, 'roster' => $roster, 'actors' => $actors]);
    $session = ArcadeSession::start($context, 100);
    $e = arcadeRun($session, 1, FieldPosition::physical($finish, $state) - $x);
    if (in_array($outcome, ['touchdown', 'safety'])) {
        $e['evidence']['end'] = 'crossing';
        $e['evidence']['tackler'] = null;
    }
    $receipt = $session->accept($e, $context, 101);
    expect($receipt['outcome'])->toBe($outcome)->and($receipt['spot'])->toBe($spot);
})->with([
    ['home', 1, 99.8, 99.9, 'tackle', 99], ['home', 1, 99.9, 100.0, 'touchdown', 100],
    ['home', 2, 99.8, 99.9, 'tackle', 99], ['home', 2, 99.9, 100.0, 'touchdown', 100],
    ['away', 1, 0.2, 0.1, 'tackle', 1], ['away', 1, 0.1, 0.0, 'safety', 0],
    ['away', 2, 0.2, 0.1, 'tackle', 1], ['away', 2, 0.1, 0.0, 'safety', 0],
]);

test('arcade accepts bounded complete intercepted and incomplete pass proposals with the relevant identities', function (string $end) {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $receipt = $session->accept(arcadePass($session, $end), $context, 101);
    expect($receipt['outcome'])->toBe($end)->and($receipt['participants']['carrier']['role'])->toBe('QB');
    if ($end === 'intercepted') {
        expect($receipt['participants']['interceptor']['player_id'])->toBe(201);
    }
})->with(['complete', 'intercepted', 'incomplete']);

test('arcade completion needs contact and passes reject returns', function () {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $e = arcadePass($session, 'complete');
    $e['evidence']['frames'][3]['ball'][0] = 43;
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
    $e = arcadePass($session, 'intercepted');
    $e['evidence']['end'] = 'return';
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
});

test('arcade domain has no persistence engine resolver or official clock/stat mutation dependency', function () {
    foreach (['ArcadeContext', 'ArcadeSession', 'ArcadeResultContract', 'FieldPosition'] as $class) {
        $source = file_get_contents(__DIR__.'/../../app/Services/Arcade/'.$class.'.php');
        expect($source)->not->toContain('ExhibitionEngine', 'DB::', 'App\\Models', '->save(', '->update(');
    }
});

test('arcade rejects forward passes released beyond scrimmage and backward or lateral flights', function (string $change) {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $e = arcadePass($session, 'incomplete');
    if ($change === 'release') {
        $e['evidence']['frames'][1]['actors']['qb'][0] = 40.1;
        $e['evidence']['frames'][1]['ball'][0] = 40.1;
    } else {
        $e['evidence']['frames'][2]['ball'][0] = $change === 'backward' ? 39.5 : 40.0;
        $e['evidence']['frames'][3]['ball'][0] = $change === 'backward' ? 39.0 : 40.0;
    }
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
})->with(['release', 'backward', 'lateral']);

test('arcade preserves the exact original spot on incompletion', function () {
    $state = arcadeContext()->state;
    $state['spot'] = 30.4;
    $context = arcadeContext(['state' => $state]);
    $session = ArcadeSession::start($context, 100);
    $receipt = $session->accept(arcadePass($session, 'incomplete'), $context, 101);
    expect($receipt['spot'])->toBe(30.4)->and($receipt['yards'])->toBe(0.0);
});

test('arcade expired attempts cannot be resurrected by a regressed caller clock', function () {
    $context = arcadeContext();
    $session = ArcadeSession::start($context, 100);
    $e = arcadeRun($session);
    expect(fn () => $session->accept($e, $context, 220))->toThrow(DomainException::class)
        ->and(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
});

test('arcade rejects ambiguous corner crossing and continuation past a goal or sideline', function (string $change) {
    $base = arcadeContext();
    $actors = $base->actors;
    $state = $base->state;
    $state['spot'] = 99.9;
    foreach ($actors as &$actor) {
        $actor['x'] = 109.9;
        $actor['y'] = 0.1;
    }
    unset($actor);
    $context = arcadeContext(['state' => $state, 'actors' => $actors]);
    $session = ArcadeSession::start($context, 100);
    $e = arcadeRun($session, $change === 'corner' ? 1 : 2, 0.1);
    $e['evidence']['end'] = 'crossing';
    $e['evidence']['tackler'] = null;
    if ($change !== 'goal') {
        $e['evidence']['frames'][1]['actors']['runner'][1] = 0;
        $e['evidence']['frames'][1]['ball'][1] = 0;
    }
    if ($change === 'sideline') {
        foreach ($e['evidence']['frames'] as &$frame) {
            $frame['actors']['runner'][0] = 109.9;
            $frame['ball'][0] = 109.9;
        }
        unset($frame);
    }
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
})->with(['corner', 'goal', 'sideline']);

test('arcade rejects catches at the back end line and flight continuing there', function (string $change) {
    $base = arcadeContext();
    $state = $base->state;
    $state['spot'] = 99;
    $actors = $base->actors;
    foreach ($actors as &$actor) {
        $actor['x'] = 109.0;
    }
    unset($actor);
    $actors['receiver']['x'] = 120.0;
    $context = arcadeContext(['state' => $state, 'actors' => $actors]);
    $session = ArcadeSession::start($context, 100);
    $e = arcadePass($session, 'complete');
    $e['evidence']['frames'] = [];
    $last = $change === 'catch' ? 12 : 13;
    for ($tick = 0; $tick <= $last; $tick++) {
        $e['evidence']['frames'][] = ['tick' => $tick, 'actors' => array_map(fn ($a) => [$a['x'], $a['y']], $actors), 'ball' => [min(120.0, 109.0 + max(0, $tick - 1)), 20.0, 1.5]];
    }
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
})->with(['catch', 'continue']);

test('arcade rejects wrong-side receivers and non-defensive tacklers', function (string $change) {
    $context = arcadeContext();
    if ($change === 'role') {
        $roster = $context->roster;
        $roster['away']['players']['QB'] = ['id' => 201];
        $actors = $context->actors;
        $actors['defender']['role'] = 'QB';
        $context = arcadeContext(['actors' => $actors, 'roster' => $roster]);
    }
    $session = ArcadeSession::start($context, 100);
    $e = $change === 'role' ? arcadeRun($session) : arcadePass($session, 'complete');
    if ($change === 'side') {
        $e['evidence']['receiver'] = 'defender';
    }
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
})->with(['role', 'side']);

test('arcade scores catches from the ball rather than the receiver center', function () {
    $base = arcadeContext();
    $state = array_replace($base->state, ['spot' => 99]);
    $actors = $base->actors;
    foreach ($actors as &$actor) {
        $actor['x'] = 109.0;
    }
    unset($actor);
    $actors['receiver']['x'] = 110.1;
    $context = arcadeContext(['state' => $state, 'actors' => $actors]);
    $session = ArcadeSession::start($context, 100);
    $e = arcadePass($session, 'complete');
    foreach ($e['evidence']['frames'] as $tick => &$frame) {
        $frame['actors'] = array_map(fn ($a) => [$a['x'], $a['y']], $actors);
        $frame['ball'] = [109.0 + max(0, $tick - 1) * 0.1, 20.0, 1.5];
    }
    unset($frame);
    $receipt = $session->accept($e, $context, 101);
    expect($receipt['outcome'])->toBe('complete')->and($receipt['spot'])->toBe(99)
        ->and($receipt['continuous_spot'])->toEqualWithDelta(99.2, 0.000001);
});

test('arcade cannot hide deferred end-zone interceptions behind a defender center inside the field', function () {
    $base = arcadeContext();
    $state = array_replace($base->state, ['spot' => 99]);
    $actors = $base->actors;
    foreach ($actors as &$actor) {
        $actor['x'] = 109.0;
    }
    unset($actor);
    $actors['defender']['x'] = 109.9;
    $context = arcadeContext(['state' => $state, 'actors' => $actors]);
    $session = ArcadeSession::start($context, 100);
    $e = arcadePass($session, 'intercepted');
    foreach ($e['evidence']['frames'] as $tick => &$frame) {
        $frame['actors'] = array_map(fn ($a) => [$a['x'], $a['y']], $actors);
        $frame['ball'] = [109.0 + max(0, $tick - 1) * 0.55, 20.0, 1.5];
    }
    unset($frame);
    expect(fn () => $session->accept($e, $context, 101))->toThrow(DomainException::class);
});

test('arcade rejected evidence does not consume the result ID or mutate state before a corrected retry', function () {
    $context = arcadeContext();
    $before = serialize($context);
    $session = ArcadeSession::start($context, 100);
    $valid = arcadeRun($session);
    $invalid = $valid;
    $invalid['evidence']['tackler'] = 'ghost';
    expect(fn () => $session->accept($invalid, $context, 101))->toThrow(DomainException::class)
        ->and(serialize($context))->toBe($before);
    $receipt = $session->accept($valid, $context, 102);
    expect($receipt['result_id'])->toBe($invalid['result_id'])
        ->and($receipt['outcome'])->toBe('tackle')
        ->and(serialize($context))->toBe($before);
});
