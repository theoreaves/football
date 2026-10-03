<?php

namespace App\Models\Concerns;

use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait BelongsToWorld
{
    public static function bootBelongsToWorld(): void
    {
        static::addGlobalScope('world', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('world_id'), app(CurrentWorld::class)->id ?? 0);
        });
        static::deleting(function ($model) {
            if (! app(CurrentWorld::class)->id || (int) $model->world_id !== app(CurrentWorld::class)->id) {
                throw new LogicException('A matching current world is required to delete football data.');
            }
        });
        static::saving(function ($model) {
            $id = app(CurrentWorld::class)->id;
            if (! $id || ($model->world_id !== null && (int) $model->world_id !== $id)) {
                throw new LogicException('A matching current world is required to save football data.');
            }
            if ($model->exists && (int) $model->getOriginal('world_id') !== $id) {
                throw new LogicException('Football records cannot move between worlds.');
            }
            foreach (['home_team_id' => \App\Models\Team::class, 'away_team_id' => \App\Models\Team::class,
                'season_id' => \App\Models\Season::class, 'league_id' => \App\Models\League::class] as $key => $parent) {
                if ($model->getAttribute($key) !== null && ! $parent::whereKey($model->getAttribute($key))->exists()) {
                    throw new LogicException('Related football records must belong to the current world.');
                }
            }
            $model->world_id = $id;
        });
    }

    public function newQueryForRestoration($ids): Builder
    {
        return $this->newQuery()->whereKey($ids);
    }

    public function world(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
