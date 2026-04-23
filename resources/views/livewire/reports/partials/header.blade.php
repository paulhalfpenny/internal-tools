{{-- Report header: title, period selector, totals cards, filters --}}
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        @isset($backLink)
        <a href="{{ $backLink }}" class="text-sm text-gray-500 hover:text-gray-700">← Reports</a>
        @endisset
        <h1 class="text-xl font-semibold text-gray-900">{{ $title }}</h1>
    </div>

    <div class="flex items-center gap-3">
        <button wire:click="export"
                class="text-sm text-gray-600 border border-gray-300 rounded-md px-3 py-1.5 hover:bg-gray-50 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Export CSV
        </button>

        <select wire:model.live="preset"
                class="text-sm border border-gray-300 rounded-md px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="this_week">This week</option>
            <option value="this_month">This month</option>
            <option value="last_month">Last month</option>
            <option value="last_3">Last 3 months</option>
            <option value="this_year">This year</option>
            <option value="last_year">Last year</option>
            <option value="custom">Custom</option>
        </select>

        @if($preset === 'custom')
        <input type="date" wire:model.live="from"
               class="text-sm border border-gray-300 rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
        <span class="text-gray-400 text-sm">–</span>
        <input type="date" wire:model.live="to"
               class="text-sm border border-gray-300 rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
        @else
        <span class="text-sm text-gray-500">
            {{ \Carbon\CarbonImmutable::parse($from)->format('d M Y') }} – {{ \Carbon\CarbonImmutable::parse($to)->format('d M Y') }}
        </span>
        @endif
    </div>
</div>

{{-- Totals --}}
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Total hours</p>
        <p class="text-2xl font-semibold text-gray-900">{{ number_format($totals->totalHours, 1) }}</p>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Billable hours</p>
        <p class="text-2xl font-semibold text-gray-900">{{ number_format($totals->billableHours, 1) }}</p>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Billable %</p>
        <p class="text-2xl font-semibold text-gray-900">{{ $totals->billablePercent }}%</p>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Billable amount</p>
        <p class="text-2xl font-semibold text-gray-900">£{{ number_format($totals->billableAmount, 2) }}</p>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Uninvoiced</p>
        <p class="text-2xl font-semibold text-blue-600">£{{ number_format($totals->uninvoicedAmount, 2) }}</p>
    </div>
</div>

{{-- Filters --}}
<div class="flex items-center gap-6 mb-4 text-sm text-gray-600">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" wire:model.live="activeProjectsOnly"
               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
        Active projects only
    </label>
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" wire:model.live="includeFixedFee"
               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
        Include Fixed Fee projects
    </label>
</div>
