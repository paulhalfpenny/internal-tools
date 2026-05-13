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

test('skips events the user has declined', function () {
    $user = User::factory()->create([
        'google_access_token' => 'tok',
        'google_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [
                [
                    'id' => 'evt-declined',
                    'summary' => 'Meeting I declined',
                    'start' => ['dateTime' => '2026-05-06T09:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T09:30:00+01:00'],
                    'attendees' => [
                        ['email' => 'organiser@filter.agency', 'responseStatus' => 'accepted'],
                        ['self' => true, 'email' => 'me@filter.agency', 'responseStatus' => 'declined'],
                    ],
                ],
                [
                    'id' => 'evt-accepted',
                    'summary' => 'Meeting I accepted',
                    'start' => ['dateTime' => '2026-05-06T10:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T10:30:00+01:00'],
                    'attendees' => [
                        ['self' => true, 'email' => 'me@filter.agency', 'responseStatus' => 'accepted'],
                    ],
                ],
                [
                    'id' => 'evt-tentative',
                    'summary' => 'Meeting I might attend',
                    'start' => ['dateTime' => '2026-05-06T11:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T11:30:00+01:00'],
                    'attendees' => [
                        ['self' => true, 'email' => 'me@filter.agency', 'responseStatus' => 'tentative'],
                    ],
                ],
                [
                    'id' => 'evt-not-responded',
                    'summary' => 'Invite I have not actioned',
                    'start' => ['dateTime' => '2026-05-06T12:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T12:30:00+01:00'],
                    'attendees' => [
                        ['self' => true, 'email' => 'me@filter.agency', 'responseStatus' => 'needsAction'],
                    ],
                ],
                [
                    'id' => 'evt-solo',
                    'summary' => 'Solo focus block (no attendees array)',
                    'start' => ['dateTime' => '2026-05-06T13:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T13:30:00+01:00'],
                ],
                [
                    'id' => 'evt-someone-else-declined',
                    'summary' => 'Meeting where someone else declined',
                    'start' => ['dateTime' => '2026-05-06T14:00:00+01:00'],
                    'end' => ['dateTime' => '2026-05-06T14:30:00+01:00'],
                    'attendees' => [
                        ['email' => 'colleague@filter.agency', 'responseStatus' => 'declined'],
                        ['self' => true, 'email' => 'me@filter.agency', 'responseStatus' => 'accepted'],
                    ],
                ],
            ],
        ]),
    ]);

    $events = (new CalendarService)->getEventsForDate($user, Carbon::parse('2026-05-06'));

    $titles = collect($events)->pluck('title')->all();
    expect($titles)->not->toContain('Meeting I declined');
    expect($titles)->toContain(
        'Meeting I accepted',
        'Meeting I might attend',
        'Invite I have not actioned',
        'Solo focus block (no attendees array)',
        'Meeting where someone else declined',
    );
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
