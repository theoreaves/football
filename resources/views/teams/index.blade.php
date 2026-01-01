<x-layouts.app>
    <div class="min-h-screen bg-gray-950 text-gray-100 p-6">
        <div class="max-w-4xl mx-auto">

            <div class="mb-6">
                <div class="text-sm text-gray-400">Teams</div>
                <div class="text-3xl font-bold tracking-tight">League Teams</div>
            </div>

            <div class="rounded-lg border border-white/10 overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-gray-300">
                    <tr>
                        <th class="text-left px-4 py-3">Team</th>
                        <th class="text-center px-4 py-3">Play Calling</th>
                        <th class="text-right px-4 py-3">Sheet</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-white/10">
                    @forelse ($teams as $team)
                        <tr class="bg-gray-950 hover:bg-white/5">
                            <td class="px-4 py-3 font-semibold">
                                {{ $team->city }} {{ $team->name }}
                            </td>

                            <td class="px-4 py-3 text-center text-gray-400">
                                    <span>
                                        {{ $team->playcalling_behind > 0 ? '+' : '' }}{{ $team->playcalling_behind }}
                                    </span>
                                /
                                <span>
                                        {{ $team->playcalling_tied > 0 ? '+' : '' }}{{ $team->playcalling_tied }}
                                    </span>
                                /
                                <span>
                                        {{ $team->playcalling_ahead > 0 ? '+' : '' }}{{ $team->playcalling_ahead }}
                                    </span>
                            </td>

                            <td class="px-4 py-3 text-right">
                                <a
                                    href="{{ route('teams.sheet', $team) }}"
                                    class="text-blue-400 hover:text-blue-300 font-medium"
                                >
                                    View Sheet →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-gray-400">
                                No teams found.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-layouts.app>
