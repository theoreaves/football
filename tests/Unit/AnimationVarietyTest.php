<?php

use App\Services\Simulation\AnimationVariety;

function varietyPlay(string $family, int $count = 0): array
{
    return ['before' => ['seed' => 42, 'animation_variety_counts' => [$family => $count]],
        'scramble' => $family === 'scramble',
        'call' => $family === 'run' ? 'inside_run' : 'short_pass',
        'outcome' => $family === 'incomplete' ? 'incomplete' : 'tackle', 'carrier' => $family === 'scramble' ? 'QB' : ($family === 'run' ? 'RB' : 'WR1')];
}

function varietyAnimation(string $family): array
{
    $path = [[0, 45, 0, 27], [1, 46, 0, 27], [2.4, 52, 0, 27], [3.8, 55, 0, 27], [5.3, 60, 0, 27], [6, 60, 0, 27]];

    return ['carrier' => $family === 'scramble' ? 'QB' : ($family === 'run' ? 'RB' : 'WR1'), 'receiver_role' => in_array($family, ['run', 'scramble']) ? null : 'WR1',
        'tackle_style' => $family === 'incomplete' ? null : 'wrap',
        'players' => [['team' => 'offense', 'role' => $family === 'scramble' ? 'QB' : ($family === 'run' ? 'RB' : 'WR1'), 'path' => $path]],
        'ball' => array_map(fn ($p) => [$p[0], $p[1], 1, $p[3]], $path)];
}

test('each outcome family uses all 100 independent combinations before repeating', function () {
    foreach (['run', 'catch', 'incomplete', 'scramble'] as $family) {
        $profiles = [];
        for ($i = 0; $i < 100; $i++) {
            $play = varietyPlay($family, $i);
            $profile = AnimationVariety::choose($play);
            expect($profile)->toBe(AnimationVariety::choose($play));
            $profiles[] = $profile;
        }
        expect(count(array_unique(array_column($profiles, 'id'))))->toBe(100)
            ->and(count(array_unique(array_map(fn ($p) => implode(':', [$p['movement'], $p['handling'], $p['finish']]), $profiles))))->toBe(100)
            ->and(AnimationVariety::choose(varietyPlay($family, 100)))->toBe($profiles[0]);
        $saved = varietyPlay($family, 999);
        $saved['animation_variety'] = $profiles[17];
        expect(AnimationVariety::choose($saved))->toBe($profiles[17]);
    }
});

test('all five movement variations change real geometry while preserving handoff catch and finish locations', function () {
    foreach (['run', 'catch', 'incomplete', 'scramble'] as $family) {
        $shapes = [];
        for ($id = 0; $id < 5; $id++) {
            $play = varietyPlay($family);
            $play['animation_variety'] = ['version' => 1, 'family' => $family, 'id' => $id];
            $source = varietyAnimation($family);
            $animation = AnimationVariety::apply($source, $play);
            $path = $animation['players'][0]['path'];
            $shapes[] = json_encode($path);
            foreach (in_array($family, ['run', 'scramble']) ? [0, 1, 5.3, 6] : [0, 3.8, 5.3, 6] as $time) {
                $old = collect($source['players'][0]['path'])->first(fn ($p) => $p[0] == $time);
                $new = collect($path)->first(fn ($p) => $p[0] == $time);
                expect(hypot($old[1] - $new[1], $old[3] - $new[3]))->toBeLessThan(1e-9);
            }
            if (! in_array($family, ['run', 'scramble'])) {
                expect(collect($path)->first(fn ($p) => $p[0] == 3.8)[3])->toBe(27.0);
            }
            if ($family === 'incomplete') {
                expect($animation['ball'])->toBe($source['ball']);
            } else {
                foreach ($path as $point) {
                    if ($point[0] < ($family === 'scramble' ? 1.55 : ($family === 'run' ? 1 : 3.8))) {
                        continue;
                    }
                    $ball = collect($animation['ball'])->first(fn ($p) => abs($p[0] - $point[0]) < 1e-9);
                    expect(hypot($ball[1] - $point[1], $ball[3] - $point[3]))->toBeLessThan(1e-9);
                }
            }
        }
        expect(count(array_unique($shapes)))->toBe(5);
    }
});

test('variety leaves kneels spikes fakes throwaways and no-snap plays untouched', function () {
    foreach ([['call' => 'kneel'], ['call' => 'spike'], ['fake_kick' => true], ['no_snap' => true], ['throwaway' => true]] as $override) {
        $play = array_replace(varietyPlay('run'), $override);
        expect(AnimationVariety::choose($play))->toBeNull()
            ->and(AnimationVariety::apply(varietyAnimation('run'), $play))->toBe(varietyAnimation('run'));
    }
});

function expandedVarietyPlays(): array
{
    $plays = [];
    foreach (['sack', 'touchdown', 'safety', 'interception', 'fumble'] as $outcome) {
        $plays[$outcome] = array_replace(varietyPlay('run'), ['outcome' => $outcome]);
    }
    $plays['sideline'] = varietyPlay('run') + ['out_of_bounds' => true];
    $plays['defensive_return'] = varietyPlay('run') + ['defensive_return' => true];
    foreach (['punt' => ['return', 'return_touchdown', 'touchback', 'fair_catch', 'blocked'],
        'kickoff' => ['return', 'return_touchdown', 'touchback'],
        'field_goal' => ['good', 'missed', 'blocked'], 'extra_point' => ['good', 'missed', 'blocked'],
        'onside' => ['recovered', 'failed']] as $call => $outcomes) {
        foreach ($outcomes as $suffix) {
            $outcome = $call.'_'.$suffix;
            $plays['special_'.$outcome] = array_replace(varietyPlay('run'), ['call' => $call === 'onside' ? 'onside_kick' : $call, 'outcome' => $outcome]);
        }
    }

    return $plays;
}

test('expanded outcomes cycle 100 saved combinations without touching any paths or events', function () {
    $source = varietyAnimation('run') + ['ballHolders' => [[0, 'offense', 'C'], [1, 'offense', 'RB']], 'contact_at' => 5.3,
        'events' => [[0, 'Snap'], [5.3, 'Result']], 'duration' => 6];
    foreach (expandedVarietyPlays() as $family => $play) {
        $ids = [];
        foreach (range(0, 99) as $count) {
            $play['before']['animation_variety_counts'] = [$family => $count];
            $profile = AnimationVariety::choose($play);
            expect($profile['family'])->toBe($family)->and($profile['pose_only'])->toBeTrue();
            $ids[] = $profile['id'];
            $animation = AnimationVariety::apply($source, $play);
            unset($animation['variety']);
            expect($animation)->toBe($source);
        }
        expect(count(array_unique($ids)))->toBe(100);
        $play['animation_variety'] = $profile;
        $play['before']['animation_variety_counts'][$family] = 500;
        expect(AnimationVariety::choose($play))->toBe($profile);
        unset($play['animation_variety']);
        $play['before']['animation_variety_counts'][$family] = 100;
        expect(AnimationVariety::choose($play)['id'])->toBe($ids[0]);
    }
});
