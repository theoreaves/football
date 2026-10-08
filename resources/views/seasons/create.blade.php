<x-layouts.app>
<main class="season-page">
    <header class="season-header"><div><p class="season-eyebrow">Season setup</p><h1>Build your season</h1><p>Choose a structure, assign teams, then preview the schedule.</p></div><a href="{{ route('seasons.index') }}">Back to seasons</a></header>
    @if($errors->any())<div role="alert" class="season-notice text-red-300">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($teams->count() < 4 || $leagues->isEmpty())<p class="season-notice">You need a league and at least four teams in this world. <a class="underline" href="{{ route('teams.editor.index') }}">Manage teams</a>.</p>@else
    @php
        $defaultCount = collect($sizes)->filter(fn($size) => $size <= $teams->count())->last();
        $count = (int) old('team_count', $defaultCount);
        if (!in_array($count, $sizes)) $count = $defaultCount;
        $layout = old('layout', 'divisions');
        if (!in_array($layout, ['flat', 'conferences', 'divisions'])) $layout = 'divisions';
        $definitions = \App\Services\Seasons\SeasonOptions::groups($count, $layout);
        $groupList = [];
        foreach ($definitions as $key => $definition) $groupList = array_merge($groupList, array_fill(0, $definition['size'], $key));
        $allDefinitions = [];
        foreach ($sizes as $size) foreach (['flat', 'conferences', 'divisions'] as $style) $allDefinitions[$size][$style] = \App\Services\Seasons\SeasonOptions::groups($size, $style);
        $selected = old('teams', $teams->take($count)->pluck('id')->all());
    @endphp
    <form data-season-setup data-definitions="{{ json_encode($allDefinitions) }}" method="POST" action="{{ route('seasons.preview') }}" class="space-y-6">@csrf
        <section class="season-panel season-fields">
            <label>Season name<input name="name" required maxlength="100" value="{{ old('name', 'My Season') }}"></label>
            <label>League<select name="league_id">@foreach($leagues as $league)<option value="{{ $league->id }}" @selected(old('league_id') == $league->id)>{{ $league->name }}</option>@endforeach</select></label>
            <label>Year<input name="year" type="number" required min="1900" max="2200" value="{{ old('year', $leagues->first()->seasons()->max('year') ?? 2026) }}"></label>
            <label>Team count<select name="team_count">@foreach($sizes as $size)<option value="{{ $size }}" @selected($count === $size) @disabled($teams->count() < $size)>{{ $size }} teams</option>@endforeach</select></label>
            <label>League layout<select name="layout"><option value="flat" @selected($layout === 'flat')>One league · no divisions</option><option value="conferences" @selected($layout === 'conferences')>Two conferences · no divisions</option><option value="divisions" @selected($layout === 'divisions')>Two conferences · division preset</option></select></label>
            <label>Games per team<select name="games">@foreach(\App\Services\Seasons\SeasonOptions::lengths($count) as $games)<option value="{{ $games }}" @selected(old('games', $count === 4 ? 3 : 17) == $games)>{{ $games }}</option>@endforeach</select></label>
            <label>Bye weeks<select name="bye"><option value="0" @selected(old('bye', '1') == '0')>No byes</option><option value="1" @selected(old('bye', '1') == '1')>One bye per team</option></select></label>
            <label>Playoffs<select name="playoffs">@foreach($playoffs as $value => $label)<option value="{{ $value }}" @selected((string) old('playoffs', '4') === (string) $value)>{{ $label }}</option>@endforeach</select></label>
        </section>
        <section class="season-panel"><h2>Conference and division names</h2><p>Rename the groups. Team assignments must match the displayed group sizes.</p><div data-group-names class="season-fields">
            @foreach($definitions as $key => $definition)
                @php
                    $conferenceKey = $layout === 'flat' ? 'league' : substr($key, 0, 1);
                @endphp
                @if($loop->first || ($layout !== 'flat' && substr($key, -1) === '1'))<label>{{ $definition['conference'] }}<input name="conference_names[{{ $conferenceKey }}]" value="{{ old('conference_names.'.$conferenceKey, $definition['conference']) }}" required maxlength="40"></label>@endif
                <label>{{ $definition['conference'] }} · {{ $definition['division'] }} ({{ $definition['size'] }} teams)<input name="division_names[{{ $key }}]" value="{{ old('division_names.'.$key, $definition['division']) }}" required maxlength="40"></label>
            @endforeach
        </div></section>
        <section class="season-panel"><h2>Teams and coaching control</h2><p data-team-count>Select exactly {{ $count }} teams. Unchecked Human means CPU. You can change control later.</p><div class="season-table-scroll"><table class="season-table"><thead><tr><th>Include</th><th>Team</th><th>Conference / Division</th><th>Human</th></tr></thead><tbody>
        @foreach($teams as $team)
            <tr data-season-team="{{ $team->id }}"><td><input aria-label="Include {{ $team->name }}" type="checkbox" name="teams[]" value="{{ $team->id }}" @checked(in_array($team->id, $selected))></td><td>{{ $team->city }} {{ $team->name }}</td><td><select aria-label="Group for {{ $team->name }}" name="groups[{{ $team->id }}]">@foreach($definitions as $key => $definition)<option value="{{ $key }}" @selected(old('groups.'.$team->id, $groupList[$loop->parent->index] ?? array_key_first($definitions)) === $key)>{{ $definition['conference'] }} · {{ $definition['division'] }}</option>@endforeach</select></td><td><input aria-label="Human controls {{ $team->name }}" type="checkbox" name="human[]" value="{{ $team->id }}" @checked(in_array($team->id, old('human', [])))></td></tr>
        @endforeach
        </tbody></table></div></section>
        <button class="landing-button">Preview schedule</button>
    </form>
    @endif
</main>
</x-layouts.app>
