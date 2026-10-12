<?php

namespace App\Services\Simulation;

use App\Models\Player;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\User;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

class NflRosterImport
{
    public const YEAR = 2026;

    public const METHOD = 'nfl-depth-physical-v1 (estimated, not official NFL ratings)';

    public function download(callable $progress): array
    {
        $bundle = ['schema' => 1, 'season' => self::YEAR, 'captured_at' => now()->toIso8601String(), 'teams' => []];
        foreach (config('nfl-import') as $id => $identity) {
            $progress($identity['city'].' '.$identity['name']);
            $base = "https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams/{$id}";
            $bundle['teams'][(string) $id] = [
                'roster' => Http::acceptJson()->connectTimeout(10)->timeout(40)->retry(2, 500)->get($base.'/roster', ['season' => self::YEAR])->throw()->json(),
                'depth' => Http::acceptJson()->connectTimeout(10)->timeout(40)->retry(2, 500)->get($base.'/depthcharts', ['season' => self::YEAR])->throw()->json(),
            ];
        }

        return $bundle;
    }

    public function prepare(array $bundle, bool $practice = false): array
    {
        if (($bundle['schema'] ?? null) !== 1 || (int) ($bundle['season'] ?? 0) !== self::YEAR || ! is_array($bundle['teams'] ?? null)
            || count($bundle['teams']) !== 32) {
            throw new LogicException('Expected a complete 2026 NFL roster snapshot (schema 1, all 32 teams).');
        }
        $prepared = [];
        $used = [];
        foreach (config('nfl-import') as $id => $identity) {
            $source = $bundle['teams'][$id] ?? [];
            foreach (['roster', 'depth'] as $kind) {
                if ((int) ($source[$kind]['season']['year'] ?? 0) !== self::YEAR || (string) ($source[$kind]['team']['id'] ?? '') !== (string) $id) {
                    throw new LogicException("{$identity['name']}: {$kind} did not return the requested 2026 team. Nothing imported.");
                }
            }
            if (! is_array($source['depth']['depthchart'] ?? null) || $source['depth']['depthchart'] === []) {
                throw new LogicException("{$identity['name']}: no current depth chart was returned.");
            }
            $depth = [];
            foreach ($source['depth']['depthchart'] as $unit) {
                foreach ($unit['positions'] ?? [] as $role => $slot) {
                    // Return/holder depth is not a player's offensive/defensive rank.
                    if (in_array(strtolower($role), ['h', 'pr', 'kr', 'ls'], true)) {
                        continue;
                    }
                    foreach ($slot['athletes'] ?? [] as $rank => $athlete) {
                        $key = (string) ($athlete['id'] ?? '');
                        if ($key !== '') {
                            $depth[$key] = min($depth[$key] ?? 99, $rank);
                        }
                    }
                }
            }
            $entries = [];
            foreach ($source['roster']['athletes'] ?? [] as $unit) {
                foreach ($unit['items'] ?? [] as $athlete) {
                    $status = strtolower($athlete['status']['type'] ?? '');
                    if (in_array($status, ['inactive', 'released', 'suspended', 'retired'], true)) {
                        continue;
                    }
                    if (! $practice && $status === 'practice-squad') {
                        continue;
                    }
                    $key = (string) ($athlete['id'] ?? '');
                    if ($key === '' || isset($used[$key])) {
                        throw new LogicException('Missing or repeated source player ID: '.$key);
                    }
                    $position = $this->position($athlete['position']['abbreviation'] ?? '');
                    $first = trim($athlete['firstName'] ?? '');
                    $last = trim($athlete['lastName'] ?? '');
                    $height = (int) round($athlete['height'] ?? 0);
                    $weight = (int) round($athlete['weight'] ?? 0);
                    $age = (int) ($athlete['age'] ?? 0);
                    $jersey = $athlete['jersey'] ?? null;
                    if ($first === '' || $last === '' || mb_strlen($first) > 255 || mb_strlen($last) > 255 || $height < 60 || $height > 90 || $weight < 140 || $weight > 450 || $age < 18 || $age > 55) {
                        throw new LogicException("{$identity['name']}: missing/invalid physical data for {$first} {$last}.");
                    }
                    if ($jersey !== null && (! ctype_digit((string) $jersey) || (int) $jersey > 99)) {
                        throw new LogicException('Invalid source jersey number for '.$first.' '.$last);
                    }
                    // Missing practice-squad numbers remain unknown, never fabricated.
                    $rank = $depth[$key] ?? 99;
                    $used[$key] = true;
                    $entries[] = ['id' => $key, 'rank' => $rank, 'player' => [
                        'firstname' => $first, 'lastname' => $last, 'age' => $age, 'position' => $position,
                        'height_inches' => $height, 'weight_pounds' => $weight,
                        'simulation_ratings' => $this->ratings($athlete, $position, $rank),
                        'appearance' => ['nfl_import' => ['source' => 'ESPN', 'source_id' => $key,
                            'source_position' => $athlete['position']['abbreviation'], 'source_status' => $status,
                            'source_jersey' => $jersey, 'source_experience' => $athlete['experience']['years'] ?? null,
                            'captured_at' => $bundle['captured_at'] ?? null, 'ratings_method' => self::METHOD]],
                    ], 'roster' => ['position' => $position, 'jersey_number' => $jersey === null ? null : (int) $jersey]];
                }
            }
            usort($entries, fn ($a, $b) => [$a['rank'], $a['player']['lastname'], $a['id']] <=> [$b['rank'], $b['player']['lastname'], $b['id']]);
            $counts = [];
            $numbers = [];
            foreach ($entries as &$entry) {
                $number = $entry['roster']['jersey_number'];
                if ($number !== null && isset($numbers[$number])) {
                    $entry['roster']['jersey_number'] = null;
                } elseif ($number !== null) {
                    $numbers[$number] = true;
                }
                $position = $entry['player']['position'];
                $entry['roster']['depth_chart_position'] = $position.(($counts[$position] ?? 0) + 1);
                $counts[$position] = ($counts[$position] ?? 0) + 1;
            }
            unset($entry);
            foreach (['QB' => 1, 'RB' => 1, 'WR' => 3, 'TE' => 1, 'OL' => 5, 'DL' => 4, 'LB' => 3, 'CB' => 2, 'S' => 2, 'K' => 1, 'P' => 1] as $position => $minimum) {
                if (($counts[$position] ?? 0) < $minimum) {
                    throw new LogicException("{$identity['name']} needs {$minimum} {$position} players to field a playable roster; source returned too few.");
                }
            }
            $prepared[$id] = ['identity' => $identity, 'players' => $entries, 'coach' => trim(($source['roster']['coach'][0]['firstName'] ?? '').' '.($source['roster']['coach'][0]['lastName'] ?? ''))];
        }

        return $prepared;
    }

