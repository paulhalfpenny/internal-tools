<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class BackupWeeklySummary extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $backupCount,
        public readonly int $totalSizeInBytes,
        public readonly Carbon $newestBackupAt,
        public readonly string $diskName,
        public readonly Carbon $periodStart,
        public readonly Carbon $periodEnd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] Weekly backup summary',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.backups.weekly-summary',
        );
    }
}
