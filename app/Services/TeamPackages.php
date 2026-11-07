<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Services\Simulation\PlayerRatings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TeamPackages
{
    public const PLAYER_FIELDS = ['firstname', 'lastname', 'age', 'position', 'height_inches', 'weight_pounds', 'skin_tone', 'appearance'];

    public const PIVOT_FIELDS = ['depth_chart_position', 'jersey_number'];

    public function headers(): array
    {
        return array_merge(self::PLAYER_FIELDS, ['team_year'], self::PIVOT_FIELDS,
            array_map(fn ($field) => 'rating_'.$field, PlayerRatings::FIELDS));
    }

    public function roster(Team $team, ?string $year = null): string
    {
        $year ??= (string) ($team->players()->max('team_players.team_year') ?? date('Y'));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $this->headers(), ',', '"', '');
        $players = $team->players()->wherePivot('team_year', $year)
            ->withPivot(self::PIVOT_FIELDS)->orderBy('team_players.id')->get();
        foreach ($players as $player) {
            $row = [];
            foreach (self::PLAYER_FIELDS as $field) {
                $row[$field] = $field === 'appearance' ? json_encode($player->$field ?? (object) [], JSON_UNESCAPED_SLASHES) : $player->$field;
            }
            $lineman = in_array(strtoupper($player->pivot->position ?: $player->position), ['C', 'G', 'T', 'OL', 'LG', 'RG', 'LT', 'RT', 'DT', 'NT'], true);
            $row['height_inches'] ??= $lineman ? 76 : 72;
            $row['weight_pounds'] ??= $lineman ? 305 : 215;
            $row['skin_tone'] ??= ['#edc5a3', '#c78e61', '#8c5536', '#593b2c'][$player->id % 4];
            $row['position'] = $player->pivot->position ?: $player->position;
            $row['team_year'] = $year;
            foreach (self::PIVOT_FIELDS as $field) {
                $row[$field] = $player->pivot->$field;
            }
            foreach (app(PlayerRatings::class)->forPlayer($player) as $field => $value) {
                if (in_array($field, PlayerRatings::FIELDS, true)) {
                    $row['rating_'.$field] = $value;
                }
            }
            fputcsv($stream, array_map(fn ($key) => $this->csvCell($row[$key] ?? ''), $this->headers()), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function csvCell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value)) {
            return "'".$value;
        }

        return $value;
    }

    public function readRoster(string $csv): array
    {
        if (strlen($csv) > 5 * 1024 * 1024 || ! mb_check_encoding($csv, 'UTF-8')) {
            $this->fail('Use a UTF-8 CSV file smaller than 5 MB.');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        try {
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if (! is_array($headers) || count($headers) !== count(array_unique($headers)) || array_diff($headers, $this->headers())) {
                $this->fail('The CSV has duplicate or unknown columns. Download a roster or starter CSV for the supported headers.');
            }
            foreach (['firstname', 'lastname', 'age', 'position', 'depth_chart_position'] as $required) {
                if (! in_array($required, $headers, true)) {
                    $this->fail("Missing CSV column: {$required}.");
                }
            }
            $rows = [];
            $jerseys = [];
            $line = 1;
            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers) || count($rows) >= 200) {
                    $this->fail("CSV row {$line}: wrong number of cells or more than 200 players.");
                }
                $row = array_combine($headers, array_map(function ($value) {
                    $value = trim($value);
                    if (preg_match("/^'[\\s]*[=+@-]/u", $value)) {
                        $value = substr($value, 1);
                    }

                    return $value === '' ? null : $value;
                }, $values));
                $rules = [
                    'firstname' => ['required', 'string', 'max:255'], 'lastname' => ['required', 'string', 'max:255'],
                    'age' => ['required', 'integer', 'between:18,99'], 'position' => ['required', 'string', 'max:10'],
                    'depth_chart_position' => ['required', 'string', 'max:10'], 'jersey_number' => ['nullable', 'integer', 'between:0,99'],
                    'team_year' => ['nullable', 'integer', 'between:1900,2200'],
                    'height_inches' => ['nullable', 'integer', 'between:48,96'], 'weight_pounds' => ['nullable', 'integer', 'between:90,450'],
                    'skin_tone' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                ];
                foreach (PlayerRatings::FIELDS as $field) {
                    $rules['rating_'.$field] = ['nullable', 'integer', 'between:1,99'];
                }
                foreach (array_diff(self::PIVOT_FIELDS, ['depth_chart_position', 'jersey_number']) as $field) {
                    $rules[$field] = str_contains($field, 'depth_chart') ? ['nullable', 'string', 'max:10'] : ['nullable', 'integer', 'between:0,10000'];
                }
                $validator = Validator::make($row, $rules);
                if ($validator->fails()) {
                    $this->fail("CSV row {$line}: ".implode(' ', $validator->errors()->all()));
                }
                $appearance = [];
                if ($row['appearance'] ?? null) {
                    $appearance = json_decode($row['appearance'], true);
                    if (! is_array($appearance) || array_is_list($appearance) && $appearance !== []) {
                        $this->fail("CSV row {$line}: appearance must be a JSON object.");
                    }
                    Validator::make(['appearance' => $appearance], [
                        'appearance' => ['array:head_shape,eye_color,hair_color,hair,brow,nose,mouth,beard,throwing_hand,nfl_import'],
                        'appearance.*' => ['nullable'], 'appearance.nfl_import' => ['sometimes', 'array'],
                        'appearance.eye_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                        'appearance.hair_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    ])->validate();
                }
                $jersey = $row['jersey_number'] ?? null;
                if ($jersey !== null && isset($jerseys[(int) $jersey])) {
                    $this->fail("CSV row {$line}: duplicate jersey number {$jersey}.");
                }
                if ($jersey !== null) {
                    $jerseys[(int) $jersey] = true;
                }
                $player = array_intersect_key($row, array_flip(self::PLAYER_FIELDS));
                $player['appearance'] = $appearance;
                $player['simulation_ratings'] = [];
                foreach (PlayerRatings::FIELDS as $field) {
                    if (($row['rating_'.$field] ?? null) !== null) {
                        $player['simulation_ratings'][$field] = (int) $row['rating_'.$field];
                    }
                }
                $pivot = array_intersect_key($row, array_flip(self::PIVOT_FIELDS));
                $rows[] = ['player' => $player, 'pivot' => $pivot, 'source_year' => $row['team_year'] ?? null];
            }
            if (! $rows) {
                $this->fail('The CSV must contain at least one player.');
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function importRoster(Team $team, array $rows, int $year, bool $replace): int
    {
        return DB::transaction(function () use ($team, $rows, $year, $replace) {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $existing = TeamPlayer::where('team_id', $team->id)->where('team_year', (string) $year);
            $replacedIds = [];
            if ($replace) {
                $replacedIds = (clone $existing)->pluck('player_id')->all();
                $existing->delete();
            } else {
                $numbers = $existing->whereNotNull('jersey_number')->pluck('jersey_number')->map(fn ($number) => (int) $number)->all();
                foreach ($rows as $i => $row) {
                    if (($row['pivot']['jersey_number'] ?? null) !== null && in_array((int) $row['pivot']['jersey_number'], $numbers, true)) {
                        $this->fail('CSV row '.($i + 2).': jersey number already exists in the destination roster.');
                    }
                }
            }
            foreach ($rows as $row) {
                $player = Player::create($row['player']);
                $pivot = $row['pivot'];
                TeamPlayer::create($pivot + ['team_id' => $team->id, 'player_id' => $player->id,
                    'team_year' => (string) $year, 'position' => $player->position]);
            }
            if ($replace) {
                $settings = $team->team_settings ?? [];
                foreach (['special_teams', 'specialists'] as $key) {
                    if (isset($settings[$key])) {
                        $settings[$key] = $this->clearAssignments($settings[$key], $replacedIds);
                    }
                }
                $team->update(['team_settings' => $settings]);
                foreach (\App\Models\Season::where('year', $year)->whereNotNull('settings')->get() as $season) {
                    $seasonSettings = $season->settings;
                    foreach (['depth_charts', 'specialists', 'special_teams'] as $key) {
                        unset($seasonSettings[$key][$team->id]);
                    }
                    $season->update(['settings' => $seasonSettings]);
                }
            }

            return count($rows);
        });
    }

    private function clearAssignments(array $assignments, array $ids): array
    {
        foreach ($assignments as $key => $value) {
            $assignments[$key] = is_array($value) ? $this->clearAssignments($value, $ids) : (in_array((int) $value, $ids, true) ? null : $value);
        }

        return $assignments;
    }

    public function starterRoster(): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $this->headers(), ',', '"', '');
        $example = ['firstname' => 'Example', 'lastname' => 'Quarterback', 'age' => 25, 'position' => 'QB',
            'depth_chart_position' => 'QB1', 'jersey_number' => 12, 'team_year' => 2026, 'appearance' => '{}'];
        foreach (PlayerRatings::FIELDS as $field) {
            $example['rating_'.$field] = 70;
        }
        fputcsv($stream, array_map(fn ($field) => $example[$field] ?? '', $this->headers()), ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['package' => $message]);
    }
}
