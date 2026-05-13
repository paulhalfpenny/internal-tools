<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Filter Time Tracker') }}</title>
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#002f5f">
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="apple-mobile-web-app-title" content="Filter">
    <meta name="application-name" content="Filter">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-config" content="/browserconfig.xml">
    <meta name="theme-color" content="#ffffff">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900 antialiased">

<nav style="background-color: #002f5f;" class="border-b border-blue-900">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <div class="flex items-center gap-6">
                <a href="{{ route('timesheet') }}"><img src="/assets/filter-logo-white-rgb.png" alt="Filter" class="w-auto" style="height: 1.75rem;"></a>

                <a href="{{ route('timesheet') }}"
                   class="text-sm font-medium text-white {{ request()->routeIs('timesheet') ? 'opacity-100 font-semibold' : 'opacity-70 hover:opacity-100' }}">
                    Track Time
                </a>

                @if(auth()->user()->isManager())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                            class="text-sm font-medium text-white flex items-center gap-1 {{ request()->routeIs('reports.*') ? 'opacity-100 font-semibold' : 'opacity-70 hover:opacity-100' }}">
                        Reports <span class="text-xs">▾</span>
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute top-full left-0 mt-1 w-36 bg-white border border-gray-200 rounded shadow-md z-50 py-1">
                        <a href="{{ route('reports.time') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Time</a>
                        <a href="{{ route('reports.clients') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Clients</a>
                        <a href="{{ route('reports.projects') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Projects</a>
                        <a href="{{ route('reports.tasks') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Tasks</a>
                        <a href="{{ route('reports.team') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Team</a>
                    </div>
                </div>
                @endif

            </div>

            <div class="flex items-center gap-4">
                @if(auth()->user()->isAdmin())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                            class="text-sm font-medium text-white flex items-center gap-1 {{ request()->routeIs('admin.*') ? 'opacity-100 font-semibold' : 'opacity-70 hover:opacity-100' }}">
                        Admin <span class="text-xs">▾</span>
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute top-full right-0 mt-1 w-40 bg-white border border-gray-200 rounded shadow-md z-50 py-1">
                        <a href="{{ route('admin.users') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Users</a>
                        <a href="{{ route('admin.clients') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Clients</a>
                        <a href="{{ route('admin.projects') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Projects</a>
                        <a href="{{ route('admin.tasks') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Tasks</a>
                        <hr class="my-1 border-gray-100">
                        <a href="{{ route('admin.rates.library') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Rates</a>
                        <a href="{{ route('admin.timesheets') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Timesheets</a>
                        <a href="{{ route('admin.time-entries.bulk-move') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Bulk Assign</a>
                        <hr class="my-1 border-gray-100">
                        <a href="{{ route('admin.integrations.asana') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Asana integration</a>
                        <a href="{{ route('admin.notifications') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Notifications</a>
                    </div>
                </div>
                @endif

                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button @click="open = !open"
                        class="flex items-center gap-2 rounded-full focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-blue-900">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-600 text-white text-xs font-semibold select-none">
                        {{ collect(explode(' ', auth()->user()->name))->map(fn($w) => strtoupper($w[0]))->take(2)->implode('') }}
                    </span>
                </button>
                <div x-show="open" x-cloak
                     class="absolute right-0 top-full mt-2 w-48 bg-white border border-gray-200 rounded shadow-md z-50 py-1">
                    <div class="px-4 py-2 border-b border-gray-100">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-gray-500 truncate">{{ auth()->user()->email }}</p>
                    </div>
                    <a href="{{ route('profile.asana') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Asana connection</a>
                    <a href="{{ route('profile.api-tokens') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">API tokens</a>
                    <form method="POST" action="{{ route('auth.logout') }}">
                        @csrf
                        <button type="submit"
                                class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            Sign out
                        </button>
                    </form>
                </div>
            </div><!-- end right side -->
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    {{ $slot }}
</main>

@livewireScripts

<script>
  window.FeedbackFlowConfig = {
    endpoint: 'https://bugfeed.co.uk/api/feedback',
    projectId: 'Xgd8K5hykzc3Q-MI',
    position: 'bottom-right',
    primaryColor: '#e1236c',
    theme: 'dark'
  };
</script>
<script src="https://bugfeed.co.uk/widget.js" defer></script>
</body>
</html>
