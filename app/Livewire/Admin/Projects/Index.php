<?php

namespace App\Livewire\Admin\Projects;

use App\Enums\BudgetType;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public bool $showArchived = false;

    public int|string $clientId = '';

    public string $code = '';

    public string $name = '';

    public bool $isBillable = true;

    public string $budgetType = '';

    public string $budgetAmount = '';

    public string $budgetHours = '';

    public string $budgetStartsOn = '';

    public function save(): void
    {
        Gate::authorize('access-admin');

        $this->validate([
            'clientId' => 'required|exists:clients,id',
            'code' => 'required|string|max:50|unique:projects,code',
            'name' => 'required|string|max:255',
            'isBillable' => 'boolean',
            'budgetType' => 'nullable|in:fixed_fee,monthly_ci',
            'budgetAmount' => 'nullable|numeric|min:0|required_with:budgetType',
            'budgetHours' => 'nullable|numeric|min:0',
            'budgetStartsOn' => 'nullable|date|required_if:budgetType,monthly_ci',
        ]);

        $project = Project::create([
            'client_id' => (int) $this->clientId,
            'code' => $this->code,
            'name' => $this->name,
            'is_billable' => $this->isBillable,
            'budget_type' => $this->budgetType !== '' ? BudgetType::from($this->budgetType) : null,
            'budget_amount' => $this->budgetType !== '' && $this->budgetAmount !== '' ? (float) $this->budgetAmount : null,
            'budget_hours' => $this->budgetType !== '' && $this->budgetHours !== '' ? (float) $this->budgetHours : null,
            'budget_starts_on' => $this->budgetType === 'monthly_ci' && $this->budgetStartsOn !== '' ? $this->budgetStartsOn : null,
        ]);

        // Pre-attach the client's default tasks, if any have been set up for this client.
        // Pivot is_billable mirrors the task's global default; the resolver reads
        // task.is_default_billable directly, but the pivot column is non-null.
        $defaults = Client::with('defaultTasks')->find($project->client_id)?->defaultTasks ?? collect();
        foreach ($defaults as $task) {
            $project->tasks()->attach($task->id, [
                'is_billable' => (bool) $task->is_default_billable,
                'hourly_rate_override' => null,
            ]);
        }

        $this->redirect(route('admin.projects.edit', $project));
    }

    public function toggleArchive(int $projectId): void
    {
        Gate::authorize('access-admin');

        $project = Project::findOrFail($projectId);
        $project->update(['is_archived' => ! $project->is_archived]);
    }

    public function duplicate(int $projectId): void
    {
        Gate::authorize('access-admin');

        $original = Project::with(['tasks', 'users'])->findOrFail($projectId);

        $newCode = $this->uniqueProjectCode($original->code.'-COPY');

        $copy = DB::transaction(function () use ($original, $newCode) {
            $copy = Project::create([
                'client_id' => $original->client_id,
                'manager_user_id' => $original->manager_user_id,
                'code' => $newCode,
                'name' => $original->name.' (copy)',
                'is_billable' => $original->is_billable,
                'budget_type' => $original->budget_type,
                'budget_amount' => $original->budget_amount,
                'budget_hours' => $original->budget_hours,
                'budget_starts_on' => $original->budget_starts_on,
                'starts_on' => $original->starts_on,
                'ends_on' => $original->ends_on,
                'is_archived' => false,
            ]);

            foreach ($original->tasks as $task) {
                $copy->tasks()->attach($task->id, [
                    'is_billable' => (bool) $task->pivot->is_billable,
                    'hourly_rate_override' => $task->pivot->hourly_rate_override,
                ]);
            }

            foreach ($original->users as $user) {
                $copy->users()->attach($user->id, [
                    'hourly_rate_override' => $user->pivot->hourly_rate_override,
                ]);
            }

            return $copy;
        });

        $this->redirect(route('admin.projects.edit', $copy));
    }

    private function uniqueProjectCode(string $base): string
    {
        $code = $base;
        $i = 2;
        while (Project::where('code', $code)->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }

    public function render(): View
    {
        $query = Project::with('client')->orderBy('name');

        if (! $this->showArchived) {
            $query->where('is_archived', false);
        }

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        return view('livewire.admin.projects.index', [
            'projects' => $query->get(),
            'clients' => Client::where('is_archived', false)->orderBy('name')->get(),
            'budgetTypes' => BudgetType::cases(),
        ]);
    }
}
