<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Bridge') }}</title>
    @bridgeHead
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/js/app.ts'])
    @endif
</head>
<body>
    @bridge
    <noscript>This application requires JavaScript.</noscript>
</body>
</html>
