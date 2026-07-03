<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Preferences</h1>
        <p class="text-sm text-gray-500 mt-1">Personal display settings for your timesheet.</p>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-6 max-w-xl">
        <label class="block text-sm font-medium text-gray-700 mb-2">Time display format</label>
        <p class="text-xs text-gray-500 mb-3">Applies to your timesheet totals and entries. You can still type hours as decimal (e.g. 0.25) or HH:MM (e.g. 0:15) either way.</p>

        <div class="space-y-2">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="radio" name="hoursFormat" value="decimal"
                       wire:click="setFormat('decimal')"
                       @checked($hoursFormat === 'decimal')>
                Decimal hours <span class="text-gray-400">(e.g. 2.8)</span>
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="radio" name="hoursFormat" value="hhmm"
                       wire:click="setFormat('hhmm')"
                       @checked($hoursFormat === 'hhmm')>
                HH:MM <span class="text-gray-400">(e.g. 2:48)</span>
            </label>
        </div>
    </div>
</div>
