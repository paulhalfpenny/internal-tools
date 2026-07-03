<?php

namespace App\Livewire\Profile;

use App\Domain\TimeTracking\HoursFormatter;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Preferences extends Component
{
    public string $hoursFormat = HoursFormatter::FORMAT_DECIMAL;

    public function mount(): void
    {
        $this->hoursFormat = $this->authUser()->hoursDisplayFormat();
    }

    public function setFormat(string $format): void
    {
        if (! in_array($format, [HoursFormatter::FORMAT_DECIMAL, HoursFormatter::FORMAT_HHMM], true)) {
            return;
        }

        $user = $this->authUser();
        $preferences = $user->schedule_preferences ?? [];
        $preferences['hours_display_format'] = $format;

        $user->forceFill(['schedule_preferences' => $preferences])->save();
        $this->hoursFormat = $format;
    }

    public function render(): View
    {
        return view('livewire.profile.preferences');
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
