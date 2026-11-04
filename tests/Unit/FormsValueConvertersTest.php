<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms value converters (#98).
 *
 * `FieldtypeMappingTrait` converts mapped values on request: dates to ISO 8601
 * (`as_date_iso_8601`, used by the Gravity Wiz Limit Dates exceptions), numbers
 * to numeric strings (`as_numeric_string`), sizes between bytes and larger
 * units, and labels to slugs, snake case and field handles. No standalone test
 * named `convertDateStringToIso8601()`, `transformToIso8601Date()`,
 * `transformToNumericString()`, `convertToBytes()`, `convertFromBytes()`,
 * `sluggifyValue()`, `snakeCaseValue()` or `convertFieldNameToHandle()` before
 * this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsValueConvertersTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsConverterMethod(
    string $method,
    mixed ...$arguments
): mixed {
    return new ReflectionMethod(
        FieldtypeMapping::class,
        $method
    )->invoke(
        null,
        ...$arguments
    );
}

/**
 * Map a Gravity Forms date field carrying Limit Dates exceptions.
 *
 * @param  list<string>  $exceptions  The exception dates as Gravity Forms stores them
 * @return mixed The mapped `date_exceptions`, or `'absent'`
 */
function mapFormsDateExceptions(
    array $exceptions
): mixed {
    $field = [
        'id' => 5,
        'type' => 'date',
        'label' => 'Visit Day',
        'gpLimitDatesexceptions' => $exceptions,
    ];

    return FieldtypeMapping::mapFieldAttributes($field, [$field], 'date')['date_exceptions'] ?? 'absent';
}

it('maps Limit Dates exceptions to ISO 8601 dates', function (): void {
    expect(mapFormsDateExceptions(['01/02/2023', '12/25/2023']))->toBe([
        ['id' => 'visit_day_5_date_exceptions_1', 'item' => '2023-01-02', 'type' => 'single_date', 'enabled' => true],
        ['id' => 'visit_day_5_date_exceptions_2', 'item' => '2023-12-25', 'type' => 'single_date', 'enabled' => true],
    ])->and(mapFormsDateExceptions([]))->toBe('absent');
});

it('converts a date string to ISO 8601', function (
    string $date,
    string $expected
): void {
    expect(invokeFormsConverterMethod('convertDateStringToIso8601', $date))->toBe($expected);
})->with([
    'month/day/year' => ['1/2/2024', '2024-01-02'],
    'month/day/year, padded' => ['12/25/2024', '2024-12-25'],
    'year/month/day' => ['2024/1/2', '2024-01-02'],
    'month-day-year' => ['01-02-2024', '2024-01-02'],
    'year-month-day' => ['2024-1-2', '2024-01-02'],
    'surrounding spaces' => [' 2024-01-02 ', '2024-01-02'],
    'escaped slashes, year last' => ['1\/2\/2024', '2024-01-02'],
    'escaped slashes, year first' => ['2024\/1\/2', '2024-01-02'],
    'a day-first date, read month first' => ['25/12/2024', '2024-25-12'],
    'words' => ['Jan 2 2024', 'Jan 2 2024'],
    'a two-digit year' => ['1/2/24', '1/2/24'],
    'a trimmed unknown format' => [' soon ', 'soon'],
    'nothing' => ['', ''],
]);

it('converts each date in a value to ISO 8601', function (): void {
    expect(invokeFormsConverterMethod('transformToIso8601Date', ['1/2/2024', 5, null]))->toBe(['2024-01-02', '5', ''])
        ->and(invokeFormsConverterMethod('transformToIso8601Date', '2024/3/4'))->toBe('2024-03-04')
        ->and(invokeFormsConverterMethod('transformToIso8601Date', null))->toBeNull()
        ->and(invokeFormsConverterMethod('transformToIso8601Date', 5))->toBe(5);
});

it('turns numbers into numeric strings and leaves other values alone', function (): void {
    expect(invokeFormsConverterMethod('transformToNumericString', [1, '2', 3.5, true, null, [4]]))
        ->toBe(['1', '2', '3.5', true, null, ['4']])
        ->and(invokeFormsConverterMethod('transformToNumericString', 1.0))->toBe('1')
        ->and(invokeFormsConverterMethod('transformToNumericString', null))->toBeNull()
        ->and(invokeFormsConverterMethod('transformToNumericString', false))->toBeFalse();
});

it('converts sizes to and from bytes in decimal units', function (): void {
    expect(invokeFormsConverterMethod('convertToBytes', 1.5, 'KB'))->toBe(1500)
        ->and(invokeFormsConverterMethod('convertToBytes', 2.0, 'MB'))->toBe(2000000)
        ->and(invokeFormsConverterMethod('convertToBytes', 1.0, 'GB'))->toBe(1000000000)
        ->and(invokeFormsConverterMethod('convertToBytes', 1.0, 'TB'))->toBe(1000000000000)
        ->and(invokeFormsConverterMethod('convertToBytes', 3.0, 'B'))->toBe(3)
        ->and(invokeFormsConverterMethod('convertToBytes', 3.0, 'XB'))->toBe(3)
        ->and(invokeFormsConverterMethod('convertFromBytes', 1500, 'KB'))->toBe(1.5)
        ->and(invokeFormsConverterMethod('convertFromBytes', 1000, 'KB'))->toBe(1)
        ->and(invokeFormsConverterMethod('convertFromBytes', 2500000, 'MB'))->toBe(2.5)
        ->and(invokeFormsConverterMethod('convertFromBytes', 3000000000, 'GB'))->toBe(3)
        ->and(invokeFormsConverterMethod('convertFromBytes', 1000000000000, 'TB'))->toBe(1)
        ->and(invokeFormsConverterMethod('convertFromBytes', 0, 'MB'))->toBe(0)
        ->and(invokeFormsConverterMethod('convertFromBytes', 5, 'XB'))->toBe(5);
});

it('turns a value into a slug and into snake case', function (
    mixed $value,
    string $slug,
    string $snake
): void {
    expect(invokeFormsConverterMethod('sluggifyValue', $value))->toBe($slug)
        ->and(invokeFormsConverterMethod('snakeCaseValue', $value))->toBe($snake);
})->with([
    'words and punctuation' => ['Hello World!', 'hello-world', 'hello_world'],
    'leading and trailing separators' => ['--A--b--', 'a-b', 'a_b'],
    'a number' => [12, '12', '12'],
    'an accented letter' => ['Café', 'caf', 'caf'],
    'null' => [null, '', ''],
]);

it('turns a field label into a handle ending in the field id', function (
    string $label,
    string $expected
): void {
    expect(invokeFormsConverterMethod('convertFieldNameToHandle', $label, '3'))->toBe($expected);
})->with([
    'spaces and capitals' => [' First Name ', 'first_name_3'],
    'runs of separators' => ['a__b--c', 'a_b_c_3'],
    'leading and trailing underscores' => ['__x__', 'x_3'],
    'an accented letter' => ['Café', 'caf_3'],
]);
