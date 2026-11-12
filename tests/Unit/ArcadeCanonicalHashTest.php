<?php

use App\Services\Arcade\ArcadeContext;

function legacyArcadeCanonicalHash(array $value): string
{
    $sort = function (array $data) use (&$sort): array {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$item) {
            if (is_array($item)) {
                $item = $sort($item);
            }
        }

        return $data;
    };

    return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
}

test('streamed Arcade fingerprint preserves canonical JSON bytes', function () {
    $cases = [[], [0, 0.0, -0.0, 1.0, 1e-20, true, false, null], ['z' => 'é / 球', 'a' => ['quote' => '"\\', 'empty' => []]], [1 => 'one', 0 => 'zero'], [2 => 'two', 'a' => 3, -1 => 'negative'], [['z' => [[0.0]], 'a' => []]], ['object' => (object) ['z' => 1, 'a' => 2]]];
    foreach ($cases as $value) {
        expect(ArcadeContext::hash($value))->toBe(legacyArcadeCanonicalHash($value));
    }
    $value = [];
    for ($i = 0; $i < 511; $i++) {
        $value = [$value];
    }
    expect(ArcadeContext::hash($value))->toBe(legacyArcadeCanonicalHash($value));
    expect(fn () => ArcadeContext::hash([$value]))->toThrow(JsonException::class);
    expect(fn () => legacyArcadeCanonicalHash([$value]))->toThrow(JsonException::class);
});

test('streamed Arcade fingerprint keeps encoding errors fatal', function () {
    foreach ([[INF], [NAN], ["\xB1\x31"]] as $value) {
        expect(fn () => ArcadeContext::hash($value))->toThrow(JsonException::class);
        expect(fn () => legacyArcadeCanonicalHash($value))->toThrow(JsonException::class);
    }
});

test('streamed Arcade fingerprint preserves the scalar and object depth boundary', function () {
    foreach ([0, (object) ['a' => 0]] as $leaf) {
        $value = $leaf;
        for ($i = 0; $i < 512; $i++) {
            $value = [$value];
        }
        if (is_object($leaf)) {
            expect(fn () => ArcadeContext::hash($value))->toThrow(JsonException::class);
            expect(fn () => legacyArcadeCanonicalHash($value))->toThrow(JsonException::class);
        } else {
            expect(ArcadeContext::hash($value))->toBe(legacyArcadeCanonicalHash($value));
        }
    }
});

test('large recording histories are hashed without a second recursive copy', function () {
    $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/../Support/arcade-hash-memory.php']);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['hash'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($result['extra_peak'])->toBeLessThan(8 * 1024 * 1024);
});

test('streamed Arcade fingerprint preserves serializable scalar at the depth boundary', function () {
    $value = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return 3;
        }
    };
    for ($i = 0; $i < 512; $i++) {
        $value = [$value];
    }
    expect(ArcadeContext::hash($value))->toBe(legacyArcadeCanonicalHash($value));
});
