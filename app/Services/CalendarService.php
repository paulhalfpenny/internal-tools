<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CalendarService
{
    /**
     * @return array<int, array{id: string, title: string, start_formatted: string, end_formatted: string, hours: float}>
     */
    public function getTodayEvents(User $user): array
    {
        return $this->getEventsForDate($user, Carbon::today());
    }

    /**
     * Fetch the user's timed events for a specific date from their primary
     * calendar.
     *
     * @return array<int, array{id: string, title: string, start_formatted: string, end_formatted: string, hours: float}>
     */
    public function getEventsForDate(User $user, Carbon $date): array
    {
        $byDate = $this->getEventsForDateRange($user, $date->copy(), $date->copy());

        return $byDate[$date->toDateString()] ?? [];
    }

    /**
     * Fetch events grouped by yyyy-mm-dd for a date range (inclusive on both
     * ends). Only queries the user's primary calendar — events from
     * subscribed or shared calendars (even ones Google reports as owner-role
     * because of "manage sharing" permission) would leak other people's time
     * into the user's timesheet.
     *
     * @return array<string, array<int, array{id: string, title: string, start_formatted: string, end_formatted: string, hours: float}>>
     */
    public function getEventsForDateRange(User $user, Carbon $from, Carbon $to): array
    {
        $token = $this->getValidToken($user);
        if ($token === null) {
            return [];
        }

        $timeMin = $from->copy()->startOfDay()->toIso8601String();
        $timeMax = $to->copy()->endOfDay()->toIso8601String();

        try {
            $response = Http::withToken($token)
                ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                    'timeMin' => $timeMin,
                    'timeMax' => $timeMax,
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'maxResults' => 250,
                ]);
        } catch (ConnectionException|Throwable $e) {
            Log::warning('calendar.fetch_events.failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $items = [];
        foreach ($response->json('items', []) as $item) {
            if (! isset($item['start']['dateTime'], $item['end']['dateTime'])) {
                continue;
            }
            if (($item['status'] ?? null) === 'cancelled') {
                continue;
            }
            if ($this->userDeclined($item)) {
                continue;
            }
            $items[] = $item;
        }

        $grouped = [];
        foreach ($items as $item) {
            $start = Carbon::parse($item['start']['dateTime']);
            $end = Carbon::parse($item['end']['dateTime']);
            $dateKey = $start->toDateString();
            $grouped[$dateKey][] = [
                'id' => $item['id'],
                'title' => $item['summary'] ?? 'Untitled event',
                'start_formatted' => $start->format('H:i'),
                'end_formatted' => $end->format('H:i'),
                'hours' => round($start->diffInMinutes($end) / 60, 2),
                'start_ts' => $start->timestamp,
            ];
        }

        // Sort each day's events chronologically and strip the helper field.
        foreach ($grouped as $dateKey => $events) {
            usort($events, fn ($a, $b) => $a['start_ts'] <=> $b['start_ts']);
            $grouped[$dateKey] = array_map(function (array $event): array {
                unset($event['start_ts']);

                return $event;
            }, $events);
        }

        return $grouped;
    }

    /**
     * Google sets `self: true` on the attendee entry that represents the
     * authenticated user. If that entry's responseStatus is "declined", the
     * user actively said no to the meeting — skip it so it doesn't clutter
     * their time-tracking sidebar.
     *
     * Events without an attendees array (e.g. solo blocks the user created
     * for themselves) are kept; there's no decline to read.
     *
     * @param  array<string, mixed>  $item
     */
    private function userDeclined(array $item): bool
    {
        $attendees = $item['attendees'] ?? null;
        if (! is_array($attendees)) {
            return false;
        }

        foreach ($attendees as $attendee) {
            if (! is_array($attendee)) {
                continue;
            }
            if (($attendee['self'] ?? false) === true) {
                return ($attendee['responseStatus'] ?? null) === 'declined';
            }
        }

        return false;
    }

    public function hasToken(User $user): bool
    {
        return $user->google_access_token !== null;
    }

    private function getValidToken(User $user): ?string
    {
        if ($user->google_access_token === null) {
            return null;
        }

        if ($user->google_token_expires_at !== null && $user->google_token_expires_at->isPast()) {
            return $this->refreshToken($user);
        }

        return $user->google_access_token;
    }

    private function refreshToken(User $user): ?string
    {
        if ($user->google_refresh_token === null) {
            return null;
        }

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $user->google_refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException|Throwable $e) {
            Log::warning('calendar.token_refresh.failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $newToken = $data['access_token'] ?? null;
        if ($newToken === null) {
            return null;
        }

        $user->forceFill([
            'google_access_token' => $newToken,
            'google_token_expires_at' => now()->addSeconds(max(0, ($data['expires_in'] ?? 3600) - 60)),
        ])->save();

        return $newToken;
    }
}
