<?php

use App\Domain\TimeTracking\HoursFormatter;
use App\Domain\TimeTracking\HoursParser;

test('formats decimal hours as h:mm', function () {
    expect(HoursFormatter::asTime(0.0))->toBe('0:00')
        ->and(HoursFormatter::asTime(0.25))->toBe('0:15')
        ->and(HoursFormatter::asTime(0.5))->toBe('0:30')
        ->and(HoursFormatter::asTime(1.0))->toBe('1:00')
        ->and(HoursFormatter::asTime(1.5))->toBe('1:30')
        ->and(HoursFormatter::asTime(8.75))->toBe('8:45');
});

test('formats decimal hours with a minimum single decimal place', function () {
    expect(HoursFormatter::asDecimal(0.0))->toBe('0.0')
        ->and(HoursFormatter::asDecimal(0.25))->toBe('0.25')
        ->and(HoursFormatter::asDecimal(0.5))->toBe('0.5')
        ->and(HoursFormatter::asDecimal(1.0))->toBe('1.0')
        ->and(HoursFormatter::asDecimal(1.5))->toBe('1.5')
        ->and(HoursFormatter::asDecimal(8.75))->toBe('8.75');
});

test('handles 60-minute rollover', function () {
    expect(HoursFormatter::asTime(0.999))->toBe('1:00')
        ->and(HoursFormatter::asTime(1.999))->toBe('2:00');
});

test('round-trips with HoursParser for typical entries', function () {
    $cases = ['0:15', '0:30', '1:00', '1:45', '8:00'];

    foreach ($cases as $input) {
        $hours = HoursParser::parse($input);
        expect(HoursFormatter::asTime($hours))->toBe($input);
    }
});

test('format() dispatches to decimal or hhmm based on the given format', function () {
    expect(HoursFormatter::format(1.5, HoursFormatter::FORMAT_DECIMAL))->toBe('1.5')
        ->and(HoursFormatter::format(1.5, HoursFormatter::FORMAT_HHMM))->toBe('1:30')
        ->and(HoursFormatter::format(0.25, HoursFormatter::FORMAT_HHMM))->toBe('0:15');
});

test('format() falls back to decimal for an unrecognised format', function () {
    expect(HoursFormatter::format(1.5, 'nonsense'))->toBe('1.5');
});
