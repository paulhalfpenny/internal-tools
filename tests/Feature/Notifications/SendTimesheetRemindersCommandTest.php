<?php

use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\ManagerWeeklyDigest;
use App\Notifications\MidWeekTimesheetNudge;
use App\Notifications\MonthlyTimesheetOverdue;
use App\Notifications\WeeklyTimesheetOverdue;
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

test('mid-week sends to users below threshold and skips paused/inactive', function () {
    Notification::fake();
    Carbon::setTestNow('2026-05-07 09:30:00');

    $behind = User::factory()->create(['name' => 'Behind']);
    $onTrack = User::factory()->create(['name' => 'OnTrack']);
    $paused = User::factory()->create(['name' => 'Paused', 'notifications_paused_until' => '2026-05-10']);
    $inactive = User::factory()->create(['name' => 'Inactive', 'is_active' => false]);

    reminderEntry($behind, '2026-05-04', 5);
    reminderEntry($behind, '2026-05-05', 5);
    reminderEntry($behind, '2026-05-06', 5);
    reminderEntry($onTrack, '2026-05-04', 8);
    reminderEntry($onTrack, '2026-05-05', 8);
    reminderEntry($onTrack, '2026-05-06', 8);
    reminderEntry($paused, '2026-05-04', 0.5);

    $this->artisan('timesheets:send-reminders', ['--type' => 'mid-week'])->assertSuccessful();

    Notification::assertSentTo($behind, MidWeekTimesheetNudge::class);
    Notification::assertNotSentTo($onTrack, MidWeekTimesheetNudge::class);
    Notification::assertNotSentTo($paused, MidWeekTimesheetNudge::class);
    Notification::assertNotSentTo($inactive, MidWeekTimesheetNudge::class);
});

test('weekly-overdue dispatches for users below target last week', function () {
    Notification::fake();
    Carbon::setTestNow('2026-05-11 09:30:00');

    $missed = User::factory()->create(['name' => 'Missed']);
    $hit = User::factory()->create(['name' => 'Hit']);

    foreach (range(0, 4) as $i) {
        reminderEntry($missed, '2026-05-0'.(4 + $i), 7);
        reminderEntry($hit, '2026-05-0'.(4 + $i), 9);
    }

    $this->artisan('timesheets:send-reminders', ['--type' => 'weekly-overdue'])->assertSuccessful();

    Notification::assertSentTo($missed, WeeklyTimesheetOverdue::class);
    Notification::assertNotSentTo($hit, WeeklyTimesheetOverdue::class);
});

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

test('manager digest scopes to direct reports and admins receive global view', function () {
    Notification::fake();
    Carbon::setTestNow('2026-05-08 16:00:00');

    $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Admin']);
    $manager1 = User::factory()->create(['role' => Role::Manager, 'name' => 'Manager One']);
    $manager2 = User::factory()->create(['role' => Role::Manager, 'name' => 'Manager Two']);

    $u1 = User::factory()->create(['name' => 'U1', 'reports_to_user_id' => $manager1->id]);
    $u2 = User::factory()->create(['name' => 'U2', 'reports_to_user_id' => $manager1->id]);
    $u3 = User::factory()->create(['name' => 'U3', 'reports_to_user_id' => $manager2->id]);
    $u4 = User::factory()->create(['name' => 'U4', 'reports_to_user_id' => $manager2->id]);

    foreach (range(0, 4) as $i) {
        $date = '2026-05-0'.(4 + $i);
        reminderEntry($u1, $date, 1);
        reminderEntry($u3, $date, 1);
        reminderEntry($u2, $date, 9);
        reminderEntry($u4, $date, 9);
        reminderEntry($admin, $date, 9);
        reminderEntry($manager1, $date, 9);
        reminderEntry($manager2, $date, 9);
    }

    $this->artisan('timesheets:send-reminders', ['--type' => 'manager-digest'])->assertSuccessful();

    Notification::assertSentTo($manager1, ManagerWeeklyDigest::class, function (ManagerWeeklyDigest $n) {
        $names = collect($n->rows)->pluck('name')->all();

        return ! $n->isAdminDigest && $names === ['U1'];
    });

    Notification::assertSentTo($manager2, ManagerWeeklyDigest::class, function (ManagerWeeklyDigest $n) {
        $names = collect($n->rows)->pluck('name')->all();

        return ! $n->isAdminDigest && $names === ['U3'];
    });

    Notification::assertSentTo($admin, ManagerWeeklyDigest::class, function (ManagerWeeklyDigest $n) {
        $names = collect($n->rows)->pluck('name')->sort()->values()->all();

        return $n->isAdminDigest && $names === ['U1', 'U3'];
    });
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
