<?php

namespace App\Services\Arcade;

/** Stable per-play randomness: retries cannot reroll, but later snaps do not repeat the same stream. */
final class LivePlaySeed
{
    public static function forState(array $state): int
    {
        return (int) hexdec(substr(hash('sha256', 'arcade-live-1:'.$state['seed'].':'.$state['version']), 0, 8));
    }
}
