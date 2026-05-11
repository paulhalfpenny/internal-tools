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
     * Fetch the user's timed events for a specific date, across every calendar
     * they have read access to (not just `primary`). De-duplicates events that
     * appear on multiple calendars (e.g. invitations).
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
     * ends). Hits Google's API once per accessible calendar across the whole
     * range — much cheaper than calling getEventsForDate() per day.
     *
     * @return array<string, array<int, array{id: string, title: string, start_formatted: string, end_formatted: string, hours: float}>>
     */
    public function getEventsForDateRange(User $user, Carbon $from, Carbon $to): array
    {
        $token = $this->getValidToken($user);
        if ($token === null) {
            return [];
        }

        $calendarIds = $this->fetchCalendarIds($token);
        if ($calendarIds === []) {
            return [];
        }

        $timeMin = $from->copy()->startOfDay()->toIso8601String();
        $timeMax = $to->copy()->endOfDay()->toIso8601String();

        // Pull from every calendar once across the whole range; key by event
        // id to drop duplicates (the same invitation appears on every
        // invitee's primary calendar).
        $byId = [];
        foreach ($calendarIds as $calendarId) {
            try {
                $response = Http::withToken($token)
                    ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', [
                        'timeMin' => $timeMin,
                        'timeMax' => $timeMax,
                        'singleEvents' => 'true',
                        'orderBy' => 'startTime',
                        'maxResults' => 250,
                    ]);
            } catch (ConnectionException|Throwable $e) {
                Log::warning('calendar.fetch_events.failed', [
                    'calendar_id' => $calendarId,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            foreach ($response->json('items', []) as $item) {
                if (! isset($item['start']['dateTime'], $item['end']['dateTime'])) {
                    continue;
                }
                if (($item['status'] ?? null) === 'cancelled') {
                    continue;
                }

                $byId[$item['id']] = $item;
            }
        }

        // Group by date and shape into the lightweight array structure the UI uses.
        $grouped = [];
        foreach ($byId as $item) {
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
     * List every calendar the authenticated user has access to.
     *
     * @return array<int, string>
     */
    private function fetchCalendarIds(string $token): array
    {
        $response = Http::withToken($token)
            ->get('https://www.googleapis.com/calendar/v3/users/me/calendarList', [
                'minAccessRole' => 'reader',
                'showHidden' => 'true',
                'maxResults' => 250,
            ]);

        if (! $response->successful()) {
            // Fall back to primary so the feature degrades gracefully rather than
            // returning nothing if the calendarList endpoint hiccups.
            return ['primary'];
        }

        /** @var array<int, array{id?: string, deleted?: bool, hidden?: bool}> $items */
        $items = $response->json('items', []);
        $ids = collect($items)
            ->filter(fn (array $cal) => ! ($cal['deleted'] ?? false) && ! ($cal['hidden'] ?? false))
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        return $ids === [] ? ['primary'] : $ids;
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
