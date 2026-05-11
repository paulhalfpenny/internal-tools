<?php

namespace App\Livewire\Admin\Timesheets;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $weekStart;

    public function mount(): void
    {
        Gate::authorize('access-admin');

        $this->weekStart = CarbonImmutable::now()->startOfWeek()->toDateString();
    }

    public function previousWeek(): void
    {
        $this->weekStart = CarbonImmutable::parse($this->weekStart)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->weekStart = CarbonImmutable::parse($this->weekStart)->addWeek()->toDateString();
    }

    public function thisWeek(): void
    {
        $this->weekStart = CarbonImmutable::now()->startOfWeek()->toDateString();
    }

    public function render(): View
    {
        $weekStart = CarbonImmutable::parse($this->weekStart)->startOfWeek();
        $weekEnd = $weekStart->addDays(6);
        $isCurrentWeek = $weekStart->equalTo(CarbonImmutable::now()->startOfWeek());

        $users = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        /** @var Collection<int, \stdClass> $weekTotals */
        $weekTotals = TimeEntry::query()
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, SUM(hours) as hours')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $lastEntry = TimeEntry::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, MAX(spent_on) as last_spent_on')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $rows = $users->map(fn (User $u) => (object) [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'week_hours' => (float) ($weekTotals[$u->id]->hours ?? 0),
            'last_entry' => $lastEntry[$u->id]->last_spent_on ?? null,
        ]);

        return view('livewire.admin.timesheets.index', [
            'rows' => $rows,
            'weekStartDate' => $weekStart,
            'weekEndDate' => $weekEnd,
            'isCurrentWeek' => $isCurrentWeek,
        ]);
    }
}
