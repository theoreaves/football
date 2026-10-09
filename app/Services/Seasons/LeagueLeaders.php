<?php

namespace App\Services\Seasons;

use App\Models\Season;

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

    public function forSeason(Season $season, int $limit = 10): array
    {
        $members = $season->settings['members'] ?? [];
        $players = [];
        $stats = app(SeasonStats::class);
        foreach ($members as $teamId => $member) {
            foreach ($stats->team($season, (int) $teamId)['players'] as $person) {
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

        return $leaders;
    }
}
