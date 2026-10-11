<h2 class="text-xl font-semibold">Stadium audio</h2>
<p class="text-sm text-gray-600">These sounds play when this team hosts the game. Upload MP3, WAV, or OGG clips up to 15 MB each. Save to apply your selections. Unchanged events use the bundled sounds.</p>
<p class="text-sm text-gray-600">New games capture your selections; games already underway retain their original sounds. Game sound sliders also control custom clips.</p>
<div class="grid gap-4 md:grid-cols-2">
@foreach(config('team-audio') as $event => $sound)
    <div class="rounded border p-4 space-y-2">
        <label class="block font-semibold" for="audio-{{ $event }}">{{ $sound['label'] }}</label>
        <p class="text-sm">{{ isset($team->team_settings['audio'][$event]) ? 'Custom clip' : 'Bundled default' }}</p>
        <audio controls preload="none" class="w-full" src="{{ isset($team->team_settings['audio'][$event]) ? route('teams.audio', ['team' => $team, 'clip' => basename($team->team_settings['audio'][$event])]) : asset('audio/stadium/v1/'.$sound['file']) }}"></audio>
        <input id="audio-{{ $event }}" type="file" name="audio_uploads[{{ $event }}]" accept=".mp3,.wav,.ogg,audio/mpeg,audio/wav,audio/ogg" class="block w-full text-sm" data-audio-preview>
        @if(isset($team->team_settings['audio'][$event]))
        <label class="block text-sm"><input type="checkbox" name="audio_clear[{{ $event }}]" value="1"> Reset to bundled default when saved</label>
        @endif
        @error('audio_uploads.'.$event)<p class="text-red-700 text-sm">{{ $message }}</p>@enderror
    </div>
@endforeach
</div>
