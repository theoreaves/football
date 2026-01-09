<x-layouts.app>
    <div class="max-w-5xl mx-auto p-6 bg-white">
        @if(session('status'))
            <div class="mb-4 p-3 border rounded bg-green-50">
                {{ session('status') }}
            </div>
        @endif

        <div class="flex items-center justify-between mb-4">
            <div>
                <div class="text-xs text-gray-500">Team</div>
                <h1 class="text-2xl font-semibold">{{ $team->city }} {{ $team->name }} — Players ({{ $year }})</h1>
            </div>

            <div class="flex gap-2">
                <a href="{{ route('teams.editor.edit', $team) }}" class="px-4 py-2 rounded border">Back to Team</a>

                <a href="{{ route('teams.editor.teams.players.create', [$team, 'year' => $year]) }}"
                   class="px-4 py-2 rounded bg-blue-600 text-white">
                    Add Player
                </a>
            </div>
        </div>

        <form method="GET" class="mb-4 flex gap-2">
            <input type="hidden" name="year" value="{{ $year }}">
            <input name="q" value="{{ $q ?? '' }}" placeholder="Search name/position..."
                   class="w-full border rounded p-2" />
            <button class="px-4 py-2 rounded border">Search</button>
        </form>

        <div class="border rounded overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                <tr class="text-left">
                    <th class="p-3">Depth</th>
                    <th class="p-3">Pos</th>
                    <th class="p-3">#</th>
                    <th class="p-3">Name</th>
                    <th class="p-3 w-32"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($players as $p)
                    <tr class="border-t">
                        <td class="p-3">{{ $p->pivot->depth_chart_position }}</td>
                        <td class="p-3">{{ $p->position }}</td>
                        <td class="p-3">{{ $p->pivot->jersey_number }}</td>
                        <td class="p-3">{{ $p->firstname }} {{ $p->lastname }}</td>
                        <td class="p-3 text-right">
                            <a class="underline"
                               href="{{ route('teams.editor.teams.players.edit', [$team, $p, 'year' => $year]) }}">
                                Edit
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr class="border-t">
                        <td class="p-3 text-gray-500" colspan="5">No players on roster for this year.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $players->links() }}
        </div>
    </div>
</x-layouts.app>
