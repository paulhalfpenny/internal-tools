<?php

namespace App\Enums;

enum BudgetType: string
{
    case FixedFee = 'fixed_fee';
    case MonthlyCi = 'monthly_ci';

    public function label(): string
    {
        return match ($this) {
            self::FixedFee => 'Fixed-fee',
            self::MonthlyCi => 'CI Retainer',
        };
    }
}
