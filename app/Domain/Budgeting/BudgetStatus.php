<?php

namespace App\Domain\Budgeting;

use App\Enums\BudgetType;

final readonly class BudgetStatus
{
    public function __construct(
        public BudgetType $budgetType,
        public float $budgetAmount,
        public ?float $budgetHours,
        public float $actualAmount,
        public float $actualHours,
    ) {}

    public function variance(): float
    {
        return round($this->budgetAmount - $this->actualAmount, 2);
    }

    public function percentUsed(): float
    {
        if ($this->budgetAmount <= 0) {
            return 0.0;
        }

        return round($this->actualAmount / $this->budgetAmount * 100, 1);
    }

    public function isOver(): bool
    {
        return $this->actualAmount > $this->budgetAmount;
    }
}
