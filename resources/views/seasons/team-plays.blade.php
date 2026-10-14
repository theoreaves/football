@if(session('status'))<p class="season-notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p class="season-notice text-red-300" role="alert">{{ $errors->first() }}</p>@endif
@php
    $savedPlays = $season->settings['team_plays'][$team->id] ?? [];
    $draft = ['id' => old('id', $editingPlay['id'] ?? ''), 'name' => old('name', $editingPlay['name'] ?? ''), 'kind' => old('kind', $editingPlay['kind'] ?? 'offense'), 'formation' => old('formation', $editingPlay['formation'] ?? 'shotgun'), 'call' => old('call', $editingPlay['call'] ?? 'inside_zone'), 'personnel' => old('personnel', $editingPlay['personnel'] ?? []), 'expect' => old('expect', $editingPlay['expect'] ?? 'balanced'), 'blitz' => old('blitz', $editingPlay['blitz'] ?? false)];
    $editorPlayers = $players->map(fn ($p) => ['id' => $p->id, 'name' => $p->firstname.' '.$p->lastname, 'number' => $p->pivot->jersey_number, 'position' => $p->pivot->position ?: $p->position])->values();
@endphp
<section class="season-panel">
    <h2>Team Plays</h2>
    <p>Named variations of existing plays, with optional personnel from this team’s {{ $season->year }} roster. Newly started season games capture this playbook. Existing games keep theirs.</p>
    <p class="mt-3">{{ count($savedPlays) }} / 40 plays · CPU and Ask Coach use standard plays.</p>
    <div class="team-play-list">
        @forelse($savedPlays as $play)
            <article class="team-play-item">
                <div>
                    <h3>{{ $play['name'] }}</h3>
                    <p>{{ ucfirst($play['kind']) }} · {{ $playCatalogue[$play['kind']][$play['formation']]['label'] ?? $play['formation'] }} · {{ ucwords(str_replace('_', ' ', $play['call'])) }}
                        @if($play['kind'] === 'defense')
                            · {{ ucfirst($play['expect']) }}
                            @if($play['blitz'])
                                + Blitz
                            @endif
                        @endif
                    </p>
                    <small>{{ count($play['personnel']) }} personnel assignments</small>
                </div>
                <div class="flex gap-3 items-center"><a href="{{ route('seasons.team', [$season, $team, 'tab' => 'plays', 'edit' => $play['id']]) }}" class="text-blue-300 underline">Edit</a><form method="POST" action="{{ route('seasons.plays.delete', [$season, $team, $play['id']]) }}">@csrf @method('DELETE')<button class="border rounded px-3 py-2">Remove</button></form></div>
            </article>
        @empty<p class="mt-4">No team plays yet. Create your first variation below.</p>@endforelse
    </div>
</section>
<section class="season-panel">
    <h2>{{ $draft['id'] ? 'Edit team play' : 'Create team play' }}</h2>
    <form data-team-play-editor data-catalogue="{{ json_encode($playCatalogue) }}" data-players="{{ json_encode($editorPlayers) }}" data-draft="{{ json_encode($draft) }}" method="POST" action="{{ route('seasons.plays.save', [$season, $team]) }}">
        @csrf<input type="hidden" name="id" value="{{ $draft['id'] }}">
        <div class="season-fields mt-4">
            <label>Name<input name="name" value="{{ $draft['name'] }}" maxlength="60" required placeholder="Heavy Goal Line"></label>
            <label>Unit<select name="kind"><option value="offense" @selected($draft['kind'] === 'offense')>Offense</option><option value="defense" @selected($draft['kind'] === 'defense')>Defense</option></select></label>
            <label>Formation<select name="formation">@foreach($playCatalogue[$draft['kind']] ?? $playCatalogue['offense'] as $key => $entry)<option value="{{ $key }}" @selected($draft['formation'] === $key)>{{ $entry['label'] }}</option>@endforeach</select></label>
            <label>Base play / coverage<select name="call">@foreach($playCatalogue[$draft['kind']][$draft['formation']]['calls'] ?? [] as $call)<option value="{{ $call }}" @selected($draft['call'] === $call)>{{ ucwords(str_replace('_', ' ', $call)) }}</option>@endforeach</select></label>
            <label data-defense-profile @if($draft['kind'] !== 'defense') hidden @endif>Expect<select name="expect"><option value="balanced" @selected($draft['expect'] === 'balanced')>Balanced</option><option value="run" @selected($draft['expect'] === 'run')>Run</option><option value="pass" @selected($draft['expect'] === 'pass')>Pass</option></select></label>
            <label data-defense-profile @if($draft['kind'] !== 'defense') hidden @endif><input type="checkbox" name="blitz" value="1" @checked($draft['blitz'])> Send blitz</label>
        </div>
        <p class="mt-4">Any roster position can fill a role. Automatic uses the game’s current lineup and specialists. A player can occupy only one assigned role.</p>
        <label class="block mt-4">Find a player<input data-team-play-search type="search" placeholder="Name, number or position" class="block w-full"></label>
        <div data-team-play-positions class="team-play-positions mt-4">
            @foreach($playCatalogue[$draft['kind']][$draft['formation']]['roles'] ?? [] as $role)
                <label>{{ $role }}<select name="personnel[{{ $role }}]"><option value="">Automatic</option>@foreach($editorPlayers as $player)<option value="{{ $player['id'] }}" @selected((string) ($draft['personnel'][$role] ?? '') === (string) $player['id'])>#{{ $player['number'] }} {{ $player['name'] }} · {{ $player['position'] }}</option>@endforeach</select></label>
            @endforeach
        </div>
        <p class="mt-4 text-sm">Injured, tired or missing named players fall back to Automatic. One-play replacements on the game’s review screen take priority.</p>
        <button class="landing-button mt-4">Save team play</button>
        @if($draft['id'])<a class="ml-4 text-blue-300" href="{{ route('seasons.team', [$season, $team, 'tab' => 'plays']) }}">Cancel / create another</a>@endif
        <noscript><p class="mt-3">JavaScript is needed to change unit or formation. The displayed formation can still be saved.</p></noscript>
    </form>
</section>
