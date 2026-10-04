<?php

namespace App\Http\Controllers;

use App\Console\Commands\SeedDemoWorld;
use App\Models\LocalSetting;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorldController extends Controller
{
    public function index(): View
    {
        return view('worlds.index', ['worlds' => World::orderByDesc('updated_at')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'league_name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:1900,2200'],
            'demo' => ['sometimes', 'boolean'],
        ]);
        $world = DB::transaction(function () use ($request, $data) {
            $world = World::create(['name' => $data['name']]);
            app(CurrentWorld::class)->id = $world->id;
            $league = $world->leagues()->create(['name' => $data['league_name']]);
            $league->seasons()->create(['year' => $data['year']]);
            if ($request->boolean('demo')) {
                $status = Artisan::call('world:seed-demo', ['world' => $world->id, '--year' => $data['year']]);
                if ($status !== SeedDemoWorld::SUCCESS) {
                    throw new \RuntimeException('Unable to create the demo league.');
                }
            }
            LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $world->id]);

            return $world;
        });
        $request->session()->put('current_world_id', $world->id);

        return redirect()->route('home');
    }

    public function select(Request $request, int $world): RedirectResponse
    {
        $savedGame = World::findOrFail($world);
        LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => $savedGame->id]);
        $request->session()->put('current_world_id', $savedGame->id);

        return redirect()->route('home');
    }

    public function close(Request $request): RedirectResponse
    {
        LocalSetting::updateOrCreate(['id' => 1], ['current_world_id' => null]);
        $request->session()->forget('current_world_id');

        return redirect()->route('worlds.index');
    }
}
