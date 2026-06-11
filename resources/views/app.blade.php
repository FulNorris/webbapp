<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#b91822">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Stuckbema Leverans') }}">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">
    <title inertia>{{ config('app.name', 'Stuckbema Leverans') }}</title>
    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body class="app-body">
    @inertia
</body>
</html>
