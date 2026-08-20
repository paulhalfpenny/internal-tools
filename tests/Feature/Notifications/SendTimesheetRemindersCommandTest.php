<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\MonthlyTimesheetOverdue;
use App\Settings\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    NotificationSettings::setEmailEnabled(true);
    NotificationSettings::setSlackEnabled(true);
});

function reminderEntry(User $user, string $date, float $hours): void
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

test('monthly-overdue dispatches for users below pro-rata target last month', function () {
    Notification::fake();
    Carbon::setTestNow('2026-05-01 09:30:00');

    $under = User::factory()->create();
    $over = User::factory()->create();

    foreach (range(0, 19) as $i) {
        $date = Carbon::parse('2026-04-01')->addDays($i)->toDateString();
        reminderEntry($under, $date, 4);
        reminderEntry($over, $date, 9);
    }

    $this->artisan('timesheets:send-reminders', ['--type' => 'monthly-overdue'])->assertSuccessful();

    Notification::assertSentTo($under, MonthlyTimesheetOverdue::class);
    Notification::assertNotSentTo($over, MonthlyTimesheetOverdue::class);
});

test('dry-run lists recipients but sends nothing', function () {
    Notification::fake();
    Carbon::setTestNow('2026-05-07 09:30:00');

    $behind = User::factory()->create(['name' => 'Behind']);
    reminderEntry($behind, '2026-05-04', 5);
    reminderEntry($behind, '2026-05-05', 5);
    reminderEntry($behind, '2026-05-06', 5);

    $this->artisan('timesheets:send-reminders', ['--type' => 'mid-week', '--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Behind');

    Notification::assertNothingSent();
});
