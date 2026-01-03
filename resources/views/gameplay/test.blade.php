<x-layouts.app>
<div class="max-w-xl mx-auto mt-10 p-6 bg-white rounded shadow">
    <h1 class="text-2xl font-bold mb-4">Game Play Engine Test</h1>
    <form method="POST" action="{{ url('/gameplay/test') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block font-semibold">Offense Code</label>
            <input type="text" name="offense_code" value="{{ old('offense_code', $input['offense_code'] ?? '') }}" class="w-full border rounded px-2 py-1" required>
        </div>
        <div>
            <label class="block font-semibold">Defense Code</label>
            <input type="text" name="defense_code" value="{{ old('defense_code', $input['defense_code'] ?? '') }}" class="w-full border rounded px-2 py-1" required>
        </div>
        <div>
            <label class="block font-semibold">Result Roll</label>
            <input type="number" name="result_roll" value="{{ old('result_roll', $input['result_roll'] ?? '') }}" class="w-full border rounded px-2 py-1" required>
        </div>
        <div>
            <label class="block font-semibold">Skill Roll</label>
            <input type="number" name="skill_roll" value="{{ old('skill_roll', $input['skill_roll'] ?? '') }}" class="w-full border rounded px-2 py-1" required>
        </div>
        <div>
            <label class="block font-semibold">Offense Team</label>
            <select name="offense_team_id" class="w-full border rounded px-2 py-1" required>
                <option value="">Select Team</option>
                @foreach($teams as $team)
                    <option value="{{ $team->id }}" @if(old('offense_team_id', $input['offense_team_id'] ?? '') == $team->id) selected @endif>{{ $team->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block font-semibold">Defense Team</label>
            <select name="defense_team_id" class="w-full border rounded px-2 py-1" required>
                <option value="">Select Team</option>
                @foreach($teams as $team)
                    <option value="{{ $team->id }}" @if(old('defense_team_id', $input['defense_team_id'] ?? '') == $team->id) selected @endif>{{ $team->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block font-semibold">Player Die</label>
            <input type="number" name="player_die" value="{{ old('player_die', $input['player_die'] ?? '') }}" class="w-full border rounded px-2 py-1">
        </div>
        <div>
            <label class="block font-semibold">Tackler Die</label>
            <input type="number" name="tackler_die" value="{{ old('tackler_die', $input['tackler_die'] ?? '') }}" class="w-full border rounded px-2 py-1">
        </div>
        <div>
            <label class="block font-semibold">Disrupter Die</label>
            <input type="number" name="disrupter_die" value="{{ old('disrupter_die', $input['disrupter_die'] ?? '') }}" class="w-full border rounded px-2 py-1">
        </div>
        <div class="flex items-center">
            <input type="checkbox" name="redzone" value="1" @if(old('redzone', $input['redzone'] ?? false)) checked @endif class="mr-2">
            <label class="font-semibold">Redzone?</label>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Submit</button>
    </form>

    @if($result !== null)
        <div class="mt-6 p-4 bg-gray-100 rounded">
            <h2 class="font-bold mb-2">Result</h2>
            @if(is_array($result))
                <ul class="list-disc pl-6">
                    @foreach($result as $key => $value)
                        <li><strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong> {{ $value }}</li>
                    @endforeach
                </ul>
            @else
                <div>{{ $result }}</div>
            @endif
        </div>
    @endif
</div>
</x-layouts.app>

