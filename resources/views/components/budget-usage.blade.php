@props(['status'])

@php
    $percent = $status->percentUsed();
    $colour = $percent > 100 ? 'text-red-700 bg-red-50 border-red-200'
        : ($percent >= 80 ? 'text-amber-700 bg-amber-50 border-amber-200'
        : 'text-green-700 bg-green-50 border-green-200');
@endphp

<div class="text-right">
    <div class="text-xs uppercase tracking-wide text-gray-500">Used to date</div>
    <div class="text-sm font-semibold text-gray-900">
        £{{ number_format($status->actualAmount, 0) }}
        <span class="text-gray-400">/</span>
        £{{ number_format($status->budgetAmount, 0) }}
    </div>
    <div class="inline-block mt-1 px-2 py-0.5 text-xs rounded border {{ $colour }}">{{ number_format($percent, 1) }}% used</div>
</div>
