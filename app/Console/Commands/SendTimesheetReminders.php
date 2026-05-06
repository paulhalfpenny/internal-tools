<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\ManagerWeeklyDigest;
use App\Notifications\MidWeekTimesheetNudge;
use App\Notifications\MonthlyTimesheetOverdue;
use App\Notifications\WeeklyTimesheetOverdue;
use App\Services\TimesheetCompletionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendTimesheetReminders extends Command
{
    protected $signature = 'timesheets:send-reminders
        {--type= : One of mid-week, weekly-overdue, monthly-overdue, manager-digest}
        {--dry-run : List recipients without dispatching}
        {--user= : Limit to a single user id (for staging)}';

    protected $description = 'Email + Slack reminders for users behind on their timesheet.';

    public function handle(TimesheetCompletionService $service): int
    {
        $type = $this->option('type');
        if (! in_array($type, ['mid-week', 'weekly-overdue', 'monthly-overdue', 'manager-digest'], true)) {
            $this->error('Pass --type=mid-week|weekly-overdue|monthly-overdue|manager-digest');

            return self::INVALID;
        }

        $now = CarbonImmutable::now();

        return match ($type) {
            'mid-week' => $this->dispatchMidWeek($service, $now),
            'weekly-overdue' => $this->dispatchWeeklyOverdue($service, $now),
            'monthly-overdue' => $this->dispatchMonthlyOverdue($service, $now),
            'manager-digest' => $this->dispatchManagerDigest($service, $now),
        };
    }

    private function dispatchMidWeek(TimesheetCompletionService $service, CarbonImmutable $now): int
    {
        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY);
        $rows = $this->limitToUser($service->usersBelowMidWeekThreshold($weekStart));

        $this->info("Mid-week nudges: {$rows->count()} user(s) below 60% by Wednesday.");
        foreach ($rows as $row) {
            $this->line(sprintf('  - %s: %.1f / %.1fh', $row['user']->name, $row['hours'], $row['target']));
            if (! $this->option('dry-run')) {
                $row['user']->notify(new MidWeekTimesheetNudge($row['hours'], $row['target'], $row['threshold'], $weekStart));
            }
        }

        return self::SUCCESS;
    }

    private function dispatchWeeklyOverdue(TimesheetCompletionService $service, CarbonImmutable $now): int
    {
        $previousWeekStart = $now->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
        $rows = $this->limitToUser($service->usersWithIncompleteWeek($previousWeekStart));

        $this->info("Weekly overdue: {$rows->count()} user(s) below target for previous week.");
        foreach ($rows as $row) {
            $this->line(sprintf('  - %s: %.1f / %.1fh', $row['user']->name, $row['hours'], $row['target']));
            if (! $this->option('dry-run')) {
                $row['user']->notify(new WeeklyTimesheetOverdue($row['hours'], $row['target'], $previousWeekStart));
            }
        }

        return self::SUCCESS;
    }

    private function dispatchMonthlyOverdue(TimesheetCompletionService $service, CarbonImmutable $now): int
    {
        $previousMonthStart = $now->startOfMonth()->subMonth();
        $rows = $this->limitToUser($service->usersWithIncompleteMonth($previousMonthStart));

        $this->info("Monthly overdue: {$rows->count()} user(s) below target for previous month.");
        foreach ($rows as $row) {
            $this->line(sprintf('  - %s: %.1f / %.1fh', $row['user']->name, $row['hours'], $row['target']));
            if (! $this->option('dry-run')) {
                $row['user']->notify(new MonthlyTimesheetOverdue($row['hours'], $row['target'], $previousMonthStart));
            }
        }

        return self::SUCCESS;
    }

    private function dispatchManagerDigest(TimesheetCompletionService $service, CarbonImmutable $now): int
    {
        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY);
        $allRows = $service->usersWithIncompleteWeek($weekStart);

        $rowsByManager = $allRows->groupBy(fn (array $row) => $row['user']->reports_to_user_id ?? 0);

        $managers = User::query()->notificationsActive()
            ->whereIn('role', [Role::Manager->value, Role::Admin->value])
            ->orderBy('name')
            ->get();

        $sent = 0;
        foreach ($managers as $manager) {
            $reportRows = ($rowsByManager[$manager->id] ?? collect())
                ->map(fn (array $row) => [
                    'name' => $row['user']->name,
                    'email' => $row['user']->email,
                    'hours' => $row['hours'],
                    'target' => $row['target'],
                ])
                ->values()
                ->all();

            if ($reportRows === []) {
                continue;
            }

            if ($this->option('user') !== null && (int) $this->option('user') !== $manager->id) {
                continue;
            }

            $this->line(sprintf('  → %s (%d direct report(s) behind)', $manager->name, count($reportRows)));
            if (! $this->option('dry-run')) {
                $manager->notify(new ManagerWeeklyDigest($reportRows, $weekStart, isAdminDigest: false));
            }
            $sent++;
        }

        $admins = User::query()->notificationsActive()
            ->where('role', Role::Admin->value)
            ->orderBy('name')
            ->get();

        $globalRows = $allRows->map(fn (array $row) => [
            'name' => $row['user']->name,
            'email' => $row['user']->email,
            'hours' => $row['hours'],
            'target' => $row['target'],
        ])->values()->all();

        foreach ($admins as $admin) {
            if ($this->option('user') !== null && (int) $this->option('user') !== $admin->id) {
                continue;
            }
            $this->line(sprintf('  ⇒ %s (admin overview, %d behind)', $admin->name, count($globalRows)));
            if (! $this->option('dry-run')) {
                $admin->notify(new ManagerWeeklyDigest($globalRows, $weekStart, isAdminDigest: true));
            }
            $sent++;
        }

        $this->info("Manager digest: dispatched to {$sent} recipient(s).");

        return self::SUCCESS;
    }

    /**
     * @template TKey
     * @template TValue of array{user: User}
     *
     * @param  Collection<TKey, TValue>  $rows
     * @return Collection<TKey, TValue>
     */
    private function limitToUser(Collection $rows): Collection
    {
        $userOption = $this->option('user');
        if ($userOption === null) {
            return $rows;
        }

        return $rows->filter(fn (array $row) => $row['user']->id === (int) $userOption)->values();
    }
}
