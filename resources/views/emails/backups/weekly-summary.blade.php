<p>Backup summary for {{ $periodStart->toFormattedDateString() }} to {{ $periodEnd->toFormattedDateString() }}.</p>

<ul>
    <li>Backups created: {{ $backupCount }}</li>
    <li>Total size: {{ number_format($totalSizeInBytes / 1024, 2) }} KB</li>
    <li>Newest backup: {{ $newestBackupAt->toDayDateTimeString() }}</li>
    <li>Destination: {{ $diskName }}</li>
</ul>
