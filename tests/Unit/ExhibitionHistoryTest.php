<?php

use App\Models\Exhibition;
use App\Services\Arcade\ArcadeContext;
use App\Support\ExhibitionHistory;

function rawHistoryGame(string $history): Exhibition
{
    return (new Exhibition)->setRawAttributes(['state' => '{"z":1,"a":{"n":0.0}}', 'rosters' => '{"home":[],"away":[]}', 'history' => $history, 'home_team_id' => 1, 'away_team_id' => 2, 'world_id' => 3], true);
}

test('bounded history reads preserve quoted delimiters Unicode types and stored ordering', function () {
    $plays = [[], ['number' => 1, 'text' => "escaped \\\" [,]} 球\n", 'values' => [0, 0.0, -0.0, true, false, null, ['x' => 1]]], ['number' => 2, 'legacy_unknown' => ['z' => 2, 'a' => 1]]];
    $game = rawHistoryGame(" \n".json_encode($plays, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\t ");
    expect(iterator_to_array(ExhibitionHistory::entries($game)))->toBe($plays)
        ->and(ExhibitionHistory::count($game))->toBe(3)
        ->and(ExhibitionHistory::last($game))->toBe($plays[2])
        ->and(ExhibitionHistory::at($game, 1))->toBe($plays[1])
        ->and(ExhibitionHistory::at($game, 99))->toBeNull()
        ->and(ExhibitionHistory::find($game, fn ($play) => ($play['number'] ?? null) === 1))->toBe($plays[1])
        ->and(ExhibitionHistory::select($game, [2, 0]))->toBe([0 => $plays[0], 2 => $plays[2]]);
    expect(ArcadeContext::exhibitionHash($game))->toBe(ArcadeContext::hash([$game->state, $game->rosters, $game->history, 1, 2, 3]));
});

test('bounded history supports empty and legacy object-root collections', function () {
    foreach (['[]', ' [ ] ', '{}', '{"4":{"number":5},"1":{"number":2}}'] as $json) {
        $game = rawHistoryGame($json);
        $expected = json_decode($json, true);
        expect(iterator_to_array(ExhibitionHistory::entries($game)))->toBe($expected)
            ->and(ExhibitionHistory::count($game))->toBe(count($expected))
            ->and(ArcadeContext::exhibitionHash($game))->toBe(ArcadeContext::hash([$game->state, $game->rosters, $expected, 1, 2, 3]));
    }
});

test('bounded history rejects corrupt JSON rather than returning a partial history', function () {
    foreach (['[', '[{}', '[{},]', '[{},', '[{}]x', '[{}, nope]', '[{"x":"unterminated}]', '[{"x":"\\q"}]', '[{]}]', '[{}]]', '[{}] []'] as $json) {
        $game = rawHistoryGame($json);
        expect(fn () => iterator_to_array(ExhibitionHistory::entries($game)))->toThrow(JsonException::class)
            ->and(fn () => ExhibitionHistory::at($game, 0))->toThrow(JsonException::class);
    }
});

test('bounded history fingerprint matches whole-array hashing across deterministic varied histories', function () {
    $history = [];
    for ($i = 0; $i < 60; $i++) {
        $history[] = ['z' => ['quoted' => str_repeat('"\\,{}[]', $i % 5), 'float' => $i / 3, 'flags' => [$i % 2 === 0, null]], 'number' => $i + 1, 'frames' => array_fill(0, $i % 8, [[$i, $i + 1, -$i], [0.0, -0.0]])];
        $game = rawHistoryGame(json_encode($history, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        expect(iterator_to_array(ExhibitionHistory::entries($game)))->toBe($history)
            ->and(ArcadeContext::exhibitionHash($game))->toBe(ArcadeContext::hash([$game->state, $game->rosters, $game->history, 1, 2, 3]));
    }
});

test('bounded history preserves decoder and canonical hash depth limits', function () {
    $play = [];
    for ($i = 0; $i < 510; $i++) {
        $play = [$play];
    }
    $json = json_encode([$play], JSON_THROW_ON_ERROR, 1024);
    expect(fn () => json_decode($json, true, 512, JSON_THROW_ON_ERROR))->toThrow(JsonException::class)
        ->and(fn () => iterator_to_array(ExhibitionHistory::entries(rawHistoryGame($json))))->toThrow(JsonException::class);
});

test('large recording histories can be read hashed appended and replaced at 128M', function () {
    $process = new Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__).'/Support/exhibition-history-memory.php'], dirname(__DIR__, 2));
    $process->setTimeout(60);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    expect($result['count'])->toBe(101)->and($result['writes'])->toBe(2)
        ->and($result['first'])->toBe(['number' => 1, 'corrected' => true])
        ->and($result['last'])->toBe(['number' => 101])
        ->and(strlen($result['hash']))->toBe(64)
        ->and($result['bytes'])->toBeGreaterThan(8 * 1024 * 1024)
        ->and($result['peak'])->toBeLessThan(128 * 1024 * 1024);
});
