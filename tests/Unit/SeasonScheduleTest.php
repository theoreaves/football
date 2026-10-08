<?php

use App\Services\Seasons\ScheduleGenerator;
use App\Services\Seasons\SeasonOptions;

test('season schedules satisfy game counts home balance and bye rules for every option', function () {
    foreach (SeasonOptions::SIZES as $count) {
        foreach (SeasonOptions::lengths($count) as $length) {
            foreach ([false, true] as $bye) {
                $members = [];
                $id = 1;
                foreach (SeasonOptions::groups($count, 'divisions') as $key => $group) {
                    for ($i = 0; $i < $group['size']; $i++) {
                        $members[$id++] = ['group' => $key, 'conference' => $group['conference']];
                    }
                }
                $fixtures = (new ScheduleGenerator)->generate($members, $length, $bye);
                expect(count($fixtures))->toBe($count * $length / 2);
                $weeks = [];
                $home = $away = $opponents = [];
                foreach ($fixtures as $fixture) {
                    $a = $fixture['home_team_id'];
                    $b = $fixture['away_team_id'];
                    $week = $fixture['week'];
                    expect($a)->not->toBe($b);
                    expect($weeks[$week][$a] ?? false)->toBeFalse()->and($weeks[$week][$b] ?? false)->toBeFalse();
                    $weeks[$week][$a] = $weeks[$week][$b] = true;
                    $home[$a] = ($home[$a] ?? 0) + 1;
                    $away[$b] = ($away[$b] ?? 0) + 1;
                    $opponents[$a][$b] = ($opponents[$a][$b] ?? 0) + 1;
                    $opponents[$b][$a] = ($opponents[$b][$a] ?? 0) + 1;
                }
                expect(max(array_keys($weeks)))->toBe($length + (int) $bye);
                foreach (array_keys($members) as $id) {
                    expect(($home[$id] ?? 0) + ($away[$id] ?? 0))->toBe($length);
                    expect(abs(($home[$id] ?? 0) - ($away[$id] ?? 0)))->toBeLessThanOrEqual(1);
                    expect(max($opponents[$id]))->toBeLessThanOrEqual((int) ceil($length / ($count - 1)));
                    $missed = 0;
                    for ($week = 1; $week <= $length + (int) $bye; $week++) {
                        if (! isset($weeks[$week][$id])) {
                            $missed++;
                        }
                    }
                    expect($missed)->toBe((int) $bye);
                }
                expect((new ScheduleGenerator)->generate($members, $length, $bye))->toBe($fixtures);
            }
        }
    }
});

test('league presets enforce NFL bracket compatibility and historical division sizes', function () {
    expect(array_column(SeasonOptions::groups(28, 'divisions'), 'size'))->toBe([5, 5, 4, 5, 5, 4]);
    expect(SeasonOptions::compatible(28, 'divisions', 'nfl10'))->toBeTrue();
    expect(SeasonOptions::compatible(32, 'divisions', 'nfl14'))->toBeTrue();
    expect(SeasonOptions::compatible(12, 'flat', 'nfl12'))->toBeFalse();
    expect(SeasonOptions::compatible(4, 'conferences', '8'))->toBeFalse();
});
