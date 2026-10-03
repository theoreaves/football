<?php

namespace App\Http\Controllers;

use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorldController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        return view('worlds.index', ['worlds' => $request->user()->worlds()->get()]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'league_name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:1900,2200'],
        ]);
        DB::transaction(function () use ($request, $data) {
            $world = World::create(['name' => $data['name'], 'owner_user_id' => $request->user()->id]);
            $world->users()->attach($request->user()->id, ['role' => 'owner']);
            app(CurrentWorld::class)->id = $world->id;
            $league = $world->leagues()->create(['name' => $data['league_name']]);
            $league->seasons()->create(['year' => $data['year']]);
            $request->user()->forceFill(['current_world_id' => $world->id])->save();
        });

        return redirect()->route('home');
    }

    public function select(Request $request, int $world): \Illuminate\Http\RedirectResponse
    {
        $memberWorld = $request->user()->worlds()->findOrFail($world);
        $request->user()->forceFill(['current_world_id' => $memberWorld->id])->save();

        return redirect()->route('home');
    }
}
