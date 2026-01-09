    <x-layouts.app>
<div class="max-w-6xl mx-auto p-6 bg-white">
        @if(session('status'))
            <div class="mb-4 p-3 border rounded bg-green-50">
                {{ session('status') }}
            </div>
        @endif

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-semibold">Teams</h1>
            <a href="{{ route('teams.index') }}"
                class="text-blue-400 hover:text-blue-300 font-semibold">
                Exit Editor
            </a>
            <a href="{{ route('teams.editor.create') }}" class="px-4 py-2 rounded bg-blue-600 text-white">
                Add Team
            </a>
        </div>

        <div class="overflow-x-auto border rounded">
            <table class="w-full text-left">
                <thead class="bg-gray-100">
                <tr>
                    <th class="p-3">Team</th>
                    <th class="p-3">Colors</th>
                    <th class="p-3">Updated</th>
                    <th class="p-3"></th>
                </tr>
                </thead>
                <tbody>
                @foreach($teams as $team)
                    <tr class="border-t">
                        <td class="p-3">
                            <div class="font-medium">{{ $team->city }} {{ $team->name }}</div>
                        </td>
                        <td class="p-3">
                            <div class="flex gap-2 items-center">
                                @foreach(['team_color1','team_color2'] as $c)
                                    @if($team->{$c})
                                        <span class="inline-block w-5 h-5 rounded border" style="background: {{ $team->{$c} }}"></span>
                                        <span class="text-sm text-gray-600">{{ $team->{$c} }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td class="p-3 text-sm text-gray-600">
                            {{ optional($team->updated_at)->format('m/d/Y H:i') }}
                        </td>
                        <td class="p-3 text-right">
                            <a class="text-blue-600 underline" href="{{ route('teams.editor.edit', $team) }}">Edit</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $teams->links() }}
        </div>
    </div>
    </x-layouts.app>
