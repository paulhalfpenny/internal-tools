<?php

namespace App\Console\Commands;

use App\Models\AsanaSyncLog;
use App\Models\User;
use App\Notifications\AsanaConnectionLost;
use App\Services\Asana\AsanaTokenManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CheckAsanaConnections extends Command
{
    protected $signature = 'asana:check-connections {--dry-run : Print what would be sent without notifying}';

    protected $description = 'Find users whose Asana connection has dropped and prompt them to reconnect.';

    public function handle(AsanaTokenManager $tokens): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $broken = $this->brokenConnections($tokens);

        if ($broken->isEmpty()) {
            $this->info('All Asana connections are healthy.');

            return self::SUCCESS;
        }

        $notified = 0;
        foreach ($broken as $user) {
            if ($user->asana_connection_alerted_at !== null) {
                $this->line("Skipping {$user->email} — already alerted.");

                continue;
            }

            if ($dryRun) {
                $this->warn("Would notify {$user->email} (lost at ".($user->asana_connection_lost_at ?? 'unknown').').');

                continue;
            }

            $user->notify(new AsanaConnectionLost($user->asana_connection_lost_at));
            $user->forceFill(['asana_connection_alerted_at' => now()])->save();

            AsanaSyncLog::warn('asana.connection.lost', [
                'user_id' => $user->id,
                'lost_at' => $user->asana_connection_lost_at?->toIso8601String(),
            ], $user);

            $this->warn("Notified {$user->email} to reconnect Asana.");
            $notified++;
        }

        $this->info(sprintf('%d broken connection(s), %d notified.', $broken->count(), $notified));

        return self::SUCCESS;
    }

    /**
     * A connection counts as broken when the user meant to be connected but no usable
     * token can be produced right now.
     *
     * Two shapes are covered:
     *  - already flagged by AsanaTokenManager when a refresh failed (tokens since cleared);
     *  - still holding an access token that cannot be resolved, e.g. expired with no
     *    refresh token, which no background job would otherwise surface.
     *
     * @return Collection<int, User>
     */
    private function brokenConnections(AsanaTokenManager $tokens): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNotNull('asana_connection_lost_at')
                ->orWhereNotNull('asana_access_token'))
            ->get()
            ->filter(function (User $user) use ($tokens): bool {
                if ($user->asana_connection_lost_at !== null) {
                    return true;
                }

                // Probing resolves (and may refresh) the token; a null result means the
                // stored connection is unusable even though the user still looks connected.
                return $tokens->getValidToken($user) === null;
            })
            ->values();
    }
}
