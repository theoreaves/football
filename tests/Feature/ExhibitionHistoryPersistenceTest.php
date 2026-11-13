<?php

use App\Models\Exhibition;
use App\Models\Team;
use App\Models\World;
use App\Support\ExhibitionHistory;
use Illuminate\Support\Facades\DB;

function historyPersistenceGame(): Exhibition
{
    $home = Team::create(['city' => 'Home', 'name' => 'History']);
    $away = Team::create(['city' => 'Away', 'name' => 'History']);

    return Exhibition::create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'state' => ['version' => 0], 'rosters' => [], 'history' => [['number' => 1, 'unknown' => ['keep' => 'exact'], 'animation' => ['frames' => [[1, 2, 3]]]]]]);
}

beforeEach(function () {
    openFootballSave(World::create(['name' => 'Bounded history']));
});

test('bounded append and replacement preserve model events timestamps and normal stored values', function () {
    $normal = historyPersistenceGame();
    $bounded = historyPersistenceGame();
    $dispatcher = Exhibition::getEventDispatcher();
    Exhibition::setEventDispatcher(clone $dispatcher);
    $events = [];
    try {
        foreach (['saving', 'updating', 'updated', 'saved'] as $event) {
            Exhibition::{$event}(function ($game) use (&$events, $event) {
                $events[$game->id][] = $event;
            });
        }
        $this->travel(2)->seconds();
        $play = ['number' => 2, 'summary' => 'quotes " and \\ and 球', 'recording' => ['frames' => [[0.0, -0.0, 1.25]]]];
        $history = $normal->history;
        $history[] = $play;
        $normal->update(['history' => $history, 'state' => ['version' => 1]]);
        expect(ExhibitionHistory::append($bounded, $play, ['state' => ['version' => 1]]))->toBeTrue();
        expect($bounded->fresh()->history)->toBe($normal->fresh()->history)
            ->and($bounded->fresh()->state)->toBe($normal->fresh()->state)
            ->and($bounded->updated_at->equalTo($normal->updated_at))->toBeTrue()
            ->and($events[$bounded->id])->toBe($events[$normal->id]);
        $replacement = $play + ['penalty' => ['decided' => true]];
        $history[1] = $replacement;
        $normal->update(['history' => $history, 'state' => ['version' => 2]]);
        ExhibitionHistory::replace($bounded, 1, $replacement, ['state' => ['version' => 2]]);
        expect($bounded->fresh()->history)->toBe($normal->fresh()->history)
            ->and($bounded->getDirty())->toBe([])
            ->and($events[$bounded->id])->toBe($events[$normal->id]);
    } finally {
        Exhibition::setEventDispatcher($dispatcher);
    }
});

test('bounded saves keep transaction rollback and world authorization intact', function () {
    $game = historyPersistenceGame();
    $before = $game->history;
    try {
        DB::transaction(function () use ($game) {
            ExhibitionHistory::append($game, ['number' => 2], ['state' => ['version' => 1]]);
            throw new RuntimeException('Rollback test');
        });
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Rollback test');
    }
    $game->refresh();
    expect($game->history)->toBe($before)->and($game->state)->toBe(['version' => 0]);
    expect(fn () => ExhibitionHistory::append($game, ['number' => 2], ['world_id' => $game->world_id + 100]))->toThrow(LogicException::class);
    $game->refresh();
    $raw = $game->getAttributes();
    $raw['history'] = ' '.($raw['history']).' ';
    $game->setRawAttributes($raw);
    expect($game->originalIsEquivalent('history'))->toBeTrue();
});

test('bounded writes retain untouched raw entries and reject conflicting or missing changes', function () {
    $game = historyPersistenceGame();
    $raw = $game->getRawOriginal('history');
    ExhibitionHistory::append($game, ['number' => 2]);
    expect($game->getRawOriginal('history'))->toStartWith(substr($raw, 0, -1).',');
    expect(fn () => ExhibitionHistory::replace($game, 50, []))->toThrow(OutOfBoundsException::class)
        ->and(fn () => $game->updateHistoryJson('[]', ['history' => []]))->toThrow(InvalidArgumentException::class);
});

test('bounded writes reject over-depth plays before changing the saved history', function () {
    $game = historyPersistenceGame();
    $before = $game->getRawOriginal('history');
    $play = [];
    for ($i = 0; $i < 511; $i++) {
        $play = [$play];
    }
    expect(json_encode($play))->toBeString()
        ->and(json_encode([$play]))->toBeFalse()
        ->and(fn () => ExhibitionHistory::append($game, $play))->toThrow(JsonException::class)
        ->and($game->fresh()->getRawOriginal('history'))->toBe($before);
    ExhibitionHistory::append($game, ['number' => 1]);
    $before = $game->getRawOriginal('history');
    expect(fn () => ExhibitionHistory::replace($game, 0, $play))->toThrow(JsonException::class)
        ->and($game->fresh()->getRawOriginal('history'))->toBe($before);
});
