<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms complex-array transform and its helpers (#98).
 *
 * `FieldtypeMappingTrait::transformToComplexArray()` turns an attribute value
 * into the structured items a Statamic field expects, filling `[#value]`,
 * `[#count]` and `[#field_handle]` placeholders in a configured template. The
 * Gravity Forms `gpLimitDatesexceptions` mapping uses it to build date
 * exceptions. This file also covers `getNestedAttributeValue()`, which
 * resolves the dot paths `generate_value` reads, and
 * `applyValueSubstitutionRegex()`. No standalone test reached any of them
 * before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsComplexArrayTransformTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeGravityFormsMappingMethod(
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
 * The complex-array template the tests transform values into.
 *
 * @return array<string, mixed> The template
 */
function complexArrayTemplate(): array
{
    return [
        'id' => '[#field_handle]_x_[#count]',
        'item' => '[#value]',
        '[#value]' => 'keyed by value',
        'nested' => ['deep' => '[#count]-[#value]'],
        'enabled' => true,
    ];
}

/**
 * Transform a value through the complex-array template.
 *
 * @param  mixed  $value  The value to transform
 * @param  int  $count  The current count
 * @return mixed The transformed value
 */
function transformToComplexArrayTemplate(
    mixed $value,
    int $count = 4
): mixed {
    return invokeGravityFormsMappingMethod(
        'transformToComplexArray',
        $value,
        complexArrayTemplate(),
        ['id' => 5, 'label' => 'Due Date'],
        $count
    );
}

it('builds Gravity Forms date exceptions as ISO 8601 items numbered from one', function (): void {
    $field = [
        'id' => 5,
        'type' => 'date',
        'label' => 'Due Date',
        'gpLimitDatesexceptions' => ['1/2/2024', '12-25-2023'],
    ];

    $mapped = FieldtypeMapping::mapFieldAttributes($field, [$field], 'date');

    expect($mapped['date_exceptions'] ?? 'absent')->toBe([
        ['id' => 'due_date_5_date_exceptions_1', 'item' => '2024-01-02', 'type' => 'single_date', 'enabled' => true],
        ['id' => 'due_date_5_date_exceptions_2', 'item' => '2023-12-25', 'type' => 'single_date', 'enabled' => true],
    ]);
});

it('maps no date exceptions when there are none', function (): void {
    $field = [
        'id' => 5,
        'type' => 'date',
        'label' => 'Due Date',
        'gpLimitDatesexceptions' => [],
    ];

    expect(FieldtypeMapping::mapFieldAttributes($field, [$field], 'date'))
        ->not->toHaveKey('date_exceptions');
});

it('fills the template once for a string, in keys, values and nested arrays', function (): void {
    expect(transformToComplexArrayTemplate('a'))->toBe([
        'id' => 'due_date_5_x_4',
        'item' => 'a',
        'a' => 'keyed by value',
        'nested' => ['deep' => '4-a'],
        'enabled' => true,
    ]);
});

it('fills the template once for a number, as a string', function (): void {
    expect(transformToComplexArrayTemplate(7))->toBe([
        'id' => 'due_date_5_x_4',
        'item' => '7',
        7 => 'keyed by value',
        'nested' => ['deep' => '4-7'],
        'enabled' => true,
    ]);
});

it('fills the template per item for an array, counting from one', function (): void {
    expect(transformToComplexArrayTemplate(['p', 'q'], 9))->toBe([
        [
            'id' => 'due_date_5_x_1',
            'item' => 'p',
            'p' => 'keyed by value',
            'nested' => ['deep' => '1-p'],
            'enabled' => true,
        ],
        [
            'id' => 'due_date_5_x_2',
            'item' => 'q',
            'q' => 'keyed by value',
            'nested' => ['deep' => '2-q'],
            'enabled' => true,
        ],
    ]);
});

it('counts a string-keyed array by position instead of failing on the key', function (): void {
    $items = transformToComplexArrayTemplate(['first' => 'p', 'second' => 'q']);

    expect(\is_array($items) ? \array_column($items, 'id') : $items)
        ->toBe(['due_date_5_x_1', 'due_date_5_x_2']);
});

it('returns no items for a null or empty value', function (mixed $value): void {
    expect(transformToComplexArrayTemplate($value))->toBe([]);
})->with([
    'null' => [null],
    'an empty string' => [''],
]);

it('resolves a nested attribute by its dot path', function (
    string $path,
    mixed $expected
): void {
    $data = [
        'choice' => ['text' => ['label' => 'Foo']],
        'flat' => 'bar',
    ];

    expect(invokeGravityFormsMappingMethod('getNestedAttributeValue', $data, $path))
        ->toBe($expected);
})->with([
    'a top-level attribute' => ['flat', 'bar'],
    'one level down' => ['choice.text', ['label' => 'Foo']],
    'two levels down' => ['choice.text.label', 'Foo'],
    'a missing nested key' => ['choice.missing', null],
    'a path through a scalar' => ['flat.deeper', null],
    'a missing top-level attribute' => ['missing', null],
]);

it('applies each substitution pattern in turn, with either back-reference form', function (): void {
    $patterns = [
        '/^(\d+)$/' => '$1MB',
        '/^(\d+)MB$/' => '\1 megabytes',
    ];

    expect(invokeGravityFormsMappingMethod('applyValueSubstitutionRegex', '25', $patterns))
        ->toBe('25 megabytes')
        ->and(invokeGravityFormsMappingMethod('applyValueSubstitutionRegex', 'abc', $patterns))
        ->toBe('abc');
});

it('skips a substitution whose pattern or replacement is not a string', function (): void {
    $patterns = [
        0 => 'ignored',
        '/a/' => ['not', 'a', 'string'],
        '/b/' => 'B',
    ];

    expect(invokeGravityFormsMappingMethod('applyValueSubstitutionRegex', 'ab', $patterns))
        ->toBe('aB');
});
