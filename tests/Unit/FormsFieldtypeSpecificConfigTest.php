<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms per-fieldtype configuration resolution (#98).
 *
 * `getFieldtypeSpecificConfig()` reduces an attribute's mapping to the
 * configuration for one fieldtype: for each supported property it takes the
 * `specific` entry for that fieldtype, else `default`, and keeps the whole
 * structure when it is conditional on an attribute value (or is one of the two
 * properties that always need it). A plain value is kept as it is.
 * `postProcessMappedAttributes()` fills the `[#subfield]` and `[#preset]`
 * placeholders in mapped attribute names and drops an empty name, and
 * `processSimpleStatamicAttribute()` translates a value when the mapping has a
 * `value_translation`. No standalone test named any of these methods before
 * this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldtypeSpecificConfigTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsConfigMethod(
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

it('resolves each property to its fieldtype entry, then its default', function (
    array $attrConfig,
    ?string $fieldtype,
    array $expected
): void {
    expect(invokeFormsConfigMethod('getFieldtypeSpecificConfig', $attrConfig, $fieldtype))->toBe($expected);
})->with([
    'a specific entry' => [['statamic_attribute' => ['default' => 'instructions', 'specific' => ['consent' => 'consent_agreement']]], 'consent', ['statamic_attribute' => 'consent_agreement']],
    'the default for another fieldtype' => [['statamic_attribute' => ['default' => 'instructions', 'specific' => ['consent' => 'consent_agreement']]], 'text', ['statamic_attribute' => 'instructions']],
    'the default with no fieldtype' => [['statamic_attribute' => ['default' => 'instructions', 'specific' => ['' => 'x']]], null, ['statamic_attribute' => 'instructions']],
    'the default with an empty fieldtype' => [['statamic_attribute' => ['default' => 'instructions', 'specific' => ['' => 'x']]], '', ['statamic_attribute' => 'instructions']],
    'a specific entry of null' => [['separate_value_field_option' => ['default' => 'enableChoiceValue', 'specific' => ['survey' => null]]], 'survey', ['separate_value_field_option' => null]],
    'a specific entry of false' => [['trim' => ['default' => true, 'specific' => ['html' => false]]], 'html', ['trim' => false]],
    'a default of false' => [['trim' => ['default' => false]], 'text', ['trim' => false]],
    'a plain value' => [['statamic_attribute' => 'display', 'trim' => true], 'text', ['statamic_attribute' => 'display', 'trim' => true]],
    'a plain list' => [['exclude_fieldtypes' => ['consent', 'html']], 'text', ['exclude_fieldtypes' => ['consent', 'html']]],
    'an unsupported property' => [['statamic_attribute' => 'display', 'not_a_property' => 'x'], 'text', ['statamic_attribute' => 'display']],
    'a property set to null' => [['statamic_attribute' => null, 'trim' => true], 'text', ['trim' => true]],
    'no properties' => [[], 'text', []],
]);

it('keeps the whole structure when it is conditional on an attribute value', function (
    string $property,
    array $propertyConfig
): void {
    expect(invokeFormsConfigMethod('getFieldtypeSpecificConfig', [$property => $propertyConfig], 'radio'))
        ->toBe([$property => $propertyConfig]);
})->with([
    'based_on_attribute_value' => ['statamic_attribute', ['default' => 'default', 'based_on_attribute_value' => ['default' => ['{date_mdy}' => null]]]],
    'based_on_other_attribute_value' => ['choices_as_fields', ['default' => false, 'based_on_other_attribute_value' => ['default' => ['enableColumns' => ['true' => true]]]]],
    'specific_based_on_attribute_value' => ['value_translation', ['specific' => ['radio' => ['a' => 'A']], 'specific_based_on_attribute_value' => ['radio' => []]]],
    'a nested-attribute rule, even with only a default' => ['statamic_other_field_value_based_on_nested_attribute_value', ['default' => ['choices' => []]]],
    'an attribute-value rule, even with only a default' => ['statamic_other_field_value_based_on_attribute_value', ['default' => ['a' => ['x' => 'y']]]],
]);

it('keeps a structure whose default is null, which downstream reads as unresolved', function (): void {
    expect(invokeFormsConfigMethod('getFieldtypeSpecificConfig', ['statamic_attribute' => ['default' => null]], 'text'))
        ->toBe(['statamic_attribute' => ['default' => null]])
        ->and(invokeFormsConfigMethod('getFieldtypeSpecificConfig', ['get_selected_choice' => ['default' => null, 'specific' => ['radio' => 'x']]], 'text'))
        ->toBe(['get_selected_choice' => ['default' => null, 'specific' => ['radio' => 'x']]]);
});

it('fills the subfield and preset placeholders in mapped attribute names', function (): void {
    $mapped = [
        '[#subfield]' => 'v1',
        '[#subfield]_label' => 'v2',
        'plain' => 'v3',
        '' => 'dropped',
        0 => 'numeric',
    ];

    expect(invokeFormsConfigMethod('postProcessMappedAttributes', $mapped, 'state_[#preset]', 'us'))->toBe([
        'state_us' => 'v1',
        'state_us_label' => 'v2',
        'plain' => 'v3',
        0 => 'numeric',
    ])->and(invokeFormsConfigMethod('postProcessMappedAttributes', ['[#subfield]' => 'v'], null, null))->toBe([
        '[#subfield]' => 'v',
    ])->and(invokeFormsConfigMethod('postProcessMappedAttributes', ['[#subfield]' => 'v'], '', null))->toBe([]);
});

it('translates a simple attribute only when the mapping has a value_translation', function (): void {
    $translated = ['value_translation' => ['a' => 'A']];

    expect(invokeFormsConfigMethod('processSimpleStatamicAttribute', 'a', $translated, 'display'))->toBe('A')
        ->and(invokeFormsConfigMethod('processSimpleStatamicAttribute', 'b', $translated, 'display'))->toBe('b')
        ->and(invokeFormsConfigMethod('processSimpleStatamicAttribute', '', $translated, 'display'))->toBeNull()
        ->and(invokeFormsConfigMethod('processSimpleStatamicAttribute', 'a', [], 'display'))->toBe('a')
        ->and(invokeFormsConfigMethod('processSimpleStatamicAttribute', 'a', ['value_translation' => null], 'display'))->toBe('a');
});
