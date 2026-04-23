<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Filter Time Tracker') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900 antialiased">

<nav class="bg-white border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <div class="flex items-center gap-6">
                <span class="font-semibold text-gray-900 text-sm tracking-tight">Filter Time</span>

                <a href="{{ route('timesheet') }}"
                   class="text-sm font-medium {{ request()->routeIs('timesheet') ? 'text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
                    Time
                </a>

                @if(auth()->user()->isManager())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                            class="text-sm font-medium flex items-center gap-1 {{ request()->routeIs('reports.*') ? 'text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
                        Reports <span class="text-xs">▾</span>
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute top-full left-0 mt-1 w-36 bg-white border border-gray-200 rounded shadow-md z-50 py-1">
                        <a href="{{ route('reports.time') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Time</a>
                        <a href="{{ route('reports.clients') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Clients</a>
                        <a href="{{ route('reports.projects') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Projects</a>
                        <a href="{{ route('reports.tasks') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Tasks</a>
                        <a href="{{ route('reports.team') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Team</a>
                        <a href="{{ route('reports.jdw') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">JDW Export</a>
                    </div>
                </div>
                @endif

                @if(auth()->user()->isAdmin())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                            class="text-sm font-medium text-gray-600 hover:text-gray-900 flex items-center gap-1">
                        Admin <span class="text-xs">▾</span>
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute top-full left-0 mt-1 w-40 bg-white border border-gray-200 rounded shadow-md z-50 py-1">
                        <a href="{{ route('admin.users') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Users</a>
                        <a href="{{ route('admin.clients') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Clients</a>
                        <a href="{{ route('admin.projects') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Projects</a>
                        <a href="{{ route('admin.tasks') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Tasks</a>
                        <hr class="my-1 border-gray-100">
                        <a href="{{ route('admin.rates') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Rates</a>
                    </div>
                </div>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit"
                            class="text-sm text-gray-500 hover:text-gray-700">
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    {{ $slot }}
</main>

@livewireScripts
</body>
</html>
