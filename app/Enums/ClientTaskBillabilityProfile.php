<?php

namespace App\Enums;

enum ClientTaskBillabilityProfile: string
{
    case Agency = 'agency';
    case Jdw = 'jdw';

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Agency',
            self::Jdw => 'JDW',
        };
    }
}
