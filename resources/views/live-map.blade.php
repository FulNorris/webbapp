<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Livekarta - {{ $settings['appTitle'] ?? config('app.name', 'Stuckbema Leverans') }}</title>
    @livewireStyles
    @vite(['resources/js/livewire.js'])
</head>
<body class="app-body">
    <div class="app-shell app-shell-live-map">
        <header class="topbar app-header">
            <div>
                <h1>Livekarta</h1>
                <div class="topbar-meta">{{ $user['name'] ?: $user['email'] }} · {{ $user['role'] }}</div>
            </div>
            <div class="topbar-actions">
                <a class="plain-button" href="/">Till dashboard</a>
            </div>
        </header>

        <main class="main app-main app-content live-map-main">
            <livewire:live-map />
        </main>
    </div>

    @livewireScripts
</body>
</html>
