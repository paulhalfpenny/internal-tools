<?php

use App\Domain\TimeTracking\HoursFormatter;

test('format() dispatches to decimal or hhmm based on the given format', function () {
    expect(HoursFormatter::format(1.5, HoursFormatter::FORMAT_DECIMAL))->toBe('1.5')
        ->and(HoursFormatter::format(1.5, HoursFormatter::FORMAT_HHMM))->toBe('1:30')
        ->and(HoursFormatter::format(0.25, HoursFormatter::FORMAT_HHMM))->toBe('0:15');
});

test('asReportDecimal always renders decimal hours with two places', function () {
    expect(HoursFormatter::asReportDecimal(0.0))->toBe('0.00')
        ->and(HoursFormatter::asReportDecimal(1.3))->toBe('1.30')
        ->and(HoursFormatter::asReportDecimal(40.45))->toBe('40.45')
        ->and(HoursFormatter::asReportDecimal(57 + 20 / 60))->toBe('57.33');
});
