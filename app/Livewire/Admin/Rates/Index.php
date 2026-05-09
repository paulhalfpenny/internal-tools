<?php

namespace App\Livewire\Admin\Rates;

use App\Domain\Billing\RateResolver;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        $resolver = new RateResolver;

        $projects = Project::with(['tasks', 'users', 'client'])
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        $users = User::where('is_active', true)->orderBy('name')->get();

        $rates = [];
        foreach ($projects as $project) {
            foreach ($users as $user) {
                // Only show if user is assigned to this project
                if (! $project->users->contains($user->id)) {
                    continue;
                }

                $task = $project->tasks->first();
                if ($task === null) {
                    continue;
                }

                $resolution = $resolver->resolve($project, $task, $user);
                $rates[] = [
                    'project' => $project->name,
                    'client' => $project->client->name,
                    'user' => $user->name,
                    'effective_rate' => $resolution->rateSnapshot,
                    'source' => $this->rateSource($project, $user),
                ];
            }
        }

        return view('livewire.admin.rates.index', ['rates' => $rates]);
    }

    private function rateSource(Project $project, User $user): string
    {
        $assignedUser = $project->users->firstWhere('id', $user->id);
        if ($assignedUser !== null) {
            /** @var Pivot $pivot */
            $pivot = $assignedUser->getRelation('pivot');
            if ($pivot->getAttribute('rate_id') !== null) {
                return 'project-user library rate';
            }
            if ($pivot->getAttribute('hourly_rate_override') !== null) {
                return 'project-user override';
            }
        }

        if ($project->rate_id !== null) {
            return 'project library rate';
        }

        if ($project->default_hourly_rate !== null) {
            return 'project default';
        }

        if ($user->rate_id !== null) {
            return 'user library rate';
        }

        if ($user->default_hourly_rate !== null) {
            return 'user default';
        }

        return 'none';
    }
}
