<?php

use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('aggregates events from every calendar the user has access to and de-duplicates', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok-123',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        // Calendar list with two calendars: primary + a shared 'team@filter.agency'.
        'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [
                ['id' => 'primary'],
                ['id' => 'team@filter.agency'],
            ],
        ]),
        // Primary returns one event
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [[
                'id' => 'evt-A',
                'summary' => 'Personal call',
                'start' => ['dateTime' => '2026-05-06T09:00:00+01:00'],
                'end' => ['dateTime' => '2026-05-06T09:30:00+01:00'],
            ]],
        ]),
        // Team calendar returns two events, one of which (evt-A) is also on primary
        'https://www.googleapis.com/calendar/v3/calendars/team*' => Http::response([
            'items' => [
                [
                    'id' => 'evt-A', // duplicate — should be deduped
                    'summary' => 'Personal call',
                    'start' => ['dateTime' => '2026-05-06T09:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T09:30:00+01:00'],
                ],
                [
                    'id' => 'evt-B',
                    'summary' => 'Agency standup',
                    'start' => ['dateTime' => '2026-05-06T10:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T10:30:00+01:00'],
                ],
            ],
        ]),
    ]);

    $events = (new CalendarService)->getEventsForDate($user, Carbon::parse('2026-05-06'));

    expect($events)->toHaveCount(2);
    $titles = collect($events)->pluck('title')->all();
    expect($titles)->toContain('Personal call', 'Agency standup');
});

test('skips cancelled events and all-day events', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [['id' => 'primary']],
        ]),
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [
                [
                    'id' => 'evt-cancelled',
                    'status' => 'cancelled',
                    'summary' => 'Cancelled meeting',
                    'start' => ['dateTime' => '2026-05-06T09:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T09:30:00+01:00'],
                ],
                [
                    'id' => 'evt-allday',
                    'summary' => 'Public holiday',
                    'start' => ['date' => '2026-05-06'],
                    'end' => ['date' => '2026-05-07'],
                ],
                [
                    'id' => 'evt-real',
                    'summary' => 'Real meeting',
                    'start' => ['dateTime' => '2026-05-06T10:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T10:30:00+01:00'],
                ],
            ],
        ]),
    ]);

    $events = (new CalendarService)->getEventsForDate($user, Carbon::parse('2026-05-06'));

    expect($events)->toHaveCount(1);
    expect($events[0]['title'])->toBe('Real meeting');
});

test('falls back to primary calendar if calendarList endpoint fails', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response('boom', 500),
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [[
                'id' => 'evt-1',
                'summary' => 'Fallback event',
                'start' => ['dateTime' => '2026-05-06T09:00:00+01:00'],
                'end' => ['dateTime' => '2026-05-06T09:30:00+01:00'],
            ]],
        ]),
    ]);

    $events = (new CalendarService)->getEventsForDate($user, Carbon::parse('2026-05-06'));

    expect($events)->toHaveCount(1);
    expect($events[0]['title'])->toBe('Fallback event');
});
