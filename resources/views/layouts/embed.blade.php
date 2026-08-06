<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Filter Time Tracker') }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @if ($livewireRuntimePreload = app(\App\Support\LivewireRuntimeAsset::class)->preloadUrl())
        <link rel="preload" href="{{ $livewireRuntimePreload }}" as="script">
    @endif
</head>
{{-- Chrome-free layout for pages embedded in an iframe (the Asana
     browser-extension overlay). No nav: the frame is ~520px wide and the
     surrounding page provides all context. --}}
<body class="bg-white text-gray-900 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
