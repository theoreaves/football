<?php

namespace App\Services\Seasons;

use App\Models\Season;
use Illuminate\Support\Facades\Cache;

class LeagueLeaders
{
    public const CATEGORIES = [
        'Passing yards' => ['field' => 'passing_yards', 'columns' => ['passing_yards', 'completions', 'pass_attempts', 'passing_td', 'interceptions']],
        'Passing touchdowns' => ['field' => 'passing_td', 'columns' => ['passing_td', 'passing_yards', 'interceptions']],
        'Rushing yards' => ['field' => 'rushing_yards', 'columns' => ['rushing_yards', 'rushes', 'rushing_td']],
        'Rushing touchdowns' => ['field' => 'rushing_td', 'columns' => ['rushing_td', 'rushing_yards', 'rushes']],
        'Receiving yards' => ['field' => 'receiving_yards', 'columns' => ['receiving_yards', 'receptions', 'receiving_td']],
        'Receptions' => ['field' => 'receptions', 'columns' => ['receptions', 'receiving_yards', 'receiving_td']],
        'Tackles' => ['field' => 'tackles', 'columns' => ['tackles', 'defensive_sacks', 'defensive_interceptions']],
        'Sacks' => ['field' => 'defensive_sacks', 'columns' => ['defensive_sacks', 'tackles']],
        'Interceptions' => ['field' => 'defensive_interceptions', 'columns' => ['defensive_interceptions', 'tackles']],
        'Field goals made' => ['field' => 'fg_made', 'columns' => ['fg_made', 'fg_attempts']],
        'Punt yards' => ['field' => 'punt_yards', 'columns' => ['punt_yards', 'punts']],
        'Return yards' => ['field' => 'return_yards', 'columns' => ['return_yards', 'returns', 'return_td']],
    ];

    private function key(Season $season): string
    {
        return 'season-leaders:'.$season->world_id.':'.$season->id;
    }

    private function fingerprint(Season $season): string
    {
        $fixtures = $season->fixtures()->where('status', 'final');

        return $fixtures->count().':'.($fixtures->max('updated_at') ?? 'none');
    }

    public function isReady(Season $season): bool
    {
        $snapshot = Cache::get($this->key($season));

        return is_array($snapshot) && isset($snapshot['leaders']);
    }

    /** Read only precomputed results in a web request. Never replay every game here. */
    public function forSeason(Season $season, int $limit = 10): array
    {
        $snapshot = Cache::get($this->key($season));
        if (! is_array($snapshot)) {
            return [];
        }

        return array_map(fn ($board) => [
            'columns' => $board['columns'],
            'players' => array_slice($board['players'], 0, $limit),
        ], $snapshot['leaders']);
    }

    public function teamStats(Season $season): array
    {
        return Cache::get($this->key($season))['teams'] ?? [];
    }

    /** The week covered by the last completed statistics snapshot. */
    public function publishedThroughWeek(Season $season): ?int
    {
        $week = Cache::get($this->key($season))['through_week'] ?? null;

        return $week === null ? null : (int) $week;
    }

    /** Heavy calculation intended for CLI, not a 30-second HTTP request. */
    public function rebuild(Season $season, int $limit = 100000): array
    {
        $members = $season->settings['members'] ?? [];
        $players = [];
        $teams = [];
        $stats = app(SeasonStats::class);
        foreach ($members as $teamId => $member) {
            $summary = $stats->team($season, (int) $teamId);
            $teams[$teamId] = ['name' => $member['name'], 'totals' => $summary['totals'], 'opponents' => $summary['opponents']];
            foreach ($summary['players'] as $person) {
                $players[] = $person + [
                    'team_id' => (int) $teamId,
                    'team_name' => $member['name'],
                ];
            }
        }

        $leaders = [];
        foreach (self::CATEGORIES as $title => $definition) {
            $field = $definition['field'];
            $eligible = array_values(array_filter($players, fn ($p) => ($p[$field] ?? 0) > 0));
            usort($eligible, function ($a, $b) use ($field) {
                return (($b[$field] ?? 0) <=> ($a[$field] ?? 0))
                    ?: (($b['games'] ?? 0) <=> ($a['games'] ?? 0))
                    ?: strcmp($a['name'], $b['name'])
                    ?: ($a['id'] <=> $b['id']);
            });
            $leaders[$title] = ['columns' => $definition['columns'], 'players' => array_slice($eligible, 0, $limit)];
        }

        Cache::forever($this->key($season), [
            'fingerprint' => $this->fingerprint($season),
            'leaders' => $leaders,
            'teams' => $teams,
            'through_week' => (int) ($season->fixtures()->where('status', 'final')->max('week') ?? 0),
        ]);

        return $leaders;
    }
}

