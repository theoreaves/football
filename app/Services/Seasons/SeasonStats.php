<?php

namespace App\Services\Seasons;

use App\Models\Exhibition;
use App\Models\Season;
use App\Services\Simulation\ExhibitionBoxScore;

class SeasonStats
{
    public const CATEGORIES = [
        'Passing' => ['pass_attempts', 'completions', 'passing_yards', 'passing_td', 'interceptions', 'sacks'],
        'Rushing' => ['rushes', 'rushing_yards', 'rushing_td', 'fumbles_lost'],
        'Receiving' => ['targets', 'receptions', 'receiving_yards', 'receiving_td'],
        'Defense' => ['tackles', 'defensive_sacks', 'defensive_interceptions', 'fumble_recoveries'],
        'Kicking' => ['fg_made', 'fg_attempts', 'xp_made', 'xp_attempts'],
        'Punting' => ['punts', 'punt_yards'],
        'Returns' => ['returns', 'return_yards', 'return_td'],
    ];

    public function team(Season $season, int $teamId): array
    {
        return $this->summaries($season, $teamId)['teams'][$teamId] ?? $this->emptyTeam();
    }

    private function emptyTeam(): array
    {
        return ['totals' => ['games' => 0, 'points_for' => 0, 'points_against' => 0], 'opponents' => [], 'players' => []];
    }

    /** Build each completed game's identity-aware box score only once. */
    public function summaries(Season $season, ?int $teamId = null): array
    {
        $teams = [];
        $throughWeek = 0;
        // Snapshot lightweight fixture metadata, then hold only one game's history at a time.
        $fixtures = $season->fixtures()->where('status', 'final')
            ->when($teamId !== null, fn ($q) => $q->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId)))
            ->orderBy('id')->get(['id', 'week', 'home_team_id', 'away_team_id', 'exhibition_id']);
        foreach ($fixtures as $fixture) {
            $game = Exhibition::select(['id', 'world_id', 'state', 'rosters', 'history'])->find($fixture->exhibition_id);
            if (! $game || $game->state['status'] !== 'final' || ($game->state['penalty_pending'] ?? false)) {
                continue;
            }
            $box = app(ExhibitionBoxScore::class)->build($game);
            $throughWeek = max($throughWeek, (int) $fixture->week);
            foreach (['home', 'away'] as $side) {
                $id = (int) $fixture->{$side.'_team_id'};
                if ($teamId !== null && $id !== $teamId) {
                    continue;
                }
                $other = $side === 'home' ? 'away' : 'home';
                $teams[$id] ??= $this->emptyTeam();
                $summary = &$teams[$id];
                $summary['totals']['games']++;
                $summary['totals']['points_for'] += $game->state[$side.'_score'];
                $summary['totals']['points_against'] += $game->state[$other.'_score'];
                foreach ($box['teams'][$side] as $key => $value) {
                    $summary['totals'][$key] = ($summary['totals'][$key] ?? 0) + $value;
                }
                foreach ($box['teams'][$other] as $key => $value) {
                    $summary['opponents'][$key] = ($summary['opponents'][$key] ?? 0) + $value;
                }
                foreach ($box['players'][$side] as $person) {
                    $values = array_filter($person, fn ($value, $key) => is_numeric($value) && ! in_array($key, ['id', 'number'], true), ARRAY_FILTER_USE_BOTH);
                    if (! array_filter($values)) {
                        continue;
                    }
                    $playerId = $person['id'];
                    $summary['players'][$playerId] ??= ['id' => $playerId, 'name' => $person['name'], 'number' => $person['number'], 'games' => 0];
                    $summary['players'][$playerId]['games']++;
                    foreach ($values as $key => $value) {
                        $summary['players'][$playerId][$key] = ($summary['players'][$playerId][$key] ?? 0) + $value;
                    }
                }
                unset($summary);
            }
            unset($box, $game);
        }
        foreach ($teams as &$summary) {
            $players = &$summary['players'];
            foreach ($players as &$player) {
                $attempts = $player['pass_attempts'] ?? 0;
                $player['passer_rating'] = $attempts ? round((min(2.375, max(0, (($player['completions'] ?? 0) / $attempts - .3) * 5)) + min(2.375, max(0, (($player['passing_yards'] ?? 0) / $attempts - 3) * .25)) + min(2.375, max(0, ($player['passing_td'] ?? 0) / $attempts * 20)) + min(2.375, max(0, 2.375 - ($player['interceptions'] ?? 0) / $attempts * 25))) / 6 * 100, 1) : null;
            }
            unset($player);
            unset($players);
        }
        unset($summary);

        return ['teams' => $teams, 'through_week' => $throughWeek];
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
