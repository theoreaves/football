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
{{ $slot }}

@livewireScripts
</body>
</html>
