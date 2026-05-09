<?php

namespace App\Livewire\Admin\Rates;

use App\Models\Rate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Library extends Component
{
    public bool $showArchived = false;

    public string $name = '';

    public string $hourlyRate = '';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editHourlyRate = '';

    public function create(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:rates,name',
            'hourlyRate' => 'required|numeric|min:0',
        ]);

        Rate::create([
            'name' => $this->name,
            'hourly_rate' => (float) $this->hourlyRate,
        ]);

        $this->name = '';
        $this->hourlyRate = '';
    }

    public function edit(int $rateId): void
    {
        $rate = Rate::findOrFail($rateId);
        $this->editingId = $rateId;
        $this->editName = $rate->name;
        $this->editHourlyRate = (string) $rate->hourly_rate;
    }

    public function save(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255|unique:rates,name,'.$this->editingId,
            'editHourlyRate' => 'required|numeric|min:0',
        ]);

        Rate::findOrFail((int) $this->editingId)->update([
            'name' => $this->editName,
            'hourly_rate' => (float) $this->editHourlyRate,
        ]);

        $this->editingId = null;
    }

    public function cancel(): void
    {
        $this->editingId = null;
    }

    public function toggleArchive(int $rateId): void
    {
        $rate = Rate::findOrFail($rateId);
        $rate->update(['is_archived' => ! $rate->is_archived]);
    }

    public function render(): View
    {
        $query = Rate::orderBy('name');
        if (! $this->showArchived) {
            $query->where('is_archived', false);
        }

        return view('livewire.admin.rates.library', [
            'rates' => $query->get(),
        ]);
    }
}
