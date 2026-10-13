<?php

namespace App\Services\Simulation;

class SavedPlayPersonnel
{
    /** Resolve identity from the saved snap, never the current lineup. */
    public function rosters(array $rosters, array $play): array
    {
        $tracks = $play['animation']['players'] ?? [];
        if ($tracks === []) {
            return app(GamePersonnel::class)->active($rosters, $play['before'] ?? []);
        }
        foreach ($tracks as $track) {
            $side = $track['side'] ?? null;
            $role = $track['role'] ?? null;
            if (! isset($rosters[$side]['players'][$role], $track['id'])) {
                continue;
            }
            $person = collect($rosters[$side]['pool'] ?? $rosters[$side]['players'])->first(fn ($person) => (int) $person['id'] === (int) $track['id']);
            $rosters[$side]['players'][$role] = array_replace($person ?? $rosters[$side]['players'][$role], array_intersect_key($track, array_flip(['id', 'name', 'number'])));
        }

        return $rosters;
    }
}
