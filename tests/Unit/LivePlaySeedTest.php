<?php

use App\Services\Arcade\LivePlaySeed;

test('live play seeds are stable uint32 values scoped to game seed and state version', function () {
    $state = ['seed' => 1234, 'version' => 0];
    $seed = LivePlaySeed::forState($state);
    expect($seed)->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(4294967295)
        ->and(LivePlaySeed::forState($state))->toBe($seed)
        ->and(LivePlaySeed::forState($state + ['clock' => 120]))->toBe($seed)
        ->and(LivePlaySeed::forState(['seed' => 1235, 'version' => 0]))->not->toBe($seed)
        ->and(LivePlaySeed::forState(['seed' => 1234, 'version' => 1]))->not->toBe($seed);
    $seeds = array_map(fn (int $version): int => LivePlaySeed::forState(['seed' => 1234, 'version' => $version]), range(0, 999));
    expect(array_unique($seeds))->toHaveCount(1000);
});
