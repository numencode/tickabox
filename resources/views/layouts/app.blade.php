<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Tickabox</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
<div class="tk-system-bar tk-system-bar--top" aria-hidden="true"></div>
<div class="tk-system-bar tk-system-bar--bottom" aria-hidden="true"></div>
<div id="app-root">
    {{ $slot }}
</div>

@livewireScripts
</body>
</html>