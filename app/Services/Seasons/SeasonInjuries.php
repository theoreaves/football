<?php

namespace App\Services\Seasons;

use App\Models\Exhibition;
use App\Models\Season;

/** Injury events recorded by completed or in-progress season games. */
class SeasonInjuries
{
    public function forTeam(Season $season, int $teamId): array
    {
        $events = [];
        $fixtures = $season->fixtures()
            ->where(fn ($query) => $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->whereNotNull('exhibition_id')->orderBy('week')->get();

        foreach ($fixtures as $fixture) {
            $game = Exhibition::find($fixture->exhibition_id);
            if (! $game) {
                continue;
            }
            $side = (int) $fixture->home_team_id === $teamId ? 'home' : 'away';
            $roster = collect($game->rosters[$side]['pool'] ?? $game->rosters[$side]['players'] ?? []);
            $names = $roster->keyBy('id');
            $seen = [];
            foreach ($game->history ?? [] as $play) {
                $before = $play['before']['injuries'][$side] ?? [];
                $after = $play['after']['injuries'][$side] ?? [];
                foreach ($after as $id => $injury) {
                    if (isset($before[$id]) || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = count($events);
                    $player = $names->get($id);
                    $events[] = [
                        'player_id' => (int) $id,
                        'name' => $player['name'] ?? $injury['name'] ?? 'Unknown player',
                        'number' => $player['number'] ?? null,
                        'position' => $player['position'] ?? '—',
                        'type' => $injury['type'] ?? 'Injury',
                        'week' => $fixture->week,
                        'game_id' => $game->id,
                        'status' => 'Out for game',
                        'return_snap' => $injury['return_snap'] ?? null,
                    ];
                }
                foreach ($before as $id => $injury) {
                    if (! isset($after[$id]) && isset($seen[$id])) {
                        $events[$seen[$id]]['status'] = 'Returned';
                    }
                }
            }
            // A game may have been saved before its final play was added to history.
            foreach ($seen as $id => $index) {
                $stillOut = isset($game->state['injuries'][$side][$id]);
                if (! $stillOut) {
                    $events[$index]['status'] = 'Returned';
                } elseif ($events[$index]['return_snap'] !== null) {
                    $events[$index]['status'] = 'Out temporarily';
                }
            }
        }

        return array_reverse($events);
    }

    public function forPlayer(int $playerId, ?Season $current = null): array
    {
        $events = [];
        $seasons = Season::query()
            ->when($current, fn ($query) => $query->where('league_id', $current->league_id)->where('year', '<=', $current->year))
            ->orderByDesc('year')->get();
        foreach ($seasons as $season) {
            foreach (array_keys($season->settings['members'] ?? []) as $teamId) {
                foreach ($this->forTeam($season, (int) $teamId) as $event) {
                    if ($event['player_id'] === $playerId) {
                        $events[] = ['season' => $season->year, 'team' => $season->settings['members'][$teamId]['name'], ...$event];
                    }
                }
            }
        }

        return $events;
    }
}
