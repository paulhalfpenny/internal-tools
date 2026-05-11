<?php

namespace App\Livewire\Admin\Notifications;

use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public bool $emailEnabled = false;

    public bool $slackEnabled = false;

    public ?string $savedAt = null;

    public ?string $syncedAt = null;

    public function mount(): void
    {
        Gate::authorize('access-admin');

        $this->emailEnabled = NotificationSettings::emailEnabled();
        $this->slackEnabled = NotificationSettings::slackEnabled();
    }

    public function save(): void
    {
        Gate::authorize('access-admin');

        NotificationSettings::setEmailEnabled($this->emailEnabled);
        NotificationSettings::setSlackEnabled($this->slackEnabled);

        $this->savedAt = now()->format('H:i:s');
    }

    public function syncSlack(): void
    {
        Gate::authorize('access-admin');

        Artisan::call('slack:sync-user-ids');

        $this->syncedAt = now()->format('H:i:s');
    }

    public function render(): View
    {
        return view('livewire.admin.notifications.index', [
            'slackConfigured' => filled(config('services.slack.notifications.bot_user_oauth_token')),
            'mailerDriver' => config('mail.default'),
            'unresolvedSlackUsers' => User::query()
                ->where('is_active', true)
                ->whereNull('slack_user_id')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }
}
