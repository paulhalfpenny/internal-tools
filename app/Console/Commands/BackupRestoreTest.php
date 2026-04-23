<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Downloads the latest backup from DO Spaces, restores it into the current
 * database, and runs migrations. Intended for monthly staging smoke-tests.
 *
 * Guards against non-staging environments so it is safe to schedule on all
 * environments without accidental data loss.
 */
final class BackupRestoreTest extends Command
{
    protected $signature = 'backup:restore-test
                            {--force : Skip the APP_ENV=staging guard (for local testing)}';

    protected $description = 'Restore the latest DB backup into the current database and run migrations (staging only)';

    public function handle(): int
    {
        if (! $this->option('force') && app()->environment() !== 'staging') {
            $this->info('Skipped: backup:restore-test only runs on staging (use --force to override).');

            return self::SUCCESS;
        }

        $this->info('Starting backup restore test...');

        $backupZip = $this->downloadLatestBackup();
        if ($backupZip === null) {
            $this->sendNotification(false, 'No backup found on DO Spaces.');

            return self::FAILURE;
        }

        $sqlFile = $this->extractSqlFromZip($backupZip);
        if ($sqlFile === null) {
            @unlink($backupZip);
            $this->sendNotification(false, 'Could not find SQL dump inside backup zip.');

            return self::FAILURE;
        }

        try {
            $this->dropAndRestoreDatabase($sqlFile);
            $this->call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            @unlink($backupZip);
            @unlink($sqlFile);
            $this->sendNotification(false, $e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($backupZip);
            @unlink($sqlFile);
        }

        $this->sendNotification(true, 'Restore completed and migrations ran successfully.');
        $this->info('Restore test passed.');

        return self::SUCCESS;
    }

    private function downloadLatestBackup(): ?string
    {
        $appName = str_replace(' ', '-', strtolower(config('app.name', 'laravel-backup')));
        $disk = Storage::disk('do_spaces');

        // Spatie stores backups at: {app-name}/{Y-m-d-H-i-s}/db-dumps/*.zip
        $allZips = collect($disk->allFiles($appName))
            ->filter(fn (string $f) => str_ends_with($f, '.zip'))
            ->sort()
            ->values();

        if ($allZips->isEmpty()) {
            $this->warn('No backup zips found in DO Spaces.');

            return null;
        }

        $remotePath = $allZips->last();
        $localPath = storage_path('app/backup-temp/restore-test-'.basename($remotePath));

        @mkdir(dirname($localPath), 0755, true);

        $this->info("Downloading {$remotePath}...");
        file_put_contents($localPath, $disk->get($remotePath));

        return $localPath;
    }

    private function extractSqlFromZip(string $zipPath): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('Could not open zip archive.');

            return null;
        }

        $sqlEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && str_ends_with($name, '.sql')) {
                $sqlEntry = $name;
                break;
            }
        }

        if ($sqlEntry === null) {
            $zip->close();
            $this->error('No .sql file found inside zip.');

            return null;
        }

        $sqlPath = storage_path('app/backup-temp/restore-test-'.basename($sqlEntry));
        file_put_contents($sqlPath, $zip->getFromName($sqlEntry));
        $zip->close();

        return $sqlPath;
    }

    private function dropAndRestoreDatabase(string $sqlPath): void
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        if ($dbConfig['driver'] !== 'mysql') {
            throw new \RuntimeException("restore-test only supports MySQL; driver is {$dbConfig['driver']}");
        }

        $database = $dbConfig['database'];
        $host = $dbConfig['host'];
        $port = $dbConfig['port'] ?? 3306;
        $username = $dbConfig['username'];
        $password = $dbConfig['password'];

        $this->info("Dropping and recreating database `{$database}`...");

        // Use PDO so we don't need a separate connection just to drop/create.
        $pdo = new \PDO(
            "mysql:host={$host};port={$port}",
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = null;

        // Reconnect Laravel's DB connection to the fresh database.
        DB::reconnect();

        $this->info('Importing SQL dump...');

        $passArg = $password !== '' ? '-p'.escapeshellarg($password) : '';
        $cmd = sprintf(
            'mysql -h %s -P %s -u %s %s %s < %s 2>&1',
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($username),
            $passArg,
            escapeshellarg($database),
            escapeshellarg($sqlPath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('mysql import failed: '.implode("\n", $output));
        }
    }

    private function sendNotification(bool $success, string $message): void
    {
        $subject = $success
            ? '['.config('app.name').'] Backup restore test PASSED'
            : '['.config('app.name').'] Backup restore test FAILED';

        $body = $message;
        $to = config('backup.notifications.mail.to');

        $this->info($subject.': '.$body);

        try {
            Mail::raw("{$subject}\n\n{$body}\n\nEnvironment: ".config('app.env'), function ($m) use ($subject, $to): void {
                $m->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            $this->warn('Could not send notification email: '.$e->getMessage());
        }
    }
}
