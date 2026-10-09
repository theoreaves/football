<?php

namespace App\Services\Seasons;

use App\Models\Exhibition;
use App\Models\Season;
use App\Services\Simulation\ExhibitionBoxScore;
use App\Services\Simulation\GamePersonnel;

class SeasonStats
{
    public const CATEGORIES = [
        'Passing' => ['pass_attempts', 'completions', 'passing_yards', 'passing_td', 'interceptions', 'sacks'],
        'Rushing' => ['rushes', 'rushing_yards', 'rushing_td', 'fumbles_lost'],
        'Receiving' => ['receptions', 'receiving_yards', 'receiving_td'],
        'Defense' => ['tackles', 'defensive_sacks', 'defensive_interceptions', 'fumble_recoveries'],
        'Kicking' => ['fg_made', 'fg_attempts', 'xp_made', 'xp_attempts'],
        'Punting' => ['punts', 'punt_yards'],
        'Returns' => ['returns', 'return_yards', 'return_td'],
    ];

    public function team(Season $season, int $teamId): array
    {
        $totals = ['games' => 0, 'points_for' => 0, 'points_against' => 0];
        $opponents = [];
        $players = [];
        $fixtures = $season->fixtures()->where('status', 'final')->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))->get();
        foreach ($fixtures as $fixture) {
            $game = Exhibition::find($fixture->exhibition_id);
            if (! $game || $game->state['status'] !== 'final' || ($game->state['penalty_pending'] ?? false)) {
                continue;
            }
            $side = (int) $fixture->home_team_id === $teamId ? 'home' : 'away';
            $other = $side === 'home' ? 'away' : 'home';
            $box = app(ExhibitionBoxScore::class)->build($game);
            $totals['games']++;
            $totals['points_for'] += $game->state[$side.'_score'];
            $totals['points_against'] += $game->state[$other.'_score'];
            foreach ($box['teams'][$side] as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
            foreach ($box['teams'][$other] as $key => $value) {
                $opponents[$key] = ($opponents[$key] ?? 0) + $value;
            }
            $appeared = [];
            foreach ($game->history as $play) {
                // Reconstruct that snap's personnel so substitutes keep their own statistics.
                $rosters = app(GamePersonnel::class)->active($game->rosters, $play['before']);
                $single = new Exhibition(['state' => $game->state, 'rosters' => $rosters, 'history' => [$play]]);
                $snap = app(ExhibitionBoxScore::class)->build($single);
                foreach ($snap['players'][$side] as $person) {
                    $id = $person['id'];
                    $values = array_filter($person, fn ($value, $key) => is_numeric($value) && ! in_array($key, ['id', 'number'], true), ARRAY_FILTER_USE_BOTH);
                    if (! array_filter($values)) {
                        continue;
                    }
                    $players[$id] ??= ['id' => $id, 'name' => $person['name'], 'number' => $person['number'], 'games' => 0];
                    $appeared[$id] = true;
                    foreach ($values as $key => $value) {
                        $players[$id][$key] = ($players[$id][$key] ?? 0) + $value;
                    }
                }
            }
            foreach (array_keys($appeared) as $id) {
                $players[$id]['games']++;
            }
        }
        foreach ($players as &$player) {
            $attempts = $player['pass_attempts'] ?? 0;
            $player['passer_rating'] = $attempts ? round((min(2.375, max(0, (($player['completions'] ?? 0) / $attempts - .3) * 5)) + min(2.375, max(0, (($player['passing_yards'] ?? 0) / $attempts - 3) * .25)) + min(2.375, max(0, ($player['passing_td'] ?? 0) / $attempts * 20)) + min(2.375, max(0, 2.375 - ($player['interceptions'] ?? 0) / $attempts * 25))) / 6 * 100, 1) : null;
        }
        unset($player);

        return compact('totals', 'opponents', 'players');
    }

    public function playerHistory(int $playerId, ?Season $current): array
    {
        $rows = [];
        $seasons = Season::when($current, fn ($q) => $q->where('league_id', $current->league_id))->orderByDesc('year')->get();
        foreach ($seasons as $season) {
            if ($current && $season->year > $current->year) {
                continue;
            }
            $candidateTeams = [];
            foreach ($season->fixtures()->where('status', 'final')->get(['exhibition_id', 'home_team_id', 'away_team_id']) as $fixture) {
                $game = Exhibition::select(['id', 'rosters'])->find($fixture->exhibition_id);
                if (! $game) {
                    continue;
                }
                foreach (['home', 'away'] as $side) {
                    if (collect($game->rosters[$side]['pool'] ?? $game->rosters[$side]['players'])->contains(fn ($p) => (int) $p['id'] === $playerId)) {
                        $candidateTeams[$fixture->{$side.'_team_id'}] = true;
                    }
                }
            }
            foreach (array_keys($candidateTeams) as $teamId) {
                $member = $season->settings['members'][$teamId];
                $stats = $this->team($season, (int) $teamId)['players'][$playerId] ?? null;
                if ($stats) {
                    $rows[] = ['season' => $season, 'team' => $member['name'], 'stats' => $stats];
                }
            }
        }

        return $rows;
    }
}
