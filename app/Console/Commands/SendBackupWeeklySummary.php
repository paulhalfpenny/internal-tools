<?php

namespace App\Console\Commands;

use App\Mail\BackupWeeklySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;

final class SendBackupWeeklySummary extends Command
{
    protected $signature = 'backup:send-weekly-summary';

    protected $description = 'Send a summary of backups created during the previous calendar week';

    public function handle(): int
    {
        $diskName = config('backup.backup.destination.disks.0');
        $backupName = config('backup.backup.name');

        if (! is_string($diskName) || ! is_string($backupName)) {
            $this->error('Backup destination configuration is incomplete.');

            return self::FAILURE;
        }

        $destination = BackupDestination::create($diskName, $backupName);

        if (! $destination->isReachable()) {
            $this->error("Backup destination [{$diskName}] is not reachable.");

            return self::FAILURE;
        }

        $periodEnd = now()->startOfDay();
        $periodStart = $periodEnd->copy()->subWeek();
        $backups = $destination->backups()->filter(
            fn (Backup $backup): bool => $backup->date()->greaterThanOrEqualTo($periodStart)
                && $backup->date()->lessThan($periodEnd),
        );

        if ($backups->isEmpty()) {
            $this->error("No backups were created between {$periodStart->toDateString()} and {$periodEnd->subDay()->toDateString()}.");

            return self::FAILURE;
        }

        $newestBackup = $backups->first();

        Mail::to(config('backup.notifications.mail.to'))->send(new BackupWeeklySummary(
            backupCount: $backups->count(),
            totalSizeInBytes: (int) $backups->sum(fn (Backup $backup): float => $backup->sizeInBytes()),
            newestBackupAt: $newestBackup->date(),
            diskName: $diskName,
            periodStart: $periodStart,
            periodEnd: $periodEnd->copy()->subDay(),
        ));

        $this->info('Weekly backup summary sent.');

        return self::SUCCESS;
    }
}
