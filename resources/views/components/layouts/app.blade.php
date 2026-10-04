@props(['immersive' => false])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Football</title>




    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

</head>
<body class="bg-gray-900">
@unless($immersive)
<nav class="flex flex-wrap items-center gap-5 p-4 text-gray-200 bg-gray-950 border-b border-gray-700">
    <a class="font-semibold text-white" href="{{ route('worlds.index') }}">Football</a>
    <a href="{{ route('home') }}">Games</a><a href="{{ route('teams.editor.index') }}">Teams</a>
    <a href="{{ route('exhibitions.index') }}">Play exhibition</a>
    <a href="{{ route('practice') }}">Practice field</a>
    <a class="ml-auto" href="{{ route('worlds.index') }}">Saved games</a>
    @if (app(\App\Support\CurrentWorld::class)->id)
        <form method="POST" action="{{ route('worlds.close') }}">@csrf<button class="text-gray-400">Close saved game</button></form>
    @endif
</nav>
@endunless
{{ $slot }}

@livewireScripts
</body>
</html>
