<?php

use App\Enums\Role;
use App\Livewire\Admin\Notifications\Index as AdminNotifications;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\MidWeekTimesheetNudge;
use App\Settings\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

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

test('both notification toggles default to off on a fresh install', function () {
    expect(NotificationSettings::emailEnabled())->toBeFalse();
    expect(NotificationSettings::slackEnabled())->toBeFalse();
});

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

test('flipping slack on dispatches slack-only when email stays off', function () {
    Notification::fake();
    Carbon::setTestNow('2026-05-07 09:30:00');
    NotificationSettings::setSlackEnabled(true);

    $behind = User::factory()->create(['name' => 'Behind']);
    killSwitchEntry($behind, '2026-05-04', 1);

    $this->artisan('timesheets:send-reminders', ['--type' => 'mid-week'])->assertSuccessful();

    Notification::assertSentTo($behind, MidWeekTimesheetNudge::class, function (MidWeekTimesheetNudge $n, array $channels) {
        return $channels === ['slack'];
    });
});

test('admin notifications screen reflects and persists changes', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    Livewire::test(AdminNotifications::class)
        ->assertSet('emailEnabled', false)
        ->assertSet('slackEnabled', false)
        ->set('emailEnabled', true)
        ->call('save');

    NotificationSettings::flushCache();
    expect(NotificationSettings::emailEnabled())->toBeTrue();
    expect(NotificationSettings::slackEnabled())->toBeFalse();
});

test('non-admin cannot reach the notifications settings page', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);

    $this->get(route('admin.notifications'))->assertForbidden();
});

test('unresolved Slack users panel lists active users with no slack_user_id', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $resolved = User::factory()->create(['name' => 'Resolved', 'slack_user_id' => 'UABC123']);
    $unresolvedActive = User::factory()->create(['name' => 'Unresolved Active', 'slack_user_id' => null]);
    $unresolvedInactive = User::factory()->create(['name' => 'Unresolved Inactive', 'slack_user_id' => null, 'is_active' => false]);

    $this->actingAs($admin);

    $component = Livewire::test(AdminNotifications::class);
    $names = $component->viewData('unresolvedSlackUsers')->pluck('name')->all();

    expect($names)->toContain('Unresolved Active');
    expect($names)->not->toContain('Resolved');
    expect($names)->not->toContain('Unresolved Inactive');
});
