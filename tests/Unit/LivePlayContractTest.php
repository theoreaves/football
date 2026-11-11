<?php

use App\Services\Arcade\ArcadeContext;
use App\Services\Arcade\LivePlayContract;
use Symfony\Component\Process\Process;

function liveRecordings(): array
{
    static $fixtures;
    if ($fixtures === null) {
        $process = new Process(['node', 'tests/JavaScript/fixtures/live-recordings.mjs'], dirname(__DIR__, 2));
        $process->mustRun();
        $fixtures = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    return $fixtures;
}

function liveContext(array $fixture): ArcadeContext
{
    return new ArcadeContext(10, 20, 30, 'home', $fixture['state'], $fixture['roster'], $fixture['actors']);
}

test('actual JavaScript gameplay recordings derive identities and preserve every recorded movement in both directions', function () {
    $covered = [];
    foreach (liveRecordings() as $fixture) {
        try {
            $result = (new LivePlayContract)->derive(liveContext($fixture), $fixture['recording']);
        } catch (Throwable $e) {
            throw new RuntimeException($fixture['name'].': '.$e->getMessage(), previous: $e);
        }
        $covered[$result['outcome']] = true;
        expect($result['recording']['frames'])->toBe($fixture['recording']['frames'])
            ->and($result['recording']['events'])->toBe($fixture['recording']['events'])
            ->and($result['animation']['players'][0]['id'])->toBe($fixture['recording']['actors'][0]['id']);
        foreach ($fixture['recording']['frames'] as $i => $frame) {
            expect($result['animation']['players'][0]['path'][$i])->toBe([$frame[1] / 1000000, $frame[3][0][0] / 100, 0, $frame[3][0][1] / 100]);
        }
        if ($result['outcome'] === 'touchdown') {
            expect($result['gain'])->toBe(100 - $fixture['state']['spot'])
                ->and($result['participants']['receiver']['player_id'])->toBe(103);
        }
    }
    expect(array_keys($covered))->toContain('touchdown', 'tackle', 'safety', 'sack', 'incomplete');
});

test('live recording replaces client identity and appearance from the trusted roster', function () {
    $fixture = liveRecordings()[0];
    $fixture['recording']['actors'][0]['id'] = 999;
    $fixture['recording']['actors'][0]['name'] = 'Forged';
    $fixture['recording']['actors'][0]['appearance'] = ['skin' => 'forged'];
    $result = (new LivePlayContract)->derive(liveContext($fixture), $fixture['recording']);
    expect($result['recording']['actors'][0]['id'])->toBe(101)->and($result['recording']['actors'][0]['name'])->toBe('RB')
        ->and($result['recording']['actors'][0])->not->toHaveKey('appearance');
});

test('live validation rejects invalid timing movement ownership configuration and terminal evidence', function (string $mutation) {
    $fixture = liveRecordings()[0];
    $r = $fixture['recording'];
    match ($mutation) {
        'gap' => array_splice($r['frames'], 1, 2),
        'teleport' => $r['frames'][1][3][0][0] += 1000,
        'ownership' => $r['frames'][1][4][4] = 1,
        'speed' => $r['speedScale'] = 1.1,
        'timeout' => $r['result']['reason'] = 'timeout',
        'truncated' => $r['truncated'] = true,
        'tackler' => $r['events'][count($r['events']) - 2][4] = 0,
        'no_finish' => array_pop($r['events']),
    };
    expect(fn () => (new LivePlayContract)->derive(liveContext($fixture), $r))->toThrow(DomainException::class);
})->with(['gap', 'teleport', 'ownership', 'speed', 'timeout', 'truncated', 'tackler', 'no_finish']);

test('a defensive end zone interception is a touchback proposal rather than an offensive touchdown', function () {
    $fixture = collect(liveRecordings())->first(fn ($f) => $f['recording']['result']['reason'] === 'interception');
    $r = $fixture['recording'];
    $last = end($r['frames']);
    $index = $last[4][4];
    $shift = 111 - $last[3][$index][0] / 100;
    $fixture['state']['spot'] += $shift;
    foreach ($fixture['actors'] as &$actor) {
        $actor['x'] += $shift;
    } unset($actor);
    $r['field']['startX'] += $shift;
    foreach ($r['frames'] as &$frame) {
        foreach ($frame[3] as &$player) {
            $player[0] += (int) round($shift * 100);
        } unset($player);
        $frame[4][0] += (int) round($shift * 100);
    } unset($frame);
    foreach ($r['events'] as &$event) {
        foreach (['target', 'actualTarget', 'contact'] as $key) {
            if (isset($event[5][$key])) {
                $event[5][$key][0] += (int) round($shift * 100);
            }
        }
        foreach (['position', 'contactExact'] as $key) {
            if (isset($event[5][$key])) {
                $event[5][$key][0] += $shift;
            }
        }
    } unset($event);
    $result = (new LivePlayContract)->derive(liveContext($fixture), $r);
    expect($result['outcome'])->toBe('interception')->and($result['flip'])->toBeTrue()->and($result['spot'])->toBe(100)
        ->and($result['participants']['interceptor']['player_id'])->toBe(201);
});

test('completed passes cannot rewrite possession or add a second throw during actual YAC', function (string $mutation) {
    $fixture = collect(liveRecordings())->first(fn ($f) => $f['recording']['result']['reason'] === 'touchdown' && $f['state']['spot'] === 30);
    $r = $fixture['recording'];
    if ($mutation === 'holder') {
        $r['frames'][count($r['frames']) - 4][4][4] = 0;
    } elseif ($mutation === 'control') {
        $r['frames'][count($r['frames']) - 4][2] = 0;
    } elseif ($mutation === 'flight') {
        $r['frames'][count($r['frames']) - 4][4][3] = 1;
        $r['frames'][count($r['frames']) - 4][4][4] = -1;
    } else {
        foreach ($r['events'] as &$event) {
            if ($event[2] === 'throw') {
                $event[5]['actualTarget'][0] = 1;
            }
        } unset($event);
    }
    expect(fn () => (new LivePlayContract)->derive(liveContext($fixture), $r))->toThrow(DomainException::class);
})->with(['holder', 'control', 'flight', 'backward']);

test('Arcade box score credits the actual saved defender and leaves Coach heuristic intact', function () {
    $roster = ['home' => ['players' => ['QB' => ['id' => 1, 'name' => 'QB'], 'WR1' => ['id' => 2, 'name' => 'WR']]], 'away' => ['players' => ['CB1' => ['id' => 3, 'name' => 'CB'], 'LB2' => ['id' => 4, 'name' => 'LB'], 'S1' => ['id' => 5, 'name' => 'Safety']]]];
    $state = ['quarter' => 1, 'possession' => 'home', 'clock' => 900, 'phase' => 'scrimmage', 'home_score' => 0, 'away_score' => 0, 'down' => 1, 'distance' => 10, 'stats' => ['home' => ['turnovers' => 0], 'away' => ['turnovers' => 0]]];
    $base = ['before' => $state, 'after' => $state, 'call' => 'short_pass', 'carrier' => 'WR1', 'gain' => 5, 'outcome' => 'tackle', 'summary' => 'Tackle', 'animation' => ['players' => [['id' => 5, 'role' => 'S1', 'side' => 'away']]]];
    $tackle = $base + ['source' => 'arcade', 'arcade_participants' => ['tackler' => ['role' => 'S1', 'player_id' => 5]]];
    $interception = array_replace($base, ['source' => 'arcade', 'carrier' => '', 'outcome' => 'interception', 'gain' => 0, 'arcade_participants' => ['interceptor' => ['role' => 'S1', 'player_id' => 5]]]);
    $game = new App\Models\Exhibition;
    $game->state = $state;
    $game->rosters = $roster;
    $game->history = [$tackle, $interception, $base];
    $box = app(App\Services\Simulation\ExhibitionBoxScore::class)->build($game);
    expect($box['players']['away']['S1']['tackles'])->toBe(1)->and($box['players']['away']['S1']['defensive_interceptions'])->toBe(1)
        ->and($box['players']['away']['CB1']['tackles'])->toBe(1)->and($box['players']['away']['CB1']['defensive_interceptions'])->toBe(0);
});

test('catch contact cannot be fabricated outside the field or away from the actual receiver', function (string $mutation) {
    $fixture = collect(liveRecordings())->first(fn ($f) => $f['recording']['result']['reason'] === 'touchdown' && $f['state']['spot'] === 30);
    $r = $fixture['recording'];
    foreach ($r['events'] as &$event) {
        if ($event[2] === 'catch') {
            if ($mutation === 'missing') {
                unset($event[5]['contact']);
            } elseif ($mutation === 'backline') {
                $event[5]['contact'][0] = 12000;
            } elseif ($mutation === 'height') {
                $event[5]['contact'][1] = 900;
            } else {
                $event[5]['contact'][2] = 1;
            }
        }
    } unset($event);
    expect(fn () => (new LivePlayContract)->derive(liveContext($fixture), $r))->toThrow(DomainException::class);
})->with(['missing', 'backline', 'height', 'distant']);

test('a recorded quarterback sack safety credits sacks to the actual defender and no rushing attempt', function () {
    $fixture = collect(liveRecordings())->first(fn ($f) => $f['recording']['mode'] === 'passing' && $f['recording']['result']['reason'] === 'safety');
    $play = (new LivePlayContract)->derive(liveContext($fixture), $fixture['recording']);
    $state = $fixture['state'] + ['home_score' => 0, 'away_score' => 0, 'down' => 1, 'distance' => 10, 'stats' => ['home' => ['turnovers' => 0], 'away' => ['turnovers' => 0]]];
    $after = array_replace($state, ['away_score' => 2]);
    $play['before'] = $state;
    $play['after'] = $after;
    $game = new App\Models\Exhibition;
    $game->state = $after;
    $game->rosters = $fixture['roster'];
    $game->history = [$play];
    $box = app(App\Services\Simulation\ExhibitionBoxScore::class)->build($game);
    $role = $play['arcade_participants']['tackler']['role'];
    expect($play['scramble'])->toBeFalse()->and($play['carrier'])->toBe('QB')
        ->and($box['players']['home']['QB']['rushes'])->toBe(0)->and($box['players']['home']['QB']['sacks'])->toBe(1)
        ->and($box['players']['away'][$role]['defensive_sacks'])->toBe(1)->and($box['players']['away'][$role]['tackles'])->toBe(1)
        ->and($box['quarters']['away'][1])->toBe(2);
});

test('validated replay retains saved team uniforms instead of client appearance', function () {
    $fixture = liveRecordings()[0];
    $fixture['state']['team_appearance'] = ['home' => ['uniform' => ['jersey_color' => '#123456']], 'away' => ['uniform' => ['jersey_color' => '#abcdef']]];
    $fixture['recording']['appearance'] = ['home' => ['uniform' => ['jersey_color' => '#000000']]];
    $result = (new LivePlayContract)->derive(liveContext($fixture), $fixture['recording']);
    expect($result['recording']['appearance'])->toBe($fixture['state']['team_appearance']);
});

test('centiyard quantization permits only the adjacent goal sample before a genuine touchdown', function () {
    $fixture = collect(liveRecordings())->first(fn ($f) => $f['recording']['result']['reason'] === 'touchdown' && $f['state']['spot'] === 30);
    $r = $fixture['recording'];
    $i = count($r['frames']) - 2;
    $carrier = $r['frames'][$i][2];
    $r['frames'][$i][3][$carrier][0] = 11000;
    $r['frames'][$i][4][0] = 11000;
    expect((new LivePlayContract)->derive(liveContext($fixture), $r)['outcome'])->toBe('touchdown');
    $r['frames'][$i][3][$carrier][0] = 11001;
    $r['frames'][$i][4][0] = 11001;
    expect(fn () => (new LivePlayContract)->derive(liveContext($fixture), $r))->toThrow(DomainException::class);
});

test('a precise tackle just short of the goal stays non scoring despite centiyard rounding', function () {
    $fixture = collect(liveRecordings())->first(fn ($f) => $f['recording']['mode'] === 'running' && $f['state']['spot'] === 99 && $f['recording']['direction'] === 1);
    $r = $fixture['recording'];
    $shift = 109.996 - end($r['events'])[5]['position'][0];
    $fixture['state']['spot'] += $shift;
    foreach ($fixture['actors'] as &$actor) {
        $actor['x'] += $shift;
    } unset($actor);
    $r['field']['startX'] += $shift;
    foreach ($r['frames'] as &$frame) {
        foreach ($frame[3] as &$player) {
            $player[0] = (int) round($player[0] + $shift * 100);
        } unset($player);
        $frame[4][0] = (int) round($frame[4][0] + $shift * 100);
    } unset($frame);
    $r['events'][count($r['events']) - 1][5]['position'][0] = 109.996;
    $r['frames'][count($r['frames']) - 1][3][0][0] = 11000;
    $r['frames'][count($r['frames']) - 1][4][0] = 11000;
    $result = (new LivePlayContract)->derive(liveContext($fixture), $r);
    expect($result['outcome'])->toBe('tackle')->and($result['spot'])->toBe(99)->and($result['continuous_spot'])->toBe(99.996);
    $r['events'][count($r['events']) - 1][5]['position'][0] = 109.9;
    expect(fn () => (new LivePlayContract)->derive(liveContext($fixture), $r))->toThrow(DomainException::class);
});

test('a recorded dropped pass credits the identified receiver target but no reception', function () {
    $fixture = collect(liveRecordings())->first(fn ($f) => collect($f['recording']['events'])->contains(fn ($e) => $e[2] === 'incomplete' && ($e[5]['cause'] ?? null) === 'drop'));
    expect($fixture)->not->toBeNull();
    $play = (new LivePlayContract)->derive(liveContext($fixture), $fixture['recording']);
    $state = $fixture['state'] + ['home_score' => 0, 'away_score' => 0, 'down' => 1, 'distance' => 10, 'stats' => ['home' => ['turnovers' => 0], 'away' => ['turnovers' => 0]]];
    $play['before'] = $state;
    $play['after'] = $state;
    $game = new App\Models\Exhibition;
    $game->state = $state;
    $game->rosters = $fixture['roster'];
    $game->history = [$play];
    $box = app(App\Services\Simulation\ExhibitionBoxScore::class)->build($game);
    $role = $play['arcade_participants']['target']['role'];
    expect($play['carrier'])->toBe($role)->and($box['players']['home']['QB']['pass_attempts'])->toBe(1)
        ->and($box['players']['home']['QB']['completions'])->toBe(0)->and($box['players']['home'][$role]['targets'])->toBe(1)
        ->and($box['players']['home'][$role]['receptions'])->toBe(0);
});

test('a goal catch is spotted by the ball contact even when the receiver center is short', function () {
    $fixture = collect(liveRecordings())->first(fn ($f) => $f['state']['spot'] === 96 && $f['recording']['direction'] === 1);
    $r = $fixture['recording'];
    $receiver = end($r['frames'])[2];
    $key = str_replace(':', '_', $r['actors'][$receiver]['key']);
    $fixture['actors'][$key]['x'] -= .6;
    $fixture['actors'][$key]['y'] += .5;
    foreach ($r['frames'] as &$frame) {
        $frame[3][$receiver][0] -= 60;
        $frame[3][$receiver][1] += 50;
        if ($frame[4][4] === $receiver) {
            $frame[4][0] -= 60;
            $frame[4][2] += 50;
        }
    } unset($frame);
    $r['events'][count($r['events']) - 1][5]['position'][0] -= .6;
    $r['events'][count($r['events']) - 1][5]['position'][1] += .5;
    expect(end($r['frames'])[3][$receiver][0])->toBeLessThan(11000);
    $result = (new LivePlayContract)->derive(liveContext($fixture), $r);
    expect($result['outcome'])->toBe('touchdown')->and($result['spot'])->toBe(100);
});

test('non list JSON tuple objects and unknown indices are rejected without offset errors', function (string $tuple) {
    $fixture = liveRecordings()[0];
    $r = $fixture['recording'];
    if ($tuple === 'frame') {
        $r['frames'][1] = array_combine(['a', 'b', 'c', 'd', 'e'], $r['frames'][1]);
    } elseif ($tuple === 'player') {
        $r['frames'][1][3][0] = array_combine(range('a', 'g'), $r['frames'][1][3][0]);
    } elseif ($tuple === 'ball') {
        $r['frames'][1][4] = array_combine(range('a', 'e'), $r['frames'][1][4]);
    } elseif ($tuple === 'event') {
        $r['events'][0] = array_combine(range('a', 'f'), $r['events'][0]);
    } elseif ($tuple === 'actors') {
        $r['actors'] = ['one' => $r['actors'][0], 'two' => $r['actors'][1], 'three' => $r['actors'][2]];
    } elseif ($tuple === 'index') {
        $r['frames'][1][2] = 500;
    } else {
        $r['events'][count($r['events']) - 1][5]['position'] = ['x' => 1, 'z' => 2];
    }
    expect(fn () => (new LivePlayContract)->derive(liveContext($fixture), $r))->toThrow(DomainException::class);
})->with(['frame', 'player', 'ball', 'event', 'actors', 'index', 'position']);
