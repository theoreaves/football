<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackageRequest;
use App\Models\CommunityPackage;
use App\Models\Team;
use App\Models\World;
use App\Services\TeamPackages;
use App\Services\WorldPackages;
use App\Support\CurrentWorld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PackageController extends Controller
{
    public function __construct(public TeamPackages $rosters, public WorldPackages $packages) {}

    public function index(Request $request): View
    {
        $worlds = World::where('owner_user_id', $request->user()->id)->get();
        $sources = Team::withoutGlobalScope('world')->whereIn('world_id', $worlds->pluck('id'))->orderBy('city')->get();

        return view('packages.index', [
            'worlds' => $worlds, 'sources' => $sources, 'teams' => Team::orderBy('city')->get(),
            'library' => CommunityPackage::with('creator')->latest()->paginate(20),
        ]);
    }

    private function ownedTeam(Request $request, int $id): Team
    {
        $team = Team::withoutGlobalScope('world')->findOrFail($id);
        World::where('owner_user_id', $request->user()->id)->findOrFail($team->world_id);

        return $team;
    }

    private function ownedWorld(Request $request, int $id): World
    {
        return World::where('owner_user_id', $request->user()->id)->findOrFail($id);
    }

    public function roster(Request $request, int $team): StreamedResponse
    {
        $source = $this->ownedTeam($request, $team);
        $request->validate(['year' => ['nullable', 'integer', 'between:1900,2200']]);
        $year = $request->input('year') ?? $this->packages->withinWorld($source->world_id, fn () => $source->players()->max('team_players.team_year')) ?? date('Y');
        $csv = $this->packages->withinWorld($source->world_id, fn () => $this->rosters->roster($source, (string) $year));

        return response()->streamDownload(fn () => print ($csv), Str::slug($source->city.'-'.$source->name).'-'.$year.'-roster.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downloadTeam(Request $request, int $team): BinaryFileResponse
    {
        $source = $this->ownedTeam($request, $team);
        $request->validate(['year' => ['nullable', 'integer', 'between:1900,2200']]);
        $path = $this->packages->export($source, $request->input('year'));

        return response()->download($path, Str::slug($source->city.'-'.$source->name).'.zip')->deleteFileAfterSend();
    }

    public function downloadWorld(Request $request, int $world): BinaryFileResponse
    {
        $source = $this->ownedWorld($request, $world);

        return response()->download($this->packages->export($source), Str::slug($source->name).'.zip')->deleteFileAfterSend();
    }

    public function preview(PackageRequest $request): View
    {
        $file = $request->file('package');
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('packages/staged/'.$request->user()->id, Str::uuid().'.'.$extension, 'local');

        return $this->stage($request, $path, $extension);
    }

    private function stage(Request $request, string $path, string $extension): View
    {
        try {
            $content = $extension === 'csv' ? ['rows' => $this->rosters->readRoster(Storage::disk('local')->get($path))]
                : $this->packages->read(Storage::disk('local')->path($path));
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($previous = $request->session()->get('package_preview.path')) {
            Storage::disk('local')->delete($previous);
        }
        $preview = ['token' => (string) Str::uuid(), 'path' => $path, 'extension' => $extension,
            'owner' => $request->user()->id, 'expires' => time() + 3600];
        $request->session()->put('package_preview', $preview);

        return view('packages.preview', ['token' => $preview['token'], 'content' => $content,
            'kind' => $extension === 'csv' ? 'roster' : $content['manifest']['kind'],
            'teams' => Team::orderBy('city')->get()]);
    }

    public function copy(Request $request, int $team): View
    {
        $source = $this->ownedTeam($request, $team);
        $request->validate(['year' => ['nullable', 'integer', 'between:1900,2200']]);
        $path = $this->packages->export($source, $request->input('year'));
        $destination = 'packages/staged/'.$request->user()->id.'/'.Str::uuid().'.zip';
        Storage::disk('local')->put($destination, fopen($path, 'rb'));
        @unlink($path);

        return $this->stage($request, $destination, 'zip');
    }

    public function import(PackageRequest $request): RedirectResponse
    {
        $preview = $request->session()->get('package_preview');
        abort_unless($preview && hash_equals($preview['token'], $request->string('token')->toString())
            && $preview['owner'] === $request->user()->id && $preview['expires'] > time(), 419, 'Import preview expired. Upload the file again.');
        $data = $request->validated();
        if ($preview['extension'] === 'csv') {
            abort_unless(app(CurrentWorld::class)->id, 422, 'Open the destination world first.');
            $team = Team::findOrFail($data['team_id'] ?? 0);
            $rows = $this->rosters->readRoster(Storage::disk('local')->get($preview['path']));
            $this->rosters->importRoster($team, $rows, $data['year'], $data['mode'] === 'replace');
            $redirect = redirect()->route('teams.editor.teams.players.index', [$team, 'year' => $data['year']]);
        } else {
            $package = $this->packages->read(Storage::disk('local')->path($preview['path']));
            if ($package['manifest']['kind'] === 'world') {
                $world = $this->packages->importWorld($package, (($data['world_name'] ?? null) ?: $package['manifest']['name']), $request->user()->id, $data['year']);
                $request->session()->put('current_world_id', $world->id);
                $redirect = redirect()->route('seasons.create', ['year' => $data['year']]);
            } else {
                abort_unless(app(CurrentWorld::class)->id, 422, 'Open the destination world first.');
                $replace = $data['mode'] === 'replace' ? Team::findOrFail($data['team_id'] ?? 0) : null;
                $this->packages->import($package, $data['year'], $replace);
                $redirect = redirect()->route('teams.index');
            }
        }
        Storage::disk('local')->delete($preview['path']);
        $request->session()->forget('package_preview');

        return $redirect->with('status', 'Import complete. Your independent copy is ready. Choose season settings and generate a schedule when ready.');
    }

    public function shareForm(Request $request): View
    {
        $request->validate(['team' => ['nullable', 'integer'], 'world' => ['nullable', 'integer'], 'kind' => ['nullable', 'in:team,world,roster']]);
        $source = $request->filled('team') ? $this->ownedTeam($request, $request->integer('team')) : $this->ownedWorld($request, $request->integer('world'));

        return view('packages.share', ['source' => $source, 'kind' => $source instanceof World ? 'world' : ($request->input('kind') === 'roster' ? 'roster' : 'team')]);
    }

    public function share(PackageRequest $request): RedirectResponse
    {
        $source = $request->filled('team_id') ? $this->ownedTeam($request, $request->integer('team_id')) : $this->ownedWorld($request, $request->integer('world_id'));
        $request->validate(['kind' => ['required', 'in:team,world,roster']]);
        $kind = $source instanceof World ? 'world' : ($request->input('kind') === 'roster' ? 'roster' : 'team');
        $extension = $kind === 'roster' ? 'csv' : 'zip';
        $path = 'packages/library/'.Str::uuid().'.'.$extension;
        $temporary = null;
        try {
            if ($kind === 'roster') {
                $bytes = $this->packages->withinWorld($source->world_id, fn () => $this->rosters->roster($source, $request->input('year')));
                $count = count($this->rosters->readRoster($bytes));
                $teamCount = 0;
            } else {
                $temporary = $this->packages->export($source, $request->input('year'));
                $package = $this->packages->read($temporary);
                $bytes = fopen($temporary, 'rb');
                $count = array_sum(array_map(fn ($team) => empty($team['rosters']) ? count($team['roster']) : array_sum(array_map('count', $team['rosters'])), $package['teams']));
                $teamCount = count($package['teams']);
            }
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new \RuntimeException('Unable to save the shared package.');
            }
            CommunityPackage::create(['user_id' => $request->user()->id, 'title' => $request->input('title'),
                'description' => $request->input('description'), 'kind' => $kind, 'path' => $path, 'team_count' => $teamCount, 'roster_count' => $count]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        } finally {
            if ($temporary) {
                @unlink($temporary);
            }
        }

        return redirect()->route('packages.index')->with('status', 'Published to the community library. Imports create independent copies.');
    }

    public function libraryDownload(CommunityPackage $package): BinaryFileResponse
    {
        return response()->download(Storage::disk('local')->path($package->path), Str::slug($package->title).'.'.($package->kind === 'roster' ? 'csv' : 'zip'));
    }

    public function libraryCopy(Request $request, CommunityPackage $package): View
    {
        $extension = $package->kind === 'roster' ? 'csv' : 'zip';
        $path = 'packages/staged/'.$request->user()->id.'/'.Str::uuid().'.'.$extension;
        abort_unless(Storage::disk('local')->copy($package->path, $path), 500);

        return $this->stage($request, $path, $extension);
    }

    public function unshare(Request $request, CommunityPackage $package): RedirectResponse
    {
        abort_unless((int) $package->user_id === $request->user()->id, 404);
        $path = $package->path;
        $package->delete();
        Storage::disk('local')->delete($path);

        return redirect()->route('packages.index')->with('status', 'Removed from the library. Previously imported copies remain with their owners.');
    }

    public function guide(): View
    {
        return view('packages.guide', ['headers' => $this->rosters->headers(), 'columns' => collect(\Illuminate\Support\Facades\Schema::getColumns('teams'))->reject(fn ($column) => in_array($column['name'], ['id', 'world_id', 'league_id', 'created_at', 'updated_at'], true))]);
    }

    public function starter(Request $request): StreamedResponse|BinaryFileResponse
    {
        if ($request->query('kind') !== 'roster') {
            $path = $this->packages->newPath();
            $zip = new \PharData($path, 0, null, \Phar::ZIP);
            $zip->addFromString('package.json', json_encode(['format' => 'websports-football', 'version' => 1,
                'kind' => $request->query('kind') === 'world' ? 'world' : 'team', 'name' => 'My Football Team',
                'leagues' => [['key' => 'league-1', 'name' => 'Football League']],
                'teams' => [['directory' => 'teams/team-1', 'league' => 'league-1']]], JSON_PRETTY_PRINT));
            $zip->addFromString('teams/team-1/team.json', json_encode(['city' => 'Example City', 'name' => 'Warriors', 'conference' => 'Conference A', 'division' => 'Division A', 'team_settings' => (object) []], JSON_PRETTY_PRINT));
            $zip->addFromString('teams/team-1/roster.csv', $this->rosters->starterRoster());
            unset($zip);

            return response()->download($path, 'football-starter.zip')->deleteFileAfterSend();
        }

        return response()->streamDownload(fn () => print ($this->rosters->starterRoster()), 'roster-starter.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
