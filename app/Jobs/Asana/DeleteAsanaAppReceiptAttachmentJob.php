<?php

namespace App\Jobs\Asana;

use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes the "receipt" attachment the Asana app form creates on submit.
 *
 * Asana's on_submit contract forces a 200 response to attach a resource, and
 * ANY attachment created via the form claims the app's slot in the task's
 * Apps row — replacing the "Log time" entry point and blocking repeat
 * logging. So we satisfy the contract, then delete the attachment moments
 * later with the acting user's OAuth token. If this fails the attachment
 * simply lingers (the user can remove it by hand), so failures are logged
 * quietly rather than retried aggressively.
 */
class DeleteAsanaAppReceiptAttachmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $asanaTaskGid,
    ) {}

    public function handle(AsanaService $service): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        try {
            $api = $service->forUser($user);
            $marker = 'from_asana='.$this->asanaTaskGid;

            foreach ($api->getTaskAttachments($this->asanaTaskGid) as $attachment) {
                if ($attachment['view_url'] !== null && str_contains($attachment['view_url'], $marker)) {
                    $api->deleteAttachment($attachment['gid']);
                }
            }
        } catch (Throwable $e) {
            // A lingering receipt is cosmetic (removable by hand) — log and
            // move on rather than fail the queue.
            Log::warning('Could not remove Asana receipt attachment', [
                'task_gid' => $this->asanaTaskGid,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
