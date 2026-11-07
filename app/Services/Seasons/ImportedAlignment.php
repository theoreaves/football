<?php

namespace App\Services\Seasons;

use Illuminate\Support\Collection;

class ImportedAlignment
{
    public function setup(Collection $teams): ?array
    {
        if (! in_array($teams->count(), SeasonOptions::SIZES, true) || $teams->contains(fn ($team) => ! $team->conference || ! $team->division)) {
            return null;
        }
        $conferences = $teams->groupBy('conference');
        if ($conferences->count() !== 2) {
            return null;
        }
        $definitions = SeasonOptions::groups($teams->count(), 'divisions');
        $assignments = [];
        foreach ($conferences->values() as $index => $members) {
            $prefix = $index === 0 ? 'A' : 'B';
            $divisions = $members->groupBy('division')->sortByDesc(fn ($group) => $group->count())->values();
            $slots = collect($definitions)->filter(fn ($group, $key) => str_starts_with($key, $prefix))->sortByDesc('size');
            if ($divisions->count() !== $slots->count()) {
                return null;
            }
            foreach ($slots->keys()->values() as $divisionIndex => $key) {
                $group = $divisions[$divisionIndex];
                if ($group->count() !== $definitions[$key]['size']) {
                    return null;
                }
                $definitions[$key]['conference'] = $group->first()->conference;
                $definitions[$key]['division'] = $group->first()->division;
                foreach ($group as $team) {
                    $assignments[$team->id] = $key;
                }
            }
        }

        return ['count' => $teams->count(), 'groups' => $definitions, 'assignments' => $assignments];
    }
}
