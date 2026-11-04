<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms value transformations (#98).
 *
 * `FieldtypeMappingTrait` converts a form export's attribute values while
 * mapping them: `applyValueTransformation()` handles dollar amounts, nulling,
 * generated handles, ISO 8601 dates and numeric strings, and
 * `mapAttributesFromMapping()` handles the `convert_file_size` and
 * `check_against_list` keys. No standalone test reached any of them before
 * this file. The two mapping keys are driven through the public
 * `mapFieldAttributes()` with the Gravity Forms attributes that use them
 * (`maxFileSize` and `visibility`); the rest call the protected methods on the
 * Gravity Forms mapping class, which uses the trait.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsValueTransformationsTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsMappingMethod(
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
 * Apply a value transformation configuration to a value.
 *
 * @param  mixed  $value  The value to transform
 * @param  array<int|string, mixed>  $config  The transformation configuration
 * @param  ?array<int|string, mixed>  $data  The field data
 * @param  ?int  $count  The current count
 * @return mixed The transformed value
 */
function applyFormsValueTransformation(
    mixed $value,
    array $config,
    ?array $data = null,
    ?int $count = null
): mixed {
    return invokeFormsMappingMethod(
        'applyValueTransformation',
        $value,
        $config,
        $data,
        $count
    );
}

/**
 * Map a Gravity Forms field's attributes through the real mapping class.
 *
 * @param  array<string, mixed>  $field  The field data
 * @return array<int|string, mixed> The mapped attributes
 */
function mapGravityFormsField(
    array $field
): array {
    return FieldtypeMapping::mapFieldAttributes(
        $field,
        [$field],
        \is_string($field['type']) ? $field['type'] : null
    );
}

it('converts a Gravity Forms maximum file size to megabytes', function (
    string $maxFileSize,
    string $expected
): void {
    $mapped = mapGravityFormsField([
        'id' => 3,
        'type' => 'fileupload',
        'label' => 'Upload',
        'maxFileSize' => $maxFileSize,
    ]);

    expect($mapped['max_file_size'] ?? 'absent')->toBe($expected);
})->with([
    'a bare number' => ['2', '2'],
    'kilobytes' => ['512kb', '0.512'],
    'fractional megabytes' => ['1.5mb', '1.5'],
    'a space before the unit' => ['10 MB', '10'],
    'no recognizable size' => ['abc', '0'],
]);

it('converts a file size between units', function (
    string $fileSize,
    string $targetUnit,
    string $expected
): void {
    expect(invokeFormsMappingMethod('convertFileSize', $fileSize, $targetUnit))
        ->toBe($expected);
})->with([
    'gigabytes to megabytes' => ['1.5GB', 'MB', '1500'],
    'megabytes to lowercase kilobytes' => ['800 MB', 'kb', '800000'],
    'lowercase terabytes to gigabytes' => ['2tb', 'GB', '2000'],
    'kilobytes to bytes' => ['1.5KB', 'B', '1500'],
    'bytes to kilobytes' => ['1B', 'KB', '0.001'],
    'whole bytes only' => ['1.0005KB', 'B', '1000'],
    'an unknown target unit stays in bytes' => ['1KB', 'PB', '1000'],
    'an unknown source unit' => ['5XB', 'MB', '0'],
]);

it('keeps a Gravity Forms visibility only when it is in the validation list', function (
    string $visibility,
    string $expected
): void {
    $mapped = mapGravityFormsField([
        'id' => 4,
        'type' => 'text',
        'label' => 'Name',
        'visibility' => $visibility,
    ]);

    expect($mapped['visibility'] ?? 'absent')->toBe($expected);
})->with([
    'administrative, translated to hidden' => ['administrative', 'hidden'],
    'hidden' => ['hidden', 'hidden'],
    'visible, which the list does not hold' => ['visible', 'absent'],
]);

it('validates a value against a named list', function (
    string $value,
    string $list,
    bool $expected
): void {
    expect(FieldtypeMapping::validateValueAgainstList($value, $list))
        ->toBe($expected);
})->with([
    'a listed value' => ['hidden', 'visibility_values', true],
    'a value differing only in case' => ['Hidden', 'visibility_values', false],
    'an unlisted value' => ['visible', 'visibility_values', false],
    'a list that does not exist' => ['hidden', 'no_such_list', false],
]);

it('converts a dollar amount to a number', function (): void {
    expect(applyFormsValueTransformation('$1,234.50', ['convert_dollar_amount' => true]))
        ->toBe(1234.5)
        ->and(applyFormsValueTransformation('$1,234.50', ['convert_dollar_amount' => false]))
        ->toBe('$1,234.50')
        ->and(applyFormsValueTransformation(12, ['convert_dollar_amount' => true]))
        ->toBe(12);
});

it('sets a value to null only when asked', function (): void {
    expect(applyFormsValueTransformation('kept', ['set_to_null' => true]))
        ->toBeNull()
        ->and(applyFormsValueTransformation('kept', ['set_to_null' => false]))
        ->toBe('kept');
});

it('generates a value from the field handle, the count, literals and attributes', function (): void {
    $field = [
        'id' => 7,
        'label' => '--Pick One, Café!--',
        'choice' => ['text' => 'Foo-Bar  baz_'],
    ];
    $config = [
        'generate_value' => [
            ['value' => '#field_handle'],
            ['value' => '#count'],
            ['value' => 'x'],
            ['attribute_value_sluggified' => 'label'],
            ['attribute_value_snake_case' => 'choice.text'],
            'not a rule',
            ['two' => 1, 'keys' => 2],
            ['unknown_rule' => 'z'],
        ],
    ];

    expect(applyFormsValueTransformation(null, $config, $field, 3))
        ->toBe('pick_one_caf_7_3_x_pick-one-caf_foo_bar_baz');
});

it('generates a value with no field data, counting from one', function (): void {
    $config = [
        'generate_value' => [
            ['value' => '#count'],
            ['attribute_value_sluggified' => 'missing'],
            ['attribute_value_snake_case' => 'missing'],
        ],
    ];

    expect(applyFormsValueTransformation('replaced', $config))->toBe('1__');
});

it('converts a date string to ISO 8601', function (
    string $date,
    string $expected
): void {
    expect(applyFormsValueTransformation($date, ['as_date_iso_8601' => true]))
        ->toBe($expected);
})->with([
    'month/day/year' => ['1/2/2024', '2024-01-02'],
    'year/month/day' => ['2024/3/4', '2024-03-04'],
    'month-day-year' => ['12-25-2023', '2023-12-25'],
    'year-month-day' => ['2024-3-4', '2024-03-04'],
    'month/day/year with escaped slashes' => ['1\/2\/2024', '2024-01-02'],
    'year/month/day with escaped slashes' => ['2024\/1\/2', '2024-01-02'],
    'surrounding whitespace' => [' 1/2/2024 ', '2024-01-02'],
    'only whitespace' => ['   ', ''],
    'a two-digit year' => ['1/2/24', '1/2/24'],
    'words' => ['next tuesday', 'next tuesday'],
]);

it('converts each date in an array and leaves other values alone', function (): void {
    $config = ['as_date_iso_8601' => true];

    expect(applyFormsValueTransformation(['1/2/2024', 5, null], $config))
        ->toBe(['2024-01-02', '5', ''])
        ->and(applyFormsValueTransformation(20240102, $config))
        ->toBe(20240102)
        ->and(applyFormsValueTransformation(null, $config))
        ->toBeNull();
});

it('converts numbers to numeric strings, recursively', function (): void {
    $config = ['as_numeric_string' => true];

    expect(applyFormsValueTransformation([1, '2', 3.5, true, null, [4]], $config))
        ->toBe(['1', '2', '3.5', true, null, ['4']])
        ->and(applyFormsValueTransformation(1, $config))
        ->toBe('1')
        ->and(applyFormsValueTransformation(1.5, $config))
        ->toBe('1.5')
        ->and(applyFormsValueTransformation(null, $config))
        ->toBeNull()
        ->and(applyFormsValueTransformation(1, ['as_numeric_string' => false]))
        ->toBe(1);
});

it('applies the dollar conversion before the numeric string', function (): void {
    expect(applyFormsValueTransformation('$1,234.50', [
        'convert_dollar_amount' => true,
        'as_numeric_string' => true,
    ]))->toBe('1234.5');
});

it('leaves a date alone unless the date transformation is on', function (): void {
    expect(applyFormsValueTransformation('1/2/2024', ['as_date_iso_8601' => false]))
        ->toBe('1/2/2024')
        ->and(applyFormsValueTransformation('1/2/2024', []))
        ->toBe('1/2/2024');
});
