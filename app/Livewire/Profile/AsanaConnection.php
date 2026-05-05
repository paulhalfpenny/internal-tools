<?php

namespace App\Livewire\Profile;

use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Models\AsanaWorkspace;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AsanaConnection extends Component
{
    public string $selectedWorkspace = '';

    public function mount(): void
    {
        $this->selectedWorkspace = (string) ($this->authUser()->asana_workspace_gid ?? '');
    }

    public function setWorkspace(string $gid): void
    {
        $user = $this->authUser();
        if (! $user->asanaConnected()) {
            return;
        }

        $user->forceFill(['asana_workspace_gid' => $gid])->save();
        $this->selectedWorkspace = $gid;

        PullAsanaProjectsJob::dispatch($gid, $user->id);

        session()->flash('asana_status', 'Workspace updated. Pulling projects in the background.');
    }

    public function render(): View
    {
        $user = $this->authUser();

        return view('livewire.profile.asana-connection', [
            'connected' => $user->asanaConnected(),
            'workspaces' => $user->asanaConnected()
                ? AsanaWorkspace::orderBy('name')->get()
                : collect(),
        ]);
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
