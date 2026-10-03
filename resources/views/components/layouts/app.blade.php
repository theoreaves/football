<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Football Companion</title>
{{--    <script src="https://unpkg.com/winbox@0.2.82/dist/winbox.bundle.js"></script>--}}
{{--    <script src="https://unpkg.com/winbox/dist/winbox.bundle.js"></script>--}}
    <script src="https://unpkg.com/winbox/dist/winbox.bundle.min.js"></script>



    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

</head>
<body class="bg-gray-900">
@auth
<nav class="flex gap-4 p-3 text-white bg-gray-800">
    <a href="{{ route('home') }}">Games</a><a href="{{ route('teams.editor.index') }}">Teams</a>
    <a href="{{ route('worlds.index') }}">{{ auth()->user()->currentWorld?->name ?? 'Choose world' }}</a>
    <form method="POST" action="{{ route('logout') }}">@csrf<button>Log out</button></form>
</nav>
@endauth
{{ $slot }}

@livewireScripts
</body>
</html>
