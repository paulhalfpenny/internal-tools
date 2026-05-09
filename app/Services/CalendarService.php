<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

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
        $token = $this->getValidToken($user);
        if ($token === null) {
            return [];
        }

        $calendarIds = $this->fetchCalendarIds($token);
        if ($calendarIds === []) {
            return [];
        }

        $timeMin = $date->copy()->startOfDay()->toIso8601String();
        $timeMax = $date->copy()->endOfDay()->toIso8601String();

        // Collect events from every calendar; key by id to drop duplicates
        // (the same event invitation appears on each invitee's primary calendar).
        $byId = [];
        foreach ($calendarIds as $calendarId) {
            $response = Http::withToken($token)
                ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', [
                    'timeMin' => $timeMin,
                    'timeMax' => $timeMax,
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'maxResults' => 50,
                ]);

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

        return collect(array_values($byId))
            ->map(function (array $item): array {
                $start = Carbon::parse($item['start']['dateTime']);
                $end = Carbon::parse($item['end']['dateTime']);
                $hours = round($start->diffInMinutes($end) / 60, 2);

                return [
                    'id' => $item['id'],
                    'title' => $item['summary'] ?? 'Untitled event',
                    'start_formatted' => $start->format('H:i'),
                    'end_formatted' => $end->format('H:i'),
                    'hours' => $hours,
                    'start_ts' => $start->timestamp,
                ];
            })
            ->sortBy('start_ts')
            ->map(function (array $event): array {
                unset($event['start_ts']);

                return $event;
            })
            ->values()
            ->toArray();
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

        $ids = collect($response->json('items', []))
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

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $user->google_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $newToken = $data['access_token'] ?? null;
        if ($newToken === null) {
            return null;
        }

        $user->update([
            'google_access_token' => $newToken,
            'google_token_expires_at' => now()->addSeconds(max(0, ($data['expires_in'] ?? 3600) - 60)),
        ]);

        return $newToken;
    }
}
