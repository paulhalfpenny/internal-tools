<?php

use App\Domain\TimeTracking\HoursFormatter;

test('format() dispatches to decimal or hhmm based on the given format', function () {
    expect(HoursFormatter::format(1.5, HoursFormatter::FORMAT_DECIMAL))->toBe('1.5')
        ->and(HoursFormatter::format(1.5, HoursFormatter::FORMAT_HHMM))->toBe('1:30')
        ->and(HoursFormatter::format(0.25, HoursFormatter::FORMAT_HHMM))->toBe('0:15');
});
