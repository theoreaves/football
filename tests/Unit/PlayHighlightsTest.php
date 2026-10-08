<?php

use App\Support\PlayHighlights;

test('automatic highlights include big gains scores and turnovers but exclude pending and dead plays', function () {
    $before = ['home_score' => 0, 'away_score' => 0, 'possession' => 'home', 'stats' => ['home' => ['turnovers' => 0]]];
    $play = ['call' => 'inside_run', 'gain' => 20, 'before' => $before, 'after' => $before];
    expect(PlayHighlights::saved($play))->toBeFalse();
    expect(PlayHighlights::reasons(array_replace($play, ['gain' => 21])))->toBe(['20+ yards']);
    expect(PlayHighlights::reasons(array_replace($play, ['gain' => 50, 'call' => 'punt'])))->toBe([]);
    expect(PlayHighlights::reasons(array_replace($play, ['call' => 'kickoff', 'gain' => 60, 'return_yards' => 25])))->toBe(['20+ yards']);
    $score = $play;
    $score['after']['home_score'] = 6;
    expect(PlayHighlights::reasons($score))->toBe(['Score']);
    $turnover = $play;
    $turnover['after']['stats']['home']['turnovers'] = 1;
    expect(PlayHighlights::reasons($turnover))->toBe(['Turnover']);
    $turnover['after']['penalty_pending'] = true;
    expect(PlayHighlights::saved($turnover))->toBeFalse();
    expect(PlayHighlights::saved(array_replace($play, ['saved_highlight' => true])))->toBeTrue();
    expect(PlayHighlights::saved(array_replace($score, ['no_snap' => true])))->toBeFalse();
    expect(PlayHighlights::saved(array_replace($play, ['gain' => 30, 'outcome' => 'penalty'])))->toBeFalse();
});