    private function position(string $position): string
    {
        return match (strtoupper($position)) {
            'QB' => 'QB', 'RB','FB' => 'RB', 'WR' => 'WR', 'TE' => 'TE',
            'C','G','OT','T','OL','LS','LG','RG','LT','RT' => 'OL',
            'DE','DT','NT','DL','EDGE' => 'DL', 'LB','ILB','OLB','MLB' => 'LB',
            'CB','DB' => 'CB', 'S','SS','FS' => 'S', 'PK','K' => 'K', 'P' => 'P',
            default => throw new LogicException('Unsupported source position: '.$position),
        };
    }

    public function ratings(array $athlete, string $position, int $rank): array
    {
        $specialties = match ($position) {
            'QB' => ['throwing', 'awareness', 'ball_security'], 'RB' => ['speed', 'acceleration', 'ball_security'],
            'WR' => ['speed', 'acceleration', 'catching', 'target_preference'], 'TE' => ['catching', 'blocking', 'strength'],
            'OL' => ['blocking', 'strength', 'awareness'], 'DL' => ['tackling', 'strength', 'acceleration'],
            'LB' => ['tackling', 'awareness', 'strength'], 'CB' => ['coverage', 'speed', 'acceleration'],
            'S' => ['coverage', 'tackling', 'awareness'], 'K','P' => ['kicking', 'awareness'], default => [],
        };
        $base = match (true) {
            $rank === 0 => 84, $rank === 1 => 74, $rank === 2 => 66, default => 60
        };
        $values = [];
        foreach (PlayerRatings::FIELDS as $field) {
            $jitter = (int) sprintf('%u', crc32('nfl-estimate-v1:'.$athlete['id'].':'.$field)) % 13 - 6;
            $value = $base + $jitter - (in_array($field, $specialties, true) ? 0 : 22);
            if ($field === 'speed' || $field === 'acceleration') {
                $speed = match ($position) {
                    'WR','CB' => 88, 'RB','S' => 85, 'LB' => 77, 'TE' => 75, 'QB' => 69, 'DL' => 65, 'OL' => 49, default => 57
                };
                $value = $speed + $jitter - max(0, ((int) ($athlete['weight'] ?? 215) - 240) / 15) + ($field === 'acceleration' ? 2 : 0);
            }
            if ($field === 'strength') {
                $value = 48 + (((int) ($athlete['weight'] ?? 215) - 160) / 3) + $jitter;
            }
            if ($field === 'awareness') {
                $value += min(10, (int) ($athlete['experience']['years'] ?? 0)) - 3;
            }
            if ($field === 'stamina') {
                $value = 82 + $jitter;
            }
            if ($field === 'durability') {
                $value = 82 + $jitter;
            } // No injury history is inferred from identity.
            $values[$field] = max(10, min(99, (int) round($value)));
        }

        return $values;
    }

