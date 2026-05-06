<?php

namespace App\Livewire\Admin\Notifications;

use App\Settings\NotificationSettings;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public bool $emailEnabled = false;

    public bool $slackEnabled = false;

    public ?string $savedAt = null;

    public function mount(): void
    {
        $this->emailEnabled = NotificationSettings::emailEnabled();
        $this->slackEnabled = NotificationSettings::slackEnabled();
    }

    public function save(): void
    {
        NotificationSettings::setEmailEnabled($this->emailEnabled);
        NotificationSettings::setSlackEnabled($this->slackEnabled);

        $this->savedAt = now()->format('H:i:s');
    }

    public function render(): View
    {
        return view('livewire.admin.notifications.index', [
            'slackConfigured' => filled(config('services.slack.notifications.bot_user_oauth_token')),
            'mailerDriver' => config('mail.default'),
        ]);
    }
}
