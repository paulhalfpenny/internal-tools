<?php

use App\Domain\TimeTracking\HoursParser;

test('parses decimal hours', function () {
    expect(HoursParser::parse('1.5'))->toBe(1.5)
        ->and(HoursParser::parse('0.25'))->toBe(0.25)
        ->and(HoursParser::parse('8'))->toBe(8.0)
        ->and(HoursParser::parse('0.01'))->toBe(0.01);
});

test('parses hh:mm format', function () {
    expect(HoursParser::parse('1:30'))->toBe(1.5)
        ->and(HoursParser::parse('0:15'))->toBe(0.25)
        ->and(HoursParser::parse('8:00'))->toBe(8.0)
        ->and(HoursParser::parse('0:01'))->toBe(round(1 / 60, 2));
});

test('parses Nm minutes format', function () {
    expect(HoursParser::parse('90m'))->toBe(1.5)
        ->and(HoursParser::parse('30m'))->toBe(0.5)
        ->and(HoursParser::parse('15m'))->toBe(0.25)
        ->and(HoursParser::parse('60m'))->toBe(1.0);
});

test('trims whitespace', function () {
    expect(HoursParser::parse(' 1.5 '))->toBe(1.5);
});

test('throws on unrecognised format', function () {
    HoursParser::parse('abc');
})->throws(InvalidArgumentException::class);

test('throws on zero hours', function () {
    HoursParser::parse('0');
})->throws(InvalidArgumentException::class);

test('throws on hours exceeding 24', function () {
    HoursParser::parse('24.01');
})->throws(InvalidArgumentException::class);

test('accepts exactly 24 hours', function () {
    expect(HoursParser::parse('24'))->toBe(24.0);
});
