# Backup Notification Summary Design

**Goal:** Replace nightly successful-backup email noise with one weekly operational summary, while retaining immediate email alerts for backup, cleanup, and health failures.

## Requirements

- The database backup and cleanup jobs continue to run nightly at their existing times.
- Successful backup, healthy-backup, and successful-cleanup events do not send individual emails.
- Failed backup, failed cleanup, and unhealthy-backup events continue to email `BACKUP_NOTIFICATION_EMAIL` (falling back to `ADMIN_EMAIL`).
- A scheduled command sends exactly one email every Monday at 09:00 in `APP_TIMEZONE`.
- The summary covers backups created during the preceding seven calendar days and includes the count, total stored size, newest backup timestamp, and destination disk.
- If the destination cannot be read or no backups exist in that period, the weekly command fails so the scheduler exposes the error instead of sending a success-shaped summary.
- Add feature coverage for the summary command, its seven-day window, and the notification configuration.
