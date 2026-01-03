<?php

declare(strict_types=1);

use App\Services\YardageParserService;

describe('YardageParserService::parseYards', function () {
    it('returns Breakaway for B!', function () {
        expect(YardageParserService::parseYards('B!'))->toBe('Breakaway');
    });

    it('returns 0 for INC', function () {
        expect(YardageParserService::parseYards('INC'))->toBe(0);
    });

    it('returns negative number for SK', function () {
        expect(YardageParserService::parseYards('5 SK'))->toBe(-5);
    });

    it('returns just the number for INT', function () {
        expect(YardageParserService::parseYards('3 INT'))->toBe(3);
    });

    it('returns number + skill for (+ R) with skill', function () {
        expect(YardageParserService::parseYards('7 (+ R)', 4))->toBe(11);
    });

    it('returns string for (+ R) without skill', function () {
        expect(YardageParserService::parseYards('7 (+ R)'))->toBe('7 + R');
    });

    it('returns the number if no special case', function () {
        expect(YardageParserService::parseYards('12'))->toBe(12);
    });

    it('returns null if no number or keyword', function () {
        expect(YardageParserService::parseYards('N/A'))->toBeNull();
    });
});

