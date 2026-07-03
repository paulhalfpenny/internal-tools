<?php

namespace App\Domain\TimeTracking;

final class HoursFormatter
{
    public const FORMAT_DECIMAL = 'decimal';

    public const FORMAT_HHMM = 'hhmm';

    /**
     * Format decimal hours as `h:mm` (e.g. 0.25 → "0:15", 1.5 → "1:30").
     * 60-minute rollover is handled (e.g. 0.999 → "1:00").
     */
    public static function asTime(float $hours): string
    {
        $totalMinutes = (int) round($hours * 60);
        $h = intdiv($totalMinutes, 60);
        $m = $totalMinutes % 60;

        return $h.':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format decimal hours with at least one decimal place.
     */
    public static function asDecimal(float $hours): string
    {
        $formatted = rtrim(number_format($hours, 2, '.', ''), '0');

        if (str_ends_with($formatted, '.')) {
            $formatted .= '0';
        }

        return $formatted;
    }

    /**
     * Format decimal hours using the given display format (FORMAT_DECIMAL or
     * FORMAT_HHMM), falling back to decimal for anything unrecognised.
     */
    public static function format(float $hours, string $format): string
    {
        return $format === self::FORMAT_HHMM
            ? self::asTime($hours)
            : self::asDecimal($hours);
    }
}
