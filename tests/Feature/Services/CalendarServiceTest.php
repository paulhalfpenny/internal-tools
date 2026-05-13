<?php

use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('returns timed events from the user primary calendar', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok-123',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [
                [
                    'id' => 'evt-A',
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
    expect(collect($events)->pluck('title')->all())->toContain('Personal call', 'Focus block');
});

test('never queries any calendar other than primary, even for subscribed calendars', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok-123',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [[
                'id' => 'evt-mine',
                'summary' => 'My meeting',
                'start' => ['dateTime' => '2026-05-06T09:00:00+01:00'],
                'end' => ['dateTime' => '2026-05-06T09:30:00+01:00'],
            ]],
        ]),
        // Defensive: if the service ever queried someone else's calendar, this
        // fake would supply a leak — and the assertions below would catch it.
        'https://www.googleapis.com/calendar/v3/calendars/*' => Http::response([
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

    // The calendarList endpoint must not be hit, and no non-primary calendar
    // should ever be queried.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/users/me/calendarList'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/calendars/')
        && ! str_contains($r->url(), '/calendars/primary/'));
});

test('skips cancelled events and all-day events', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
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

test('returns no events if the primary calendar endpoint fails', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response('boom', 500),
    ]);

    $events = (new CalendarService)->getEventsForDate($user, Carbon::parse('2026-05-06'));

    expect($events)->toBe([]);
});
