<?php

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Model;

test('decimal cast normalises float inputs before brick math receives them', function () {
    $model = new class extends Model
    {
        protected function casts(): array
        {
            return ['amount' => Decimal::class.':2'];
        }
    };

    $deprecations = [];
    $previousErrorHandler = set_error_handler(
        function (int $level, string $message, string $file, int $line) use (&$deprecations, &$previousErrorHandler): bool {
            if ($level === E_USER_DEPRECATED && str_contains($message, 'Passing floats to BigNumber::of()')) {
                $deprecations[] = $message;

                return true;
            }

            return $previousErrorHandler ? ($previousErrorHandler)($level, $message, $file, $line) : false;
        },
    );

    try {
        $model->amount = 1.235;
        $value = $model->amount;
        $attributes = $model->getAttributes();
        $serialized = $model->toArray();
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBeEmpty()
        ->and($value)->toBe('1.24')
        ->and($attributes['amount'])->toBe('1.24')
        ->and($serialized['amount'])->toBe('1.24');
});

test('decimal cast supports configured scales and null values', function () {
    $model = new class extends Model
    {
        protected function casts(): array
        {
            return ['amount' => Decimal::class.':3'];
        }
    };

    $model->amount = '12.3456';

    expect($model->amount)->toBe('12.346');

    $model->amount = null;

    expect($model->amount)->toBeNull();
});
