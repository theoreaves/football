<?php

namespace App\Services\Seasons;

use App\Models\Season;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates an immutable opening-round seed and matchup snapshot. Games are not enabled yet. */
class PlayoffBracket
{
    public function create(Season $season): array
    {
        return DB::transaction(function () use ($season) {
            $season = Season::whereKey($season->id)->lockForUpdate()->firstOrFail();
            abort_unless($season->phase === 'playoffs_pending', 409, 'Finish the regular season first.');
            $settings = $season->settings;
            if (isset($settings['playoff_bracket'])) {
                return $settings['playoff_bracket'];
            }
            $qualification = app(PlayoffQualification::class)->snapshot($season);
            if (! ($qualification['ready'] ?? false) || ($qualification['format'] ?? 'none') === 'none') {
                throw ValidationException::withMessages(['playoffs' => 'Complete every regular-season game first.']);
            }
            $bracket = $this->openingRound($qualification);
            $settings['playoff_bracket'] = $bracket;
            $season->update(['settings' => $settings]);
            return $bracket;
        }, 3);
    }

    public function openingRound(array $qualification): array
    {
        $format = (string) $qualification['format'];
        $conferences = $qualification['conferences'];
        $result = ['version' => 1, 'format' => $format, 'status' => 'opening_round', 'conferences' => []];
        foreach ($conferences as $conference => $entries) {
            $teams = collect($entries)->sortBy('seed')->values()->all();
            $count = count($teams);
            $expected = match ($format) {
                'nfl10' => 5, 'nfl12' => 6, 'nfl14' => 7,
                default => (int) $format,
            };
            if ($count !== $expected || $count < 2) {
                throw ValidationException::withMessages(['playoffs' => 'Incomplete conference seed list.']);
            }
            $byes = match ($format) {
                'nfl10' => 3, 'nfl12' => 2, 'nfl14' => 1,
                default => 0,
            };
            if ($byes === 0 && ($count & ($count - 1)) !== 0) {
                throw ValidationException::withMessages(['playoffs' => 'Unsupported opening-round size.']);
            }
            $byeEntries = array_slice($teams, 0, $byes);
            $playing = array_slice($teams, $byes);
            $games = [];
            while (count($playing) >= 2) {
                $home = array_shift($playing);
                $away = array_pop($playing);
                $games[] = ['home_seed' => (int) $home['seed'], 'away_seed' => (int) $away['seed'],
                    'home_team_id' => (int) $home['team_id'], 'away_team_id' => (int) $away['team_id'],
                    'status' => 'scheduled'];
            }
            $result['conferences'][$conference] = ['seeds' => array_map(fn ($entry) => ['seed' => (int) $entry['seed'],
                'team_id' => (int) $entry['team_id'], 'name' => $entry['name']], $teams),
                'byes' => array_map(fn ($entry) => (int) $entry['team_id'], $byeEntries), 'games' => $games];
        }
        // Ordinary brackets have one league; NFL brackets have separate conference paths.
        return $result;
    }
}
