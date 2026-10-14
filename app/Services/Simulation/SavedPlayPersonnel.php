<?php

namespace App\Services\Simulation;

class SavedPlayPersonnel
{
    /** Resolve identity from the saved snap, never the current lineup. */
    public function rosters(array $rosters, array $play): array
    {
        $tracks = $play['animation']['players'] ?? [];
        if ($tracks === []) {
            $before = $play['before'] ?? [];
            $before['_personnel_simulation'] = true;

            return app(GamePersonnel::class)->active($rosters, $before);
        }
        foreach ($tracks as $track) {
            $side = $track['side'] ?? null;
            $role = $track['role'] ?? null;
            if (! isset($rosters[$side], $track['id']) || ! isset(RosterBuilder::GROUPS[$role])) {
                continue;
            }
            $person = collect($rosters[$side]['pool'] ?? $rosters[$side]['players'])->first(fn ($person) => (int) $person['id'] === (int) $track['id']);
            $rosters[$side]['players'][$role] = array_replace($person ?? $rosters[$side]['players'][$role] ?? $track, array_intersect_key($track, array_flip(['id', 'name', 'number'])));
        }

        return $rosters;
    }
}
