<?php

use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('aggregates events only from calendars the user owns and de-duplicates across them', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok-123',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        // calendarList is filtered by minAccessRole=owner server-side — so a
        // realistic fake here returns only the user-owned calendars (primary +
        // a "Focus" personal one). Shared/team calendars wouldn't be returned
        // by Google when minAccessRole=owner is requested.
        'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [
                ['id' => 'primary'],
                ['id' => 'focus@personal'],
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
        // Personal calendar returns two events, one of which (evt-A) is also on primary
        'https://www.googleapis.com/calendar/v3/calendars/focus*' => Http::response([
            'items' => [
                [
                    'id' => 'evt-A', // duplicate — should be deduped
                    'summary' => 'Personal call',
                    'start' => ['dateTime' => '2026-05-06T09:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T09:30:00+01:00'],
                ],
                [
                    'id' => 'evt-B',
                    'summary' => 'Focus block',
                    'start' => ['dateTime' => '2026-05-06T10:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T10:30:00+01:00'],
                ],
            ],
        ]),
    ]);

    $events = (new CalendarService)->getEventsForDate($user, Carbon::parse('2026-05-06'));

    expect($events)->toHaveCount(2);
    $titles = collect($events)->pluck('title')->all();
    expect($titles)->toContain('Personal call', 'Focus block');

    // Critical: we asked Google for owner-only calendars, not reader.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/users/me/calendarList')
        && $r->data()['minAccessRole'] === 'owner');
});

test('shared/team calendars the user only reads are skipped', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok-123',
        'google_token_expires_at' => now()->addHour(),
    ]);

    // Simulate what Google would actually return when asked with
    // minAccessRole=owner: only the calendars the user owns. The shared
    // "team@filter.agency" calendar (where the user is a reader) is excluded
    // by the API itself, so it must never appear in the merged event list.
    Http::fake([
        'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [['id' => 'primary']],
        ]),
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [[
                'id' => 'evt-mine',
                'summary' => 'My meeting',
                'start' => ['dateTime' => '2026-05-06T09:00:00+01:00'],
                'end' => ['dateTime' => '2026-05-06T09:30:00+01:00'],
            ]],
        ]),
        // Defensive: if the service ever queried the team calendar, this fake
        // would supply a "someone else's meeting" — and the assertion below
        // would catch it.
        'https://www.googleapis.com/calendar/v3/calendars/team*' => Http::response([
            'items' => [[
                'id' => 'evt-someone-else',
                'summary' => "Someone else's meeting",
                'start' => ['dateTime' => '2026-05-06T11:00:00+01:00'],
                'end' => ['dateTime' => '2026-05-06T11:30:00+01:00'],
            ]],
        ]),
    ]);

    $events = (new CalendarService)->getEventsForDate($user, Carbon::parse('2026-05-06'));

    expect($events)->toHaveCount(1);
    expect($events[0]['title'])->toBe('My meeting');
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
