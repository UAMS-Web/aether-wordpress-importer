<?php

declare(strict_types=1);

/**
 * Unit tests for the preset that picks a preset-specific subfield handle (#429).
 *
 * `FieldtypeMappingTrait::getStatamicPresetValue()` finds the attribute mapped to
 * Statamic's `preset` and returns its value, translated when the mapping declares
 * a flat `value_translation`. It used to recognize only a plain `'preset'` string,
 * which no shipped mapping uses, so it never found the Gravity Forms `addressType`
 * mapping. The handle lookup itself runs only in the host app.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsPresetValueTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Call the protected preset lookup on the Gravity Forms mapping class.
 *
 * @param  array<int|string, mixed>  $parentField  The parent field data
 * @param  ?array<int|string, mixed>  $mappingConfig  The mapping config, or the shipped one when null
 * @return ?string The preset value
 */
function statamicPresetValue(
    array $parentField,
    ?array $mappingConfig = null
): ?string {
    $mappingConfig ??= FieldtypeMapping::getFieldAttributeMapping();

    $preset = new ReflectionMethod(
        FieldtypeMapping::class,
        'getStatamicPresetValue'
    )->invoke(
        null,
        $parentField,
        $mappingConfig
    );

    return \is_string($preset) ? $preset : null;
}

it('reads the shipped Gravity Forms address type as the preset', function (
    string $addressType
): void {
    expect(statamicPresetValue([
        'type' => 'address',
        'addressType' => $addressType,
    ]))->toBe($addressType);
})->with([
    'us, which the mapping excludes from the field config' => ['us'],
    'canadian' => ['canadian'],
    'international' => ['international'],
]);

it('finds no preset on a Gravity Forms field that carries no preset attribute', function (): void {
    expect(statamicPresetValue([
        'type' => 'text',
        'label' => 'Name',
    ]))->toBeNull();
});

it('recognizes a preset mapped by a plain string or by an array default', function (
    mixed $statamicAttribute,
    ?string $expected
): void {
    $mappingConfig = [
        'kind' => [
            'statamic_attribute' => $statamicAttribute,
            'value_translation' => [
                'us' => 'american',
            ],
        ],
    ];

    expect(statamicPresetValue(['kind' => 'us'], $mappingConfig))->toBe($expected);
})->with([
    'a plain string' => ['preset', 'american'],
    'an array default' => [['default' => 'preset'], 'american'],
    'an array default with an exclusion for the value' => [
        ['default' => 'preset', 'based_on_attribute_value' => ['default' => ['us' => null]]],
        'american',
    ],
    'an array whose default is another attribute' => [['default' => 'placeholder'], null],
    'another attribute' => ['placeholder', null],
]);

it('keeps an untranslated value when the translation has no entry for it', function (): void {
    $mappingConfig = [
        'kind' => [
            'statamic_attribute' => ['default' => 'preset'],
            'value_translation' => [
                'us' => 'american',
            ],
        ],
    ];

    expect(statamicPresetValue(['kind' => 'canadian'], $mappingConfig))->toBe('canadian');
});

it('moves past a preset attribute the field does not carry', function (): void {
    $mappingConfig = [
        'first' => [
            'statamic_attribute' => ['default' => 'preset'],
        ],
        'second' => [
            'statamic_attribute' => ['default' => 'preset'],
        ],
    ];

    expect(statamicPresetValue(['second' => 'canadian'], $mappingConfig))->toBe('canadian')
        ->and(statamicPresetValue([], $mappingConfig))->toBeNull();
});
