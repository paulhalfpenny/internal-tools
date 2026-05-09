<?php

namespace App\Domain\TimeTracking;

final class HoursFormatter
{
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
}
