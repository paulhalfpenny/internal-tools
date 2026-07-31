<?php

namespace App\Casts;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException as BrickMathException;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Exceptions\MathException;

/**
 * @implements CastsAttributes<string, int|float|string>
 */
final class Decimal implements CastsAttributes
{
    public function __construct(private readonly int $scale = 2) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->format($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->format($value);
    }

    private function format(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            // brick/math 0.14 deprecates float input and 0.15 will reject it.
            // PHP's float-to-string conversion preserves the previous behaviour.
            return (string) BigDecimal::of((string) $value)
                ->toScale($this->scale, RoundingMode::HALF_UP);
        } catch (BrickMathException $exception) {
            throw new MathException('Unable to cast value to a decimal.', previous: $exception);
        }
    }
}
