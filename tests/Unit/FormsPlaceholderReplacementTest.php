<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms placeholder replacement (#98).
 *
 * `FieldtypeMappingTrait` fills mapping templates from the field: `[#value]`,
 * `[#count]` and `[#field_handle]` in a complex array (`as_complex_array`), `*`,
 * `[#preset]`, `[#subfield]` and `[#site_handle]` in an attribute name, and
 * values built from rules (`generate_value`). The imported value is inserted
 * last, so text in it that looks like a placeholder is kept as written. No
 * standalone test named `replaceComplexArrayPlaceholder()`,
 * `replaceComplexArrayPlaceholders()`, `generateValue()`,
 * `replacePlaceholders()`, `applySiteHandlePlaceholderToMappedStructure()`,
 * `getPresetValueForPlaceholder()` or `getStatamicPresetValue()` before this
 * file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsPlaceholderReplacementTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsPlaceholderMethod(
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
 * The field data the placeholder tests read a handle from.
 *
 * @return array<string, int|string> The field data
 */
function placeholderField(): array
{
    return ['id' => 7, 'label' => 'Pick One'];
}

it('keeps an imported value that looks like a placeholder as written', function (): void {
    $field = [
        'id' => 5,
        'type' => 'date',
        'label' => 'Visit Day',
        'gpLimitDatesexceptions' => ['[#count]', '01/02/2023'],
    ];

    expect(FieldtypeMapping::mapFieldAttributes($field, [$field], 'date')['date_exceptions'] ?? 'absent')->toBe([
        ['id' => 'visit_day_5_date_exceptions_1', 'item' => '[#count]', 'type' => 'single_date', 'enabled' => true],
        ['id' => 'visit_day_5_date_exceptions_2', 'item' => '2023-01-02', 'type' => 'single_date', 'enabled' => true],
    ]);
});

it('fills a complex array template from the value, count and field', function (): void {
    expect(invokeFormsPlaceholderMethod('replaceComplexArrayPlaceholder', '[#field_handle]-[#count]-[#value]', 'v', placeholderField(), 3))
        ->toBe('pick_one_7-3-v')
        ->and(invokeFormsPlaceholderMethod('replaceComplexArrayPlaceholder', 'x [#value]', '[#count] [#field_handle]', placeholderField(), 3))
        ->toBe('x [#count] [#field_handle]')
        ->and(invokeFormsPlaceholderMethod('replaceComplexArrayPlaceholder', 'plain', 'v', placeholderField(), 3))
        ->toBe('plain')
        ->and(invokeFormsPlaceholderMethod('replaceComplexArrayPlaceholder', 5, 'v', placeholderField(), 3))
        ->toBe(5)
        ->and(invokeFormsPlaceholderMethod('replaceComplexArrayPlaceholders', ['[#value]_k' => ['id' => '[#count]', 'n' => true]], 'v', placeholderField(), 2))
        ->toBe(['v_k' => ['id' => '2', 'n' => true]]);
});

it('builds a generated value from its rules', function (): void {
    $rules = [
        ['value' => '#field_handle'],
        ['value' => '#count'],
        ['value' => 'lit'],
        ['attribute_value_sluggified' => 'label'],
        ['attribute_value_snake_case' => 'label'],
        'not a rule',
        ['two' => 1, 'keys' => 2],
        ['unknown' => 'x'],
    ];

    expect(invokeFormsPlaceholderMethod('generateValue', $rules, placeholderField(), 4))
        ->toBe('pick_one_7_4_lit_pick-one_pick_one')
        ->and(invokeFormsPlaceholderMethod('generateValue', [], placeholderField(), 1))
        ->toBe('');
});

it('replaces the placeholders in an attribute name', function (): void {
    expect(invokeFormsPlaceholderMethod('replacePlaceholders', 'a_*_[#preset]_[#subfield]_[#site_handle]', 'H', 'sub_[#preset]', 'us', 'site'))
        ->toBe('a_H_us_sub_us_site')
        ->and(invokeFormsPlaceholderMethod('replacePlaceholders', '[#subfield]', null, 'sub_[#preset]'))
        ->toBe('sub_[#preset]')
        ->and(invokeFormsPlaceholderMethod('replacePlaceholders', 'a_*_[#preset]_[#subfield]_[#site_handle]'))
        ->toBe('a_*_[#preset]_[#subfield]_[#site_handle]');
});

it('replaces the site handle throughout a mapped structure', function (): void {
    expect(invokeFormsPlaceholderMethod('applySiteHandlePlaceholderToMappedStructure', ['k_[#site_handle]' => ['v_[#site_handle]', 3, null], 0 => '[#site_handle]'], 'en'))
        ->toBe(['k_en' => ['v_en', 3, null], 0 => 'en'])
        ->and(invokeFormsPlaceholderMethod('applySiteHandlePlaceholderToMappedStructure', 5, 'en'))
        ->toBe(5);
});

it('reads the preset for placeholders, preferring placeholder_translation', function (
    array $field,
    ?string $expected
): void {
    $mapping = [
        'other' => ['statamic_attribute' => 'text'],
        'addressType' => [
            'statamic_attribute' => ['default' => 'preset'],
            'placeholder_translation' => ['us' => 'american'],
            'value_translation' => ['us' => 'from_value_translation'],
        ],
    ];

    expect(invokeFormsPlaceholderMethod('getPresetValueForPlaceholder', $field, $mapping))->toBe($expected);
})->with([
    'a translated value' => [['addressType' => 'us'], 'american'],
    'an untranslated value' => [['addressType' => 'canadian'], 'canadian'],
    'no value' => [[], null],
    'a value that is not a string' => [['addressType' => ['x']], null],
]);

it('reads the preset from a plain preset attribute, through value_translation', function (): void {
    $translated = ['t' => ['statamic_attribute' => 'preset', 'value_translation' => ['a' => 'A']]];
    $plain = ['t' => ['statamic_attribute' => 'preset']];

    foreach (['getStatamicPresetValue', 'getPresetValueForPlaceholder'] as $method) {
        expect(invokeFormsPlaceholderMethod($method, ['t' => 'a'], $translated))->toBe('A')
            ->and(invokeFormsPlaceholderMethod($method, ['t' => 'b'], $translated))->toBe('b')
            ->and(invokeFormsPlaceholderMethod($method, [], $translated))->toBeNull()
            ->and(invokeFormsPlaceholderMethod($method, ['t' => 'raw'], $plain))->toBe('raw')
            ->and(invokeFormsPlaceholderMethod($method, ['t' => 'raw'], ['x' => 'not a config']))->toBeNull();
    }
});
