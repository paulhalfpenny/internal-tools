<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\MidWeekTimesheetNudge;
use App\Settings\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    NotificationSettings::flushCache();
});

function killSwitchEntry(User $user, string $date, float $hours): void
{
    $projectId = Project::query()->value('id') ?? Project::factory()->create()->id;
    $taskId = Task::query()->value('id') ?? Task::factory()->create()->id;

    TimeEntry::create([
        'user_id' => $user->id,
        'project_id' => $projectId,
        'task_id' => $taskId,
        'spent_on' => $date,
        'hours' => $hours,
        'is_running' => false,
        'is_billable' => true,
        'billable_rate_snapshot' => 80,
        'billable_amount' => 80 * $hours,
    ]);
}

test('command sends nothing when both global toggles are off', function () {
    Notification::fake();
    Carbon::setTestNow('2026-05-07 09:30:00');

    $behind = User::factory()->create(['name' => 'Behind']);
    killSwitchEntry($behind, '2026-05-04', 1);
    killSwitchEntry($behind, '2026-05-05', 1);
    killSwitchEntry($behind, '2026-05-06', 1);

    $this->artisan('timesheets:send-reminders', ['--type' => 'mid-week'])->assertSuccessful();

    Notification::assertNothingSent();
});

test('flipping email on dispatches mail-only when slack stays off', function () {
    Notification::fake();
    Carbon::setTestNow('2026-05-07 09:30:00');
    NotificationSettings::setEmailEnabled(true);

    $behind = User::factory()->create(['name' => 'Behind']);
    killSwitchEntry($behind, '2026-05-04', 1);
    killSwitchEntry($behind, '2026-05-05', 1);
    killSwitchEntry($behind, '2026-05-06', 1);

    $this->artisan('timesheets:send-reminders', ['--type' => 'mid-week'])->assertSuccessful();

    Notification::assertSentTo($behind, MidWeekTimesheetNudge::class, function (MidWeekTimesheetNudge $n, array $channels) {
        return $channels === ['mail'];
    });
});
