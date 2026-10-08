@props(['immersive' => false])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WebSports Football</title>
    <meta name="application-name" content="WebSports Football">
    <link rel="icon" type="image/png" href="{{ asset('branding/websports-football.png') }}">




    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

</head>
<body class="bg-gray-900">
@unless($immersive)
<nav class="flex flex-wrap items-center gap-5 p-4 text-gray-200 bg-gray-950 border-b border-gray-700">
    <a class="shrink-0" href="{{ route('worlds.index') }}"><x-brand-logo class="w-44" /></a>
    @if(app(\App\Support\CurrentWorld::class)->id)
    <a href="{{ route('home') }}">World overview</a><a href="{{ route('teams.editor.index') }}">Teams</a>
    <a href="{{ route('seasons.index') }}">Seasons</a>
    @endif
    <a href="{{ route('practice') }}">Practice field</a>
    <a class="ml-auto" href="{{ route('worlds.index') }}">Worlds</a>
    @if (app(\App\Support\CurrentWorld::class)->id)
        <form method="POST" action="{{ route('worlds.close') }}">@csrf<button class="text-gray-400">Close world</button></form>
    @endif
    @auth
        <span class="text-sm text-gray-400">{{ auth()->user()->name }}</span>
        @if(config('services.google.client_id') && config('services.google.client_secret') && !auth()->user()->google_id)<a href="{{ route('google.redirect') }}" class="text-sm text-blue-300">Link Google</a>@endif
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-gray-300">Sign out</button></form>
    @endauth
</nav>
@endunless
{{ $slot }}

@livewireScripts
</body>
</html>
