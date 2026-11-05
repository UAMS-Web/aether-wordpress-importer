<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms value-translation preparation (#98).
 *
 * Before a value is translated, `injectEmptyValueMapping()` adds `#empty => null`
 * to each associative translation table that lacks one, so an empty value maps
 * to nothing rather than through the table, and leaves search/replace lists and
 * `based_on_other_attribute_value` tables alone. `checkForArrayReplacement()`
 * then replaces a whole array value when a search/replace entry searches for
 * exactly that array, which is how Gravity Wiz Limit Dates drops a days-of-week
 * list that allows every day. No standalone test named either method before this
 * file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsEmptyValueAndArrayReplacementTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsTranslationPrepMethod(
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
 * Map a date field's Limit Dates days of the week.
 *
 * @param  mixed  $days  The `gpLimitDatesdaysOfWeek` value
 * @return mixed The mapped `days_of_week`, or `'absent'` when none was set
 */
function mapDaysOfWeek(
    mixed $days
): mixed {
    $field = [
        'id' => 4,
        'type' => 'date',
        'label' => 'Visit Day',
        'gpLimitDatesdaysOfWeek' => $days,
    ];

    return FieldtypeMapping::mapFieldAttributes($field, [$field], 'date')['days_of_week'] ?? 'absent';
}

it('drops a days-of-week list that allows every day, and keeps any other', function (): void {
    expect(mapDaysOfWeek([0, 1, 2, 3, 4, 5, 6]))->toBe('absent')
        ->and(mapDaysOfWeek(['0', '1', '2', '3', '4', '5', '6']))->toBe('absent')
        ->and(mapDaysOfWeek([1, 2, 3, 4, 5]))->toBe(['1', '2', '3', '4', '5'])
        ->and(mapDaysOfWeek([6, 5, 4, 3, 2, 1, 0]))->toBe(['6', '5', '4', '3', '2', '1', '0']);
});

it('replaces a whole array only when an entry searches for exactly that array', function (
    mixed $value,
    array $translationConfig,
    mixed $expected
): void {
    expect(invokeFormsTranslationPrepMethod('checkForArrayReplacement', $value, $translationConfig))->toBe($expected);
})->with([
    'a default entry' => [[1, 2], ['default' => [['search' => [1, 2], 'replace' => 'both']]], 'both'],
    'a default entry replacing with null' => [[1, 2], ['default' => [['search' => [1, 2], 'replace' => null]]], null],
    'a later default entry' => [[1, 2], ['default' => [['search' => [3], 'replace' => 'x'], ['search' => [1, 2], 'replace' => 'both']]], 'both'],
    'a list of entries' => [['a'], [['search' => ['a'], 'replace' => 'A']], 'A'],
    'a specific entry' => [['a'], ['specific' => ['date' => [['search' => ['a'], 'replace' => 'A']]]], 'A'],
    'a default and a specific entry' => [['a'], ['default' => [], 'specific' => ['date' => [['search' => ['a'], 'replace' => 'A']]]], 'A'],
    'the same items in another order' => [[2, 1], ['default' => [['search' => [1, 2], 'replace' => 'both']]], false],
    'the same items as strings' => [['1', '2'], ['default' => [['search' => [1, 2], 'replace' => 'both']]], false],
    'an entry with no replace' => [[1, 2], ['default' => [['search' => [1, 2]]]], false],
    'an entry searching for a string' => [['a'], [['search' => 'a', 'replace' => 'A']], false],
    'an entry that is not an array' => [['a'], ['x', ['search' => ['b'], 'replace' => 'B']], false],
    'a value that is not an array' => ['a', [['search' => 'a', 'replace' => 'A']], false],
    'no entries' => [['a'], [], false],
]);

it('adds #empty => null to each associative translation table', function (
    array $translationConfig,
    array $expected
): void {
    expect(invokeFormsTranslationPrepMethod('injectEmptyValueMapping', $translationConfig))->toBe($expected);
})->with([
    'a plain table' => [['a' => 'A'], ['a' => 'A', '#empty' => null]],
    'an empty plain table' => [[], ['#empty' => null]],
    'a plain table with its own #empty' => [['#empty' => 'none', 'a' => 'A'], ['#empty' => 'none', 'a' => 'A']],
    'a plain search/replace list' => [[['search' => 'a', 'replace' => 'A']], [['search' => 'a', 'replace' => 'A']]],
    'a default table' => [['default' => ['a' => 'A']], ['default' => ['a' => 'A', '#empty' => null]]],
    'a default table with its own #empty' => [['default' => ['#empty' => 'x']], ['default' => ['#empty' => 'x']]],
    'a default search/replace list' => [['default' => [['search' => 'a', 'replace' => 'A']]], ['default' => [['search' => 'a', 'replace' => 'A']]]],
    'a default that is not an array' => [['default' => 'a'], ['default' => ['#empty' => null]]],
    'specific tables' => [
        ['specific' => ['date' => ['a' => 'A'], 'time' => [['search' => 'a', 'replace' => 'A']], 'name' => ['#empty' => 'x'], 'text' => []]],
        ['specific' => ['date' => ['a' => 'A', '#empty' => null], 'time' => [['search' => 'a', 'replace' => 'A']], 'name' => ['#empty' => 'x'], 'text' => []]],
    ],
    'a default and specific tables' => [
        ['default' => ['a' => 'A'], 'specific' => ['date' => ['b' => 'B']]],
        ['default' => ['a' => 'A', '#empty' => null], 'specific' => ['date' => ['b' => 'B', '#empty' => null]]],
    ],
    'a based_on_other_attribute_value table' => [
        ['based_on_other_attribute_value' => ['x' => ['a' => 'A']]],
        ['based_on_other_attribute_value' => ['x' => ['a' => 'A']]],
    ],
]);
