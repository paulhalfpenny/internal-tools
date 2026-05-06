<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Slack\SlackClient;
use Illuminate\Console\Command;

class SyncSlackUserIds extends Command
{
    protected $signature = 'slack:sync-user-ids {--force : Re-resolve users that already have a Slack ID}';

    protected $description = 'Resolve Slack user IDs for active users via users.lookupByEmail.';

    public function handle(SlackClient $slack): int
    {
        if (! $slack->isConfigured()) {
            $this->warn('SLACK_BOT_USER_OAUTH_TOKEN is not set; skipping.');

            return self::SUCCESS;
        }

        $query = User::query()->where('is_active', true);
        if (! $this->option('force')) {
            $query->whereNull('slack_user_id');
        }

        $users = $query->get();
        $resolved = 0;
        $missing = 0;

        foreach ($users as $user) {
            if ($this->option('force')) {
                // Skip the cache check inside resolveUserId() without losing the existing id on lookup failure.
                $stash = $user->slack_user_id;
                $user->slack_user_id = null;
                $id = $slack->resolveUserId($user);
                if ($id === null && $stash !== null) {
                    $user->forceFill(['slack_user_id' => $stash])->save();
                }
            } else {
                $id = $slack->resolveUserId($user);
            }

            if ($id !== null) {
                $resolved++;
                $this->line("  ✓ {$user->email} → {$id}");
            } else {
                $missing++;
                $this->line("  · {$user->email} (no Slack account or lookup failed)");
            }
        }

        $this->info("Resolved {$resolved} of {$users->count()}; {$missing} could not be resolved.");

        return self::SUCCESS;
    }
}
