<?php

namespace App\Services;

use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamPlayer;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RecursiveIteratorIterator;

class WorldPackages
{
    public const ART = ['team_logo', 'helmet_logo_left', 'helmet_logo_right', 'midfield_logo', 'endzone_logo_left', 'endzone_logo_right', 'jersey_image_dark', 'jersey_image_white', 'game_field_image'];

    public function __construct(public TeamPackages $rosters) {}

    public function assetSlots(): array
    {
        $slots = self::ART;
        foreach (['home', 'away'] as $venue) {
            foreach (['chest', 'sleeve'] as $part) {
                $slots[] = "team_settings.uniform_logos.{$venue}.{$part}";
            }
        }
        foreach (['field_wall', 'home_tunnel', 'away_tunnel'] as $part) {
            $slots[] = "team_settings.stadium.{$part}_art";
        }
        foreach (array_keys(config('team-audio')) as $event) {
            $slots[] = "team_settings.audio.{$event}";
        }

        return array_merge($slots, ['team_settings.field_art.yard25_logo', 'team_settings.championship_endzone.art_left', 'team_settings.championship_endzone.art_right']);
    }

    public function withinWorld(int $id, callable $callback): mixed
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        $context->id = $id;
        try {
            return $callback();
        } finally {
            $context->id = $previous;
        }
    }

    public function export(Team|World $source, ?string $year = null): string
    {
        return $this->withinWorld($source instanceof World ? $source->id : $source->world_id, function () use ($source, $year) {
            $path = $this->newPath();
            $zip = new PharData($path, 0, null, Phar::ZIP);
            try {
                $teams = $source instanceof Team ? collect([$source]) : Team::orderBy('id')->get();
                $manifest = ['format' => 'websports-football', 'version' => 1, 'kind' => $source instanceof Team ? 'team' : 'world',
                    'name' => $source instanceof Team ? trim($source->city.' '.$source->name) : $source->name,
                    'teams' => [], 'leagues' => []];
                if ($source instanceof World) {
                    foreach (League::all() as $league) {
                        $logo = null;
                        if ($league->logo_path) {
                            $logo = $this->addAsset($zip, $league->logo_path, 'assets/league-'.$league->id);
                        }
                        $manifest['leagues'][] = ['key' => 'league-'.$league->id, 'name' => $league->name, 'logo' => $logo];
                    }
                }
                foreach ($teams as $i => $team) {
                    $directory = 'teams/team-'.($i + 1);
                    $data = Arr::except($team->attributesToArray(), ['id', 'world_id', 'league_id', 'created_at', 'updated_at']);
                    $rosterYear = $year ?? (string) ($team->players()->max('team_players.team_year') ?? date('Y'));
                    $players = $team->players()->wherePivot('team_year', $rosterYear)->orderBy('team_players.id')->pluck('players.id')->all();
                    if (! $players) {
                        $this->rosters->fail($team->city.' '.$team->name.' needs at least one player in roster year '.$rosterYear.' before export.');
                    }
                    foreach (['special_teams', 'specialists'] as $key) {
                        $settings = data_get($data, 'team_settings.'.$key, []);
                        data_set($data, 'team_settings.'.$key, $this->mapIds($settings, $players ? array_combine($players, range(1, count($players))) : []));
                    }
                    foreach ($this->assetSlots() as $slot) {
                        $asset = data_get($data, $slot);
                        if ($asset) {
                            data_set($data, $slot, $this->addAsset($zip, $asset, $directory.'/assets/'.Str::slug($slot)));
                        }
                    }
                    $alignment = Season::whereNotNull('settings')->orderByDesc('year')->get()->first(fn ($season) => isset($season->settings['members'][$team->id]));
                    if ($alignment) {
                        $member = $alignment->settings['members'][$team->id];
                        $data['conference'] = $member['conference_name'] ?? $data['conference'];
                        $data['division'] = $member['division'] ?? $data['division'];
                    }
                    $zip->addFromString($directory.'/team.json', $this->json($data));
                    $zip->addFromString($directory.'/roster.csv', $this->rosters->roster($team, $rosterYear));
                    $rosterFiles = [];
                    if ($source instanceof World) {
                        foreach ($team->players()->distinct()->pluck('team_players.team_year')->sort()->values() as $savedYear) {
                            $file = $directory.'/rosters/'.$savedYear.'.csv';
                            $zip->addFromString($file, $this->rosters->roster($team, (string) $savedYear));
                            $rosterFiles[] = ['year' => (int) $savedYear, 'file' => $file];
                        }
                    }
                    $manifest['teams'][] = ['directory' => $directory, 'rosters' => $rosterFiles,
                        'league' => $source instanceof World ? ($team->league_id ? 'league-'.$team->league_id : ($alignment ? 'league-'.$alignment->league_id : ($manifest['leagues'][0]['key'] ?? null))) : null];
                }
                $zip->addFromString('package.json', $this->json($manifest));
                $zip->addFromString('README.txt', "WebSports Football package v1\nEdit team.json and roster.csv inside each team folder. Keep asset references relative to the ZIP root.\nNo game results, injuries or season progress are included. Choose destination year and schedule during import.\nSpecialist assignments use roster row numbers starting at 1.\n");
            } catch (\Throwable $error) {
                unset($zip);
                @unlink($path);
                throw $error;
            }
            unset($zip);

            return $path;
        });
    }

    private function addAsset(PharData $zip, string $source, string $destination): string
    {
        if (! $this->safePath($source) || ! Storage::disk('team_art')->exists($source)) {
            $this->rosters->fail('A referenced graphic or sound is missing: '.$source.'. Repair it in the team editor before exporting.');
        }
        $bytes = Storage::disk('team_art')->get($source);
        $destination .= '.'.strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $this->checkAsset($destination, $bytes);
        $zip->addFromString($destination, $bytes);

        return $destination;
    }

    public function newPath(): string
    {
        Storage::disk('local')->makeDirectory('packages/tmp');

        return Storage::disk('local')->path('packages/tmp/'.Str::uuid().'.zip');
    }

    public function read(string $path): array
    {
        try {
            $zip = new PharData($path);
            $files = [];
            $total = 0;
            foreach (new RecursiveIteratorIterator($zip) as $file) {
                $name = substr($file->getPathname(), strlen('phar://'.$path.'/'));
                if (! $this->safePath($name) || $file->isLink() || count($files) >= 10000) {
                    $this->rosters->fail('The ZIP contains unsafe paths or too many files.');
                }
                $total += $file->getSize();
                if ($total > 300 * 1024 * 1024 || $file->getSize() > 20 * 1024 * 1024) {
                    $this->rosters->fail('The ZIP exceeds 300 MB expanded or contains a file larger than 20 MB.');
                }
                $files[$name] = in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['json', 'csv', 'txt'], true) ? $file->getContent() : ['archive' => $path, 'entry' => $name];
            }
            $manifest = $this->decode($files['package.json'] ?? '', 'package.json');
            Validator::make($manifest, [
                'format' => ['required', 'in:websports-football'], 'version' => ['required', 'integer', 'in:1'],
                'kind' => ['required', 'in:team,world'], 'name' => ['required', 'string', 'max:255'],
                'teams' => ['required', 'array', 'min:1', 'max:128'], 'teams.*.directory' => ['required', 'string', 'distinct'],
                'teams.*.league' => ['nullable', 'string'],
                'teams.*.rosters' => ['sometimes', 'array', 'max:200'],
                'teams.*.rosters.*.year' => ['required', 'integer', 'between:1900,2200'],
                'teams.*.rosters.*.file' => ['required', 'string'], 'leagues' => ['present', 'array', 'max:20'],
                'leagues.*.key' => ['required', 'string', 'max:60', 'distinct'], 'leagues.*.name' => ['required', 'string', 'max:255'],
                'leagues.*.logo' => ['nullable', 'string'],
            ])->validate();
            if ($manifest['kind'] === 'team' && count($manifest['teams']) !== 1) {
                $this->rosters->fail('A team package must contain exactly one team.');
            }
            $teams = [];
            foreach ($manifest['teams'] as $entry) {
                $dir = $entry['directory'];
                if (! $this->safePath($dir)) {
                    $this->rosters->fail('Invalid team directory.');
                }
                if (($entry['league'] ?? null) && ! in_array($entry['league'], array_column($manifest['leagues'], 'key'), true)) {
                    $this->rosters->fail('Unknown league reference in package.json.');
                }
                $data = $this->decode($files[$dir.'/team.json'] ?? '', $dir.'/team.json');
                $this->validateTeam($data);
                foreach ($this->assetSlots() as $slot) {
                    if ($asset = data_get($data, $slot)) {
                        $this->requireAsset($asset, $files);
                    }
                }
                $rows = $this->rosters->readRoster($files[$dir.'/roster.csv'] ?? '');
                foreach (['special_teams', 'specialists'] as $key) {
                    $this->mapIds(data_get($data, 'team_settings.'.$key, []), array_combine(range(1, count($rows)), range(1, count($rows))));
                }
                $rosters = [];
                foreach (($entry['rosters'] ?? []) as $rosterEntry) {
                    if (! $this->safePath($rosterEntry['file']) || isset($rosters[$rosterEntry['year']])) {
                        $this->rosters->fail('Invalid or duplicate roster year in package.json.');
                    }
                    $rosters[$rosterEntry['year']] = $this->rosters->readRoster($files[$rosterEntry['file']] ?? '');
                }
                $teams[] = ['data' => $data, 'roster' => $rows, 'rosters' => $rosters];
            }
            foreach ($manifest['leagues'] as $league) {
                if ($league['logo'] ?? null) {
                    $this->requireAsset($league['logo'], $files);
                }
            }

            return ['manifest' => $manifest, 'teams' => $teams, 'files' => $files];
        } catch (\Illuminate\Validation\ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->rosters->fail('Unable to read this ZIP. Use the documented package layout and a standard ZIP archive.');
        }
    }

    private function validateTeam(array $data): void
    {
        $allowed = array_diff(Schema::getColumnListing('teams'), ['id', 'world_id', 'league_id', 'created_at', 'updated_at']);
        if (array_diff(array_keys($data), $allowed)) {
            $this->rosters->fail('team.json contains unknown fields or database identifiers.');
        }
        $rules = ['city' => ['required', 'string', 'max:255'], 'name' => ['required', 'string', 'max:255'],
            'team_settings' => ['sometimes', 'array'], 'team_settings.*' => ['nullable']];
        foreach (Schema::getColumns('teams') as $column) {
            $key = $column['name'];
            if (in_array($key, ['city', 'name', 'team_settings'], true) || ! in_array($key, $allowed, true)) {
                continue;
            }
            $type = strtolower($column['type_name']);
            $rules[$key] = str_contains($type, 'int') || $type === 'boolean'
                ? ['nullable', 'integer', 'between:-10000,10000'] : ['nullable', 'string', 'max:255'];
            if (str_contains($key, 'color') || preg_match('/^uniform_.*_(helmet|shirt|pants|socks|number|number_outline|stripe|facemask)$/', $key)) {
                $rules[$key] = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
            }
        }
        $rules['conference'] = ['nullable', 'string', 'max:40'];
        $rules['division'] = ['nullable', 'string', 'max:40'];
        $rules['endzone_text'] = ['nullable', 'string', 'max:40'];
        Validator::make($data, $rules)->validate();
        if (strlen($this->json($data['team_settings'] ?? [])) > 100000) {
            $this->rosters->fail('Team settings are too large.');
        }
        $settings = $data['team_settings'] ?? [];
        foreach (['stadium', 'coaching', 'staff', 'cheerleaders', 'mascot', 'helmets', 'specialists', 'special_teams', 'championship_endzone', 'uniform_patterns', 'uniform_stripe_colors', 'uniform_logos', 'field_art', 'audio', 'nfl_import'] as $section) {
            if (isset($settings[$section]) && ! is_array($settings[$section])) {
                $this->rosters->fail('team_settings.'.$section.' must be a JSON object.');
            }
        }
        foreach (($settings['coaching'] ?? []) as $value) {
            if (! is_numeric($value) || $value < 0 || $value > 100) {
                $this->rosters->fail('Coaching settings must be numbers from 0 to 100.');
            }
        }
        foreach (['specialists', 'special_teams'] as $section) {
            $this->validateAssignments($settings[$section] ?? []);
        }
        foreach ($this->assetSlots() as $slot) {
            $value = data_get($data, $slot);
            if ($value !== null && ! is_string($value)) {
                $this->rosters->fail('Invalid asset reference: '.$slot);
            }
        }
    }

    private function teamDefaults(): array
    {
        $defaults = [];
        foreach (Schema::getColumns('teams') as $column) {
            if (in_array($column['name'], ['id', 'world_id', 'league_id', 'created_at', 'updated_at', 'city', 'name'], true)) {
                continue;
            }
            $value = $column['default'];
            if (is_string($value)) {
                $value = trim($value, "'\"");
                if (strtoupper($value) === 'NULL') {
                    $value = null;
                }
            }
            $defaults[$column['name']] = $value;
        }
        $defaults['team_settings'] = [];

        return $defaults;
    }

    public function import(array $package, int $year, ?Team $replace = null, bool $preserveYears = false): array
    {
        $written = [];
        try {
            return DB::transaction(function () use ($package, $year, $replace, $preserveYears, &$written) {
                $teams = [];
                foreach ($package['teams'] as $entry) {
                    $data = array_replace($this->teamDefaults(), $entry['data']);
                    if ($replace) {
                        $data['conference'] = $replace->conference;
                        $data['division'] = $replace->division;
                    }
                    $team = $replace ?? Team::create(['city' => $data['city'], 'name' => $data['name']]);
                    foreach ($this->assetSlots() as $slot) {
                        if ($asset = data_get($data, $slot)) {
                            $destination = 'teams/'.$team->id.'/imports/'.Str::uuid().'.'.pathinfo($asset, PATHINFO_EXTENSION);
                            Storage::disk('team_art')->put($destination, $this->assetBytes($package['files'][$asset]));
                            $written[] = $destination;
                            data_set($data, $slot, $destination);
                        }
                    }
                    $primaryYear = $year;
                    if ($preserveYears && ! empty($entry['rosters'])) {
                        foreach ($entry['rosters'] as $savedYear => $rows) {
                            $this->rosters->importRoster($team, $rows, (int) $savedYear, false);
                        }
                        $primaryYear = (int) ($entry['roster'][0]['source_year'] ?? max(array_keys($entry['rosters'])));
                        if (! isset($entry['rosters'][$primaryYear])) {
                            $primaryYear = max(array_keys($entry['rosters']));
                        }
                    } else {
                        $this->rosters->importRoster($team, $entry['roster'], $year, $replace !== null);
                    }
                    $ids = TeamPlayer::where('team_id', $team->id)->where('team_year', (string) $primaryYear)->orderBy('id')->pluck('player_id')->all();
                    foreach (['special_teams', 'specialists'] as $key) {
                        data_set($data, 'team_settings.'.$key, $this->mapIds(data_get($data, 'team_settings.'.$key, []), array_combine(range(1, count($ids)), $ids)));
                    }
                    $team->update($data);
                    $teams[] = $team;
                }

                return $teams;
            });
        } catch (\Throwable $error) {
            Storage::disk('team_art')->delete($written);
            throw $error;
        }
    }

    public function importWorld(array $package, string $name, int $owner, int $year): World
    {
        $written = [];
        try {
            return DB::transaction(function () use ($package, $name, $owner, $year, &$written) {
                $world = World::create(['name' => $name, 'owner_user_id' => $owner]);
                $this->withinWorld($world->id, function () use ($package, $year, &$written) {
                    $leagueIds = [];
                    foreach ($package['manifest']['leagues'] as $entry) {
                        $league = League::create(['name' => $entry['name']]);
                        $leagueIds[$entry['key']] = $league->id;
                        if ($logo = $entry['logo'] ?? null) {
                            $destination = 'leagues/'.$league->id.'/'.Str::uuid().'.'.pathinfo($logo, PATHINFO_EXTENSION);
                            Storage::disk('team_art')->put($destination, $this->assetBytes($package['files'][$logo]));
                            $written[] = $destination;
                            $league->update(['logo_path' => $destination]);
                        }
                    }
                    if (! League::exists()) {
                        League::create(['name' => 'Football League']);
                    }
                    $teams = $this->import($package, $year, preserveYears: true);
                    foreach ($teams as $index => $team) {
                        $key = $package['manifest']['teams'][$index]['league'] ?? null;
                        $team->update(['league_id' => $leagueIds[$key] ?? League::value('id')]);
                        foreach ($this->assetSlots() as $slot) {
                            if ($asset = data_get($team->attributesToArray(), $slot)) {
                                $written[] = $asset;
                            }
                        }
                    }
                });

                return $world;
            });
        } catch (\Throwable $error) {
            Storage::disk('team_art')->delete($written);
            throw $error;
        }
    }

    private function validateAssignments(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->validateAssignments($value);
            } elseif ($value !== null && $value !== '' && (! is_numeric($value) || $value < 1 || $value > 200)) {
                $this->rosters->fail('Specialist assignments must reference roster row numbers from 1 to 200.');
            }
        }
    }

    private function mapIds(array $values, array $map): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = is_array($value) ? $this->mapIds($value, $map) : ($value === null || $value === '' ? null : ($map[$value] ?? null));
        }

        return $values;
    }

    private function requireAsset(string $path, array $files): void
    {
        if (! $this->safePath($path) || ! isset($files[$path])) {
            $this->rosters->fail('Missing package asset: '.$path);
        }
        $this->checkAsset($path, $this->assetBytes($files[$path]));
    }

    private function assetBytes(array|string $asset): string
    {
        if (is_string($asset)) {
            return $asset;
        }
        $zip = new PharData($asset['archive']);

        return $zip[$asset['entry']]->getContent();
    }

    private function checkAsset(string $path, string $bytes): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            $size = @getimagesizefromstring($bytes);
            if (! $size || $size[0] > 8192 || $size[1] > 8192 || ! in_array($size['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                $this->rosters->fail('Invalid image: '.$path);
            }
        } elseif ($extension === 'svg') {
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $valid = $document->loadXML($bytes, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if (! $valid || $document->doctype || (new \DOMXPath($document))->query('//processing-instruction()')->length || $document->documentElement->localName !== 'svg') {
                $this->rosters->fail('Invalid SVG: '.$path);
            }
            $allowed = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'title', 'desc', 'defs', 'clipPath', 'linearGradient', 'radialGradient', 'stop', 'text', 'tspan'];
            foreach ($document->getElementsByTagName('*') as $element) {
                if (! in_array($element->localName, $allowed, true)) {
                    $this->rosters->fail('Unsupported SVG element in '.$path.'. Use a PNG instead.');
                }
                foreach ($element->attributes as $attribute) {
                    if (preg_match('/^on|href|src|style/i', $attribute->localName) || preg_match('/url\s*\(\s*["\x27]?(?!#)/i', $attribute->value)) {
                        $this->rosters->fail('External or active SVG content is not supported: '.$path);
                    }
                }
            }
        } elseif (in_array($extension, ['mp3', 'wav', 'ogg'], true)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (! in_array($mime, ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'application/ogg'], true)) {
                $this->rosters->fail('Invalid audio: '.$path);
            }
        } else {
            $this->rosters->fail('Unsupported asset type: '.$path);
        }
    }

    private function safePath(string $path): bool
    {
        return strlen($path) <= 240 && preg_match('~^[A-Za-z0-9_./-]+$~D', $path)
            && ! str_starts_with($path, '/') && ! in_array('..', explode('/', $path), true)
            && ! in_array('.', explode('/', $path), true) && ! str_contains($path, '//');
    }

    private function decode(string $text, string $name): array
    {
        $data = json_decode($text, true);
        if (! is_array($data)) {
            $this->rosters->fail('Missing or invalid JSON: '.$name);
        }

        return $data;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
