<?php

namespace App\Domain\TimeTracking;

use InvalidArgumentException;

final class HoursParser
{
    /**
     * Parse a human-entered hours string into a float.
     *
     * Accepted formats:
     *   1.5      → 1.5
     *   1:30     → 1.5
     *   90m      → 1.5
     *   0:15     → 0.25
     *
     * @throws InvalidArgumentException on unrecognised input
     */
    public static function parse(string $input): float
    {
        $input = trim($input);

        // hh:mm
        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $input, $m)) {
            $hours = (int) $m[1] + (int) $m[2] / 60;

            return self::validate(round($hours, 2));
        }

        // Nm  (e.g. 90m)
        if (preg_match('/^(\d+)m$/i', $input, $m)) {
            return self::validate(round((int) $m[1] / 60, 2));
        }

        // decimal / integer
        if (preg_match('/^\d+(\.\d+)?$/', $input)) {
            return self::validate((float) $input);
        }

        throw new InvalidArgumentException("Cannot parse hours from: \"{$input}\"");
    }

    private static function validate(float $hours): float
    {
        if ($hours <= 0 || $hours > 24) {
            throw new InvalidArgumentException("Hours must be between 0.01 and 24, got {$hours}");
        }

        return $hours;
    }
}
