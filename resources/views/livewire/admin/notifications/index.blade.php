<div class="max-w-2xl">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Notification settings</h1>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
        <p class="text-sm text-gray-600 mb-6">
            Master switches for the timesheet reminder system. Both default to <strong>off</strong> so the schedule can be deployed quietly until you're ready to flip them on. Per-user opt-outs (in <a href="{{ route('admin.users') }}" class="text-blue-600 hover:underline">Admin → Users</a>) and the <em>notifications paused until</em> date still apply on top of these.
        </p>

        <div class="space-y-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <label class="text-sm font-semibold text-gray-900">Email reminders</label>
                    <p class="text-xs text-gray-500 mt-1">Sends mid-week, weekly-overdue, monthly-overdue, and Friday manager-digest emails via Resend.</p>
                    <p class="text-xs {{ $mailerDriver === 'resend' ? 'text-green-700' : 'text-amber-700' }} mt-1">
                        Mail driver: <span class="font-mono">{{ $mailerDriver }}</span>
                        @if ($mailerDriver !== 'resend')
                            — set <span class="font-mono">MAIL_MAILER=resend</span> in <span class="font-mono">.env</span> before turning this on in production.
                        @endif
                    </p>
                </div>
                <label class="inline-flex items-center cursor-pointer mt-1">
                    <input wire:model.live="emailEnabled" type="checkbox" class="sr-only peer">
                    <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#E1236C]"></div>
                </label>
            </div>

            <div class="flex items-start justify-between gap-4 pt-5 border-t border-gray-100">
                <div>
                    <label class="text-sm font-semibold text-gray-900">Slack DMs</label>
                    <p class="text-xs text-gray-500 mt-1">Sends mid-week, weekly-overdue and monthly-overdue DMs via the Slack bot. (Manager digest is email-only.)</p>
                    <p class="text-xs {{ $slackConfigured ? 'text-green-700' : 'text-amber-700' }} mt-1">
                        @if ($slackConfigured)
                            Bot token is configured.
                        @else
                            Bot token is missing — set <span class="font-mono">SLACK_BOT_USER_OAUTH_TOKEN</span> in <span class="font-mono">.env</span> before turning this on. DMs will silently no-op until then.
                        @endif
                    </p>
                </div>
                <label class="inline-flex items-center cursor-pointer mt-1">
                    <input wire:model.live="slackEnabled" type="checkbox" class="sr-only peer">
                    <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#E1236C]"></div>
                </label>
            </div>
        </div>

        <div class="flex items-center gap-3 mt-6 pt-5 border-t border-gray-100">
            <button wire:click="save" class="px-4 py-2 bg-[#002F5F] text-white text-sm font-medium rounded-md hover:bg-[#004080]">Save changes</button>
            @if ($savedAt)
                <span wire:key="saved-{{ $savedAt }}" class="text-xs text-green-700">Saved at {{ $savedAt }}.</span>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="text-base font-semibold text-gray-900">Slack DM coverage</h2>
                <p class="text-xs text-gray-500 mt-1">Active users where <span class="font-mono">users.lookupByEmail</span> couldn't find a Slack account. They'll still receive email reminders — only the Slack channel is missed.</p>
            </div>
            <div class="flex items-center gap-3">
                @if ($syncedAt)
                    <span class="text-xs text-green-700" wire:key="synced-{{ $syncedAt }}">Synced at {{ $syncedAt }}.</span>
                @endif
                <button wire:click="syncSlack" wire:loading.attr="disabled" class="px-3 py-1.5 text-xs border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50">
                    <span wire:loading.remove wire:target="syncSlack">Re-run sync now</span>
                    <span wire:loading wire:target="syncSlack">Syncing…</span>
                </button>
            </div>
        </div>

        @if ($unresolvedSlackUsers->isEmpty())
            <p class="text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2">All active users are matched to a Slack account.</p>
        @else
            <p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mb-3">
                <strong>{{ $unresolvedSlackUsers->count() }}</strong> active {{ \Illuminate\Support\Str::plural('user', $unresolvedSlackUsers->count()) }} couldn't be resolved. Most common cause: their Slack account is registered under a different email.
            </p>
            <ul class="divide-y divide-gray-100 text-sm border border-gray-100 rounded-md">
                @foreach ($unresolvedSlackUsers as $user)
                    <li class="flex items-center justify-between px-3 py-2">
                        <div>
                            <span class="font-medium text-gray-900">{{ $user->name }}</span>
                            <span class="text-gray-500 ml-2">{{ $user->email }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <details class="mt-4 text-xs text-gray-600">
                <summary class="cursor-pointer text-gray-700 font-medium">How to fix</summary>
                <ol class="list-decimal list-inside mt-2 space-y-1 pl-1">
                    <li>Ask the user to add their <span class="font-mono">@filteragency.com</span> email to their Slack profile (Slack → Profile → Edit profile → "Add another email"). They can keep their existing Slack account intact.</li>
                    <li>Alternatively, if the user has left the company, mark them inactive in <a href="{{ route('admin.users') }}" class="text-blue-600 hover:underline">Admin → Users</a> and they'll drop off the list.</li>
                    <li>Click <em>Re-run sync now</em> above to verify, or wait for the nightly 03:00 sync.</li>
                </ol>
            </details>
        @endif
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
        <strong>Heads up:</strong> turning a channel on takes effect immediately for the next scheduled run. The current schedule is Thu 09:30 (mid-week), Mon 09:30 (weekly), 1st @ 09:30 (monthly), Fri 16:00 (manager digest).
    </div>
</div>
