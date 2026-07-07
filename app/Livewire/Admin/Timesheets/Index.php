<?php

namespace App\Livewire\Admin\Timesheets;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    private const SORTABLE_FIELDS = ['name', 'week_hours', 'last_entry'];

    public string $weekStart;

    public string $sortField = 'name';

    public string $sortDirection = 'asc';

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

    public function sortByHoursThisWeek(): void
    {
        $this->sortBy('week_hours');
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORTABLE_FIELDS, true)) {
            throw new InvalidArgumentException("Unsupported timesheet sort field [{$field}].");
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
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

        $rows = $users->map(function (User $u) use ($weekTotals, $lastEntry): object {
            $lastEntryDate = $lastEntry[$u->id]->last_spent_on ?? null;

            return (object) [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'week_hours' => (float) ($weekTotals[$u->id]->hours ?? 0),
                'last_entry' => is_string($lastEntryDate) ? $lastEntryDate : null,
            ];
        });

        $rows = $this->sortRows($rows);

        return view('livewire.admin.timesheets.index', [
            'rows' => $rows,
            'weekStartDate' => $weekStart,
            'weekEndDate' => $weekEnd,
            'isCurrentWeek' => $isCurrentWeek,
        ]);
    }

    /**
     * @param  Collection<int, \stdClass&object{id:int,name:string,email:string,week_hours:float,last_entry:string|null}>  $rows
     * @return Collection<int, \stdClass&object{id:int,name:string,email:string,week_hours:float,last_entry:string|null}>
     */
    private function sortRows(Collection $rows): Collection
    {
        return $rows
            ->sort(function (object $a, object $b): int {
                $comparison = match ($this->sortField) {
                    'name' => strcasecmp($a->name, $b->name),
                    'week_hours' => $a->week_hours <=> $b->week_hours,
                    'last_entry' => $this->compareLastEntry($a->last_entry, $b->last_entry),
                    default => throw new InvalidArgumentException("Unsupported timesheet sort field [{$this->sortField}]."),
                };

                if ($comparison !== 0) {
                    return $this->sortDirection === 'desc' ? -$comparison : $comparison;
                }

                $nameComparison = strcasecmp($a->name, $b->name);

                return $nameComparison !== 0 ? $nameComparison : strcasecmp($a->email, $b->email);
            })
            ->values();
    }

    private function compareLastEntry(?string $a, ?string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        return strcmp($a, $b);
    }
}
