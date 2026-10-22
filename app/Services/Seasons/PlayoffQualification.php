<?php

namespace App\Services\Seasons;

use App\Models\Season;
use Illuminate\Validation\ValidationException;

/** Read-only qualification snapshot. Does not modify saved schedules or statistics. */
class PlayoffQualification
{
    public function snapshot(Season $season): array
    {
        $settings = $season->settings ?? [];
        $format = (string) ($settings['playoffs'] ?? 'none');
        $members = $settings['members'] ?? [];
        if ($format === 'none') {
            return ['format' => $format, 'conferences' => [], 'ready' => false];
        }
        $regularGames = $season->fixtures()->where('status', 'final')->orderBy('id')->get();
        $allGames = $season->fixtures()->count();
        $ready = $allGames > 0 && $regularGames->count() === $allGames
            && in_array($season->phase, ['playoffs_pending', 'playoffs', 'completed'], true);
        $records = [];
        foreach ($members as $id => $member) {
            $records[(int) $id] = ['team_id' => (int) $id, 'name' => $member['name'], 'conference' => $member['conference'],
                'group' => $member['group'], 'wins' => 0, 'losses' => 0, 'ties' => 0, 'pf' => 0, 'pa' => 0,
                'pct' => 0.0, 'opponents' => []];
        }
        foreach ($regularGames as $game) {
            $home = (int) $game->home_team_id;
            $away = (int) $game->away_team_id;
            if (!isset($records[$home], $records[$away])) {
                continue;
            }
            foreach ([[$home, $away, (int) $game->home_score, (int) $game->away_score],
                      [$away, $home, (int) $game->away_score, (int) $game->home_score]] as [$id, $opponent, $for, $against]) {
                $records[$id]['pf'] += $for;
                $records[$id]['pa'] += $against;
                $records[$id][$for === $against ? 'ties' : ($for > $against ? 'wins' : 'losses')]++;
                $records[$id]['opponents'][] = ['id' => $opponent, 'for' => $for, 'against' => $against];
            }
        }
        foreach ($records as &$record) {
            $played = $record['wins'] + $record['losses'] + $record['ties'];
            $record['pct'] = $played ? ($record['wins'] + $record['ties'] / 2) / $played : 0.0;
        }
        unset($record);
        $byConference = [];
        foreach ($records as $row) {
            $key = str_starts_with($format, 'nfl') ? $row['conference'] : 'league';
            $byConference[$key][] = $row;
        }
        $seedsPerConference = match ($format) {
            'nfl10' => 5, 'nfl12' => 6, 'nfl14' => 7,
            default => (int) $format,
        };
        $result = [];
        foreach ($byConference as $conference => $teams) {
            $divisionWinners = [];
            if (str_starts_with($format, 'nfl')) {
                $divisions = [];
                foreach ($teams as $team) {
                    $divisions[$team['group']][] = $team;
                }
                foreach ($divisions as $division) {
                    usort($division, fn ($a, $b) => $this->compare($a, $b));
                    $divisionWinners[] = $division[0];
                }
                usort($divisionWinners, fn ($a, $b) => $this->compare($a, $b));
            }
            $selected = $divisionWinners;
            $selectedIds = array_column($selected, 'team_id');
            $wildcards = array_values(array_filter($teams, fn ($team) => !in_array($team['team_id'], $selectedIds, true)));
            usort($wildcards, fn ($a, $b) => $this->compare($a, $b));
            $selected = array_slice(array_merge($selected, $wildcards), 0, $seedsPerConference);
            foreach ($selected as $index => &$team) {
                $team['seed'] = $index + 1;
                $team['division_champion'] = in_array($team['team_id'], array_column($divisionWinners, 'team_id'), true);
                unset($team['opponents']);
            }
            unset($team);
            $result[$conference] = $selected;
        }
        ksort($result);
        return ['format' => $format, 'conferences' => $result, 'ready' => $ready,
            'tiebreaker_notice' => 'Provisional ordering: win percentage, point differential, points scored, stable team ID. Full head-to-head and NFL multi-team tiebreakers are not implemented.'];
    }

    private function compare(array $a, array $b): int
    {
        return ($b['pct'] <=> $a['pct'])
            ?: (($b['pf'] - $b['pa']) <=> ($a['pf'] - $a['pa']))
            ?: ($b['pf'] <=> $a['pf'])
            ?: ($a['team_id'] <=> $b['team_id']);
    }
}