    public function create(User $owner, string $name, array $prepared, string $captured, bool $logos): World
    {
        $logoBytes = [];
        if ($logos) {
            foreach ($prepared as $data) {
                $identity = $data['identity'];
                $url = 'https://a.espncdn.com/i/teamlogos/nfl/500/'.strtolower($identity['abbr']).'.png';
                $bytes = Http::connectTimeout(10)->timeout(30)->retry(2, 500)->get($url)->throw()->body();
                $image = @getimagesizefromstring($bytes);
                if (! $image || ($image['mime'] ?? '') !== 'image/png' || strlen($bytes) > 5 * 1024 * 1024) {
                    throw new LogicException('Invalid logo response for '.$identity['name']);
                }
                $logoBytes[$identity['abbr']] = $bytes;
            }
        }
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        $written = [];
        try {
            return DB::transaction(function () use ($owner, $name, $prepared, $captured, $logos, $logoBytes, $context, &$written) {
                $world = World::create(['name' => $name, 'owner_user_id' => $owner->id]);
                $context->id = (int) $world->id;
                $league = $world->leagues()->create(['name' => 'NFL']);
                $league->seasons()->create(['year' => self::YEAR]);
                foreach ($prepared as $data) {
                    $identity = $data['identity'];
                    $entry = config('pro-football.teams')[$identity['template']];
                    [$city,$fiction,$abbr,$conference,$division,$primary,$secondary,$accent,$helmet,$pants] = $entry;
                    $team = Team::create(array_merge(app(ProTeamAppearance::class)->defaults($entry), [
                        'city' => $identity['city'], 'name' => $identity['name'], 'abbr' => $identity['abbr'], 'conference' => $conference, 'division' => $division,
                        'team_color1' => $primary, 'team_color2' => $secondary,
                        'uniform_home_helmet' => $helmet, 'uniform_away_helmet' => $helmet,
                        'uniform_home_shirt' => $primary, 'uniform_away_shirt' => '#ffffff',
                        'uniform_home_pants' => $pants, 'uniform_away_pants' => $pants,
                        'uniform_home_socks' => $primary, 'uniform_away_socks' => $primary,
                        'uniform_home_facemask' => $primary, 'uniform_away_facemask' => $primary,
                        'uniform_home_number' => '#ffffff', 'uniform_away_number' => $primary,
                        'uniform_home_number_outline' => $secondary, 'uniform_away_number_outline' => $secondary,
                        'endzone_text' => strtoupper($identity['name']), 'endzone_background' => $primary, 'endzone_text_color' => '#ffffff',
                        'team_settings' => ['white_at_home' => in_array($identity['abbr'], ['DAL', 'MIA'], true), 'staff' => ['head' => ['name' => $data['coach']]],
                            'nfl_import' => ['season' => self::YEAR, 'source' => 'ESPN', 'captured_at' => $captured, 'ratings_method' => self::METHOD]],
                    ]));
                    if ($logos) {
                        $bytes = $logoBytes[$identity['abbr']];
                        $path = "teams/{$team->id}/nfl-logo.png";
                        $written[] = $path;
                        if (! Storage::disk('team_art')->put($path, $bytes)) {
                            throw new LogicException('Could not save '.$identity['name'].' logo.');
                        }
                        $team->update(array_fill_keys(['team_logo', 'helmet_logo_left', 'helmet_logo_right', 'midfield_logo'], $path));
                    }
                    $written = array_merge($written, app(NflHelmetDefaults::class)->apply($team, false, $logos));
                    foreach ($data['players'] as $entry) {
                        $player = Player::create($entry['player']);
                        TeamPlayer::create($entry['roster'] + ['team_id' => $team->id, 'player_id' => $player->id, 'team_year' => (string) self::YEAR]);
                    }
                }

                return $world;
            });
        } catch (Throwable $error) {
            if ($written !== []) {
                Storage::disk('team_art')->delete($written);
            }
            throw $error;
        } finally {
            $context->id = $previous;
        }
    }
}
