<?php

use App\Services\Simulation\ExhibitionEngine;

test('scrimmage and conversion calls are separated', function () {
    expect(ExhibitionEngine::callsForState(['phase' => 'scrimmage']))->toContain('kneel', 'short_pass')->not->toContain('extra_point', 'two_point_run');
    expect(ExhibitionEngine::callsForState(['phase' => 'extra_point']))->toBe(['extra_point', 'two_point_run', 'two_point_pass']);
});
