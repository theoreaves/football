<?php

declare(strict_types=1);

use App\Models\OffensePlayRoll;

describe('OffensePlayRoll getYardsAttribute', function () {
    it('returns Breakaway for B!', function () {
        $roll = new OffensePlayRoll(['skill_pass' => 'B!']);
        expect($roll->yards)->toBe('Breakaway');
    });

    it('returns 0 for INC', function () {
        $roll = new OffensePlayRoll(['skill_pass' => 'INC']);
        expect($roll->yards)->toBe(0);
    });

    it('returns negative number for SK', function () {
        $roll = new OffensePlayRoll(['skill_pass' => '5 SK']);
        expect($roll->yards)->toBe(-5);
    });

    it('returns just the number for INT', function () {
        $roll = new OffensePlayRoll(['skill_pass' => '3 INT']);
        expect($roll->yards)->toBe(3);
    });

    it('returns number + R for (+ R)', function () {
        $roll = new OffensePlayRoll(['skill_pass' => '7 (+ R)']);
        expect($roll->yards)->toBe('7 + R');
    });

    it('returns the number if no special case', function () {
        $roll = new OffensePlayRoll(['skill_pass' => '12']);
        expect($roll->yards)->toBe(12);
    });

    it('returns null if no number or keyword', function () {
        $roll = new OffensePlayRoll(['skill_pass' => 'N/A']);
        expect($roll->yards)->toBeNull();
    });
});

