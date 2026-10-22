<?php

namespace App\Services\Seasons;

use App\Models\Exhibition;
use App\Services\Simulation\ExhibitionBoxScore;

class CompletedGameStats
{
    // Bump when box-score scoring rules change. Replays remain the source of truth.
    public const SCHEMA = 1;

    public function box(Exhibition $game, array $state): array
    {
        $cached = $game->box_score_cache;
        if (($cached['schema'] ?? null) === self::SCHEMA && ($cached['version'] ?? null) === ($state['version'] ?? null)
            && isset($cached['box']['teams'], $cached['box']['players'], $cached['box']['quarters'], $cached['box']['scoring'])) {
            return $cached['box'];
        }
        // Only a cache miss fetches the large replay/roster JSON columns.
        $source = Exhibition::select(['id', 'world_id', 'state', 'rosters', 'history'])->findOrFail($game->id);
        $sourceState = $source->state;
        $box = app(ExhibitionBoxScore::class)->build($source);
        if ($sourceState['status'] === 'final' && ! ($sourceState['penalty_pending'] ?? false)) {
            Exhibition::whereKey($source->id)->where('state->version', $sourceState['version'])
                ->update(['box_score_cache' => json_encode(['schema' => self::SCHEMA, 'version' => $sourceState['version'], 'box' => $box], JSON_THROW_ON_ERROR)]);
        }

        return $box;
    }
}
