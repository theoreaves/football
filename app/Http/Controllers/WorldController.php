<?php

namespace App\Http\Controllers;

use App\Console\Commands\SeedDemoWorld;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorldController extends Controller
{
    public function index(Request $request): View
    {
        return view('worlds.index', ['worlds' => World::where('owner_user_id', $request->user()->id)->orderByDesc('updated_at')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'league_name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:1900,2200'],
            'demo' => ['sometimes', 'boolean'],
            'preset' => ['sometimes', \Illuminate\Validation\Rule::in(['empty', 'demo', 'pro'])],
        ]);
        $world = DB::transaction(function () use ($request, $data) {
            $world = World::create(['name' => $data['name'], 'owner_user_id' => $request->user()->id]);
            app(CurrentWorld::class)->id = $world->id;
            $league = $world->leagues()->create(['name' => $data['league_name']]);
            $league->seasons()->create(['year' => $data['year']]);
            if (($data['preset'] ?? '') === 'pro') {
                app(\App\Services\Simulation\LeagueTemplateSeeder::class)->pro($world, (int) $data['year'], (int) $data['year']);
            } elseif (($data['preset'] ?? ($request->boolean('demo') ? 'demo' : 'empty')) === 'demo') {
                $status = Artisan::call('world:seed-demo', ['world' => $world->id, '--year' => $data['year']]);
                if ($status !== SeedDemoWorld::SUCCESS) {
                    throw new \RuntimeException('Unable to create the demo league.');
                }
            }

            return $world;
        });
        $request->session()->put('current_world_id', $world->id);

        return redirect()->route('home');
    }

    public function select(Request $request, int $world): RedirectResponse
    {
        $savedGame = World::where('owner_user_id', $request->user()->id)->findOrFail($world);
        $request->session()->put('current_world_id', $savedGame->id);

        return redirect()->route('home');
    }

    public function close(Request $request): RedirectResponse
    {
        $request->session()->forget('current_world_id');

        return redirect()->route('worlds.index');
    }
}
