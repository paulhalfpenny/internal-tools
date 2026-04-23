<div class="space-y-8">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">JDW Monthly Export</h1>
            <p class="mt-0.5 text-sm text-gray-500">Generates time hours for Olly's monthly workbook.</p>
        </div>
        <div class="flex items-center gap-3">
            <input
                type="month"
                wire:model.live="month"
                class="rounded border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
            <button
                wire:click="export"
                class="inline-flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
            >
                Download .xlsx
            </button>
        </div>
    </div>

    {{-- ── Block 1: Programme Management Hours ──────────────────────────────── --}}
    <div
        x-data="{
            tsv: {{ Js::from($this->programmeTsvRow($programmeTasks, $this->programmeRow)) }},
            copied: false,
            copy() {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(this.tsv).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); });
                } else {
                    this.$refs.fallback.classList.remove('hidden');
                    this.$refs.fallback.select();
                }
            }
        }"
        class="rounded-lg border border-gray-200 bg-white shadow-sm"
    >
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-gray-700">Block 1 — Programme Management Hours</h2>
            <button
                @click="copy()"
                class="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50"
            >
                <span x-show="!copied">Copy TSV</span>
                <span x-show="copied" x-cloak class="text-green-600">Copied!</span>
            </button>
        </div>
        <textarea x-ref="fallback" class="hidden w-full resize-none p-2 font-mono text-xs" rows="2">{{ $this->programmeTsvRow($programmeTasks, $this->programmeRow) }}</textarea>
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead>
                    <tr class="bg-gray-50">
                        @foreach ($programmeTasks as $task)
                            <th class="whitespace-nowrap px-2 py-2 text-center font-medium text-gray-600">{{ $task }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        @foreach ($programmeTasks as $task)
                            @php $h = $this->programmeRow[$task] ?? null; @endphp
                            <td class="px-2 py-2 tabular-nums text-gray-800">
                                {{ $h !== null ? number_format($h, 2) : '' }}
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Block 2: Projects Hours ────────────────────────────────────────────── --}}
    <div
        x-data="{
            tsv: {{ Js::from($this->blockTsv($projectsTasks, $this->projectsRows)) }},
            copied: false,
            copy() {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(this.tsv).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); });
                } else {
                    this.$refs.fallback.classList.remove('hidden');
                    this.$refs.fallback.select();
                }
            }
        }"
        class="rounded-lg border border-gray-200 bg-white shadow-sm"
    >
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-gray-700">Block 2 — Projects Hours</h2>
            <button
                @click="copy()"
                class="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50"
            >
                <span x-show="!copied">Copy TSV</span>
                <span x-show="copied" x-cloak class="text-green-600">Copied!</span>
            </button>
        </div>
        <textarea x-ref="fallback" class="hidden w-full resize-none p-2 font-mono text-xs" rows="4">{{ $this->blockTsv($projectsTasks, $this->projectsRows) }}</textarea>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Project</th>
                        <th class="px-2 py-2 text-left font-medium text-gray-500">Code</th>
                        @foreach ($projectsTasks as $task)
                            <th class="whitespace-nowrap px-2 py-2 text-right font-medium text-gray-600">{{ $task }}</th>
                        @endforeach
                        <th class="px-2 py-2 text-right font-medium text-gray-600">Total</th>
                        <th class="px-2 py-2 text-left font-medium text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse ($this->projectsRows as $project)
                        @php $rowTotal = collect($project['hours'])->whereNotNull()->sum(); @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="max-w-[200px] truncate px-3 py-1.5 font-medium text-gray-800" title="{{ $project['name'] }}">
                                {{ $project['name'] }}
                            </td>
                            <td class="px-2 py-1.5 text-gray-400">{{ $project['code'] ?? '' }}</td>
                            @foreach ($projectsTasks as $task)
                                @php $h = $project['hours'][$task] ?? null; @endphp
                                <td class="px-2 py-1.5 text-right tabular-nums text-gray-700">
                                    {{ $h !== null ? number_format($h, 2) : '' }}
                                </td>
                            @endforeach
                            <td class="px-2 py-1.5 text-right tabular-nums font-semibold text-gray-800">
                                {{ $rowTotal > 0 ? number_format($rowTotal, 2) : '' }}
                            </td>
                            <td class="max-w-[160px] truncate px-2 py-1.5 text-gray-400" title="{{ $project['jdw_status'] ?? '' }}">
                                {{ $project['jdw_status'] ?? '' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($projectsTasks) + 3 }}" class="px-3 py-6 text-center text-sm text-gray-400">No projects tagged for JDW — set jdw_category on projects in Admin.</td></tr>
                    @endforelse
                </tbody>
                @if ($this->projectsRows->isNotEmpty())
                    @php
                        $grandTotal = $this->projectsRows->sum(fn($p) => collect($p['hours'])->whereNotNull()->sum());
                    @endphp
                    <tfoot>
                        <tr class="border-t border-gray-200 bg-gray-50 font-semibold">
                            <td colspan="2" class="px-3 py-2 text-xs text-gray-600">Total</td>
                            @foreach ($projectsTasks as $task)
                                @php $col = $this->projectsRows->sum(fn($p) => $p['hours'][$task] ?? 0); @endphp
                                <td class="px-2 py-2 text-right tabular-nums text-xs text-gray-700">
                                    {{ $col > 0 ? number_format($col, 2) : '' }}
                                </td>
                            @endforeach
                            <td class="px-2 py-2 text-right tabular-nums text-xs text-gray-800">{{ number_format($grandTotal, 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- ── Block 3: Support & Maintenance Hours ──────────────────────────────── --}}
    <div
        x-data="{
            tsv: {{ Js::from($this->blockTsv($smTasks, $this->smRows)) }},
            copied: false,
            copy() {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(this.tsv).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); });
                } else {
                    this.$refs.fallback.classList.remove('hidden');
                    this.$refs.fallback.select();
                }
            }
        }"
        class="rounded-lg border border-gray-200 bg-white shadow-sm"
    >
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-gray-700">Block 3 — Support &amp; Maintenance Hours</h2>
            <button
                @click="copy()"
                class="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50"
            >
                <span x-show="!copied">Copy TSV</span>
                <span x-show="copied" x-cloak class="text-green-600">Copied!</span>
            </button>
        </div>
        <textarea x-ref="fallback" class="hidden w-full resize-none p-2 font-mono text-xs" rows="4">{{ $this->blockTsv($smTasks, $this->smRows) }}</textarea>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Project</th>
                        <th class="px-2 py-2 text-left font-medium text-gray-500">Code</th>
                        @foreach ($smTasks as $task)
                            <th class="whitespace-nowrap px-2 py-2 text-right font-medium text-gray-600">{{ $task }}</th>
                        @endforeach
                        <th class="px-2 py-2 text-right font-medium text-gray-600">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse ($this->smRows as $project)
                        @php $rowTotal = collect($project['hours'])->whereNotNull()->sum(); @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="max-w-[200px] truncate px-3 py-1.5 font-medium text-gray-800" title="{{ $project['name'] }}">
                                {{ $project['name'] }}
                            </td>
                            <td class="px-2 py-1.5 text-gray-400">{{ $project['code'] ?? '' }}</td>
                            @foreach ($smTasks as $task)
                                @php $h = $project['hours'][$task] ?? null; @endphp
                                <td class="px-2 py-1.5 text-right tabular-nums text-gray-700">
                                    {{ $h !== null ? number_format($h, 2) : '' }}
                                </td>
                            @endforeach
                            <td class="px-2 py-1.5 text-right tabular-nums font-semibold text-gray-800">
                                {{ $rowTotal > 0 ? number_format($rowTotal, 2) : '' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($smTasks) + 3 }}" class="px-3 py-6 text-center text-sm text-gray-400">No projects tagged for S&amp;M — set jdw_category on projects in Admin.</td></tr>
                    @endforelse
                </tbody>
                @if ($this->smRows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t border-gray-200 bg-gray-50 font-semibold">
                            <td colspan="2" class="px-3 py-2 text-xs text-gray-600">Total</td>
                            @foreach ($smTasks as $task)
                                @php $col = $this->smRows->sum(fn($p) => $p['hours'][$task] ?? 0); @endphp
                                <td class="px-2 py-2 text-right tabular-nums text-xs text-gray-700">
                                    {{ $col > 0 ? number_format($col, 2) : '' }}
                                </td>
                            @endforeach
                            <td class="px-2 py-2 text-right tabular-nums text-xs text-gray-800">
                                {{ number_format($this->smRows->sum(fn($p) => collect($p['hours'])->whereNotNull()->sum()), 2) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

</div>
