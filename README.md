# Filter Internal Tools

## Timesheet completion notifications

Email + Slack reminders that chase users when their timesheets fall behind.

**Channels.** Email is sent via [Resend](https://resend.com) (`MAIL_MAILER=resend`, `RESEND_KEY=`). Slack DMs use a bot token at `SLACK_BOT_USER_OAUTH_TOKEN` and require the `chat:write` and `users:read.email` scopes.

**Per-user settings** live on `users` (managed in **Admin → Users**):

- `weekly_capacity_hours` — weekly target (default 40h, override per user).
- `reports_to_user_id` — line manager (drives the Friday digest).
- `notifications_paused_until` — vacation pause; no reminders fire until it passes.
- `email_notifications_enabled`, `slack_notifications_enabled` — per-channel opt-out.
- `slack_user_id` — resolved nightly from the user's email.

**Schedule** (London time, registered in `bootstrap/app.php`):

| When | Command | What it does |
| --- | --- | --- |
| Thu 09:30 | `timesheets:send-reminders --type=mid-week` | DM + email to anyone <60% of weekly target by end of Wednesday |
| Mon 09:30 | `timesheets:send-reminders --type=weekly-overdue` | Chases anyone who finished last week below target |
| 1st @ 09:30 | `timesheets:send-reminders --type=monthly-overdue` | Chases anyone who finished last month below pro-rata target |
| Fri 16:00 | `timesheets:send-reminders --type=manager-digest` | Sends each manager a digest of their direct reports who are behind; admins additionally get a global digest |
| 03:00 daily | `slack:sync-user-ids` | Resolves `slack_user_id` for new joiners via `users.lookupByEmail` |

Runs depend on `php artisan schedule:run` being wired up in cron in production. The reminder command takes:

- `--dry-run` — print the recipient list, send nothing.
- `--user=<id>` — limit dispatch to a single user (handy for staging tests).

Example: `php artisan timesheets:send-reminders --type=mid-week --dry-run` or `--user=42` for a single-recipient end-to-end test against Resend / Slack staging.

**Production prerequisites.** Verify the Filter sending domain in the Resend dashboard, set `RESEND_KEY` in the production `.env`, install the Slack app to the workspace and capture the bot token, then run `php artisan slack:sync-user-ids` once to populate `slack_user_id` for the existing team.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
