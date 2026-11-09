<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms subfield attribute mapping (#98).
 *
 * `mapSubfieldAttributes()` maps one composite-field input (an address or name
 * subfield) to Statamic attributes keyed by the subfield's handle. It reads the
 * mapping class's `$subfieldMapping`, merging the general attributes, then the
 * fieldtype's, then the subfield's, and resolves each `statamic_attribute`
 * through `processSubfieldAttributeMappings()` and
 * `processSubfieldStatamicAttribute()`, which replace the `[#subfield]` and
 * `[#preset]` placeholders. `getSubfieldMapping()` and
 * `resolveFieldAttributeOptionsMapping()` read a mapping class's optional
 * properties. No standalone test named any of these methods before this file.
 *
 * The Gravity Forms State and ZIP subfields are asserted through labels taken
 * from `json_decode()`, because that is the only form the loaders produce: the
 * mapping once keyed them with a JSON-escaped slash (`State \/ Province`), so
 * decoded input matched nothing and both subfields lost their configuration
 * (UAMS-Web/aether-wordpress-importer#438).
 *
 * @command  php vendor/bin/pest tests/Unit/FormsSubfieldAttributeMappingTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping as FormidableFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a non-public static method on a mapping class.
 *
 * @param  class-string  $class  The mapping class
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsSubfieldMethod(
    string $class,
    string $method,
    mixed ...$arguments
): mixed {
    return new ReflectionMethod(
        $class,
        $method
    )->invoke(
        null,
        ...$arguments
    );
}

/**
 * Resolve one subfield `statamic_attribute` for the `city` subfield.
 *
 * @param  mixed  $statamicAttribute  The statamic_attribute configuration
 * @param  int|string|null  $attrName  The source attribute's name
 * @param  array<int|string, mixed>  $parentField  The parent field data
 * @param  array<int|string, mixed>  $subfieldData  The subfield data
 * @param  ?string  $preset  The preset for placeholder replacement
 * @return mixed The `[attribute, true]` pair, or null
 */
function resolveFormsSubfieldAttribute(
    mixed $statamicAttribute,
    int|string|null $attrName = 'attr',
    array $parentField = [],
    array $subfieldData = [],
    ?string $preset = null
): mixed {
    return invokeFormsSubfieldMethod(
        FieldtypeMapping::class,
        'processSubfieldStatamicAttribute',
        $statamicAttribute,
        $attrName,
        $parentField,
        'city',
        $subfieldData,
        $preset
    );
}

/**
 * A Gravity Forms mapping class whose subfield mapping layers general,
 * fieldtype and subfield attributes for a `combo` fieldtype, and which defines
 * a field-attribute options mapping.
 *
 * @return FieldtypeMapping The mapping class instance
 */
function formsSubfieldLayeredMappingClass(): FieldtypeMapping
{
    return new class extends FieldtypeMapping
    {
        /**
         * The subfield mapping under test.
         *
         * @var array<int|string, mixed>
         */
        protected static array $subfieldMapping = [
            'attributes' => [
                'customLabel' => ['statamic_attribute' => ['default' => '[#subfield]_general']],
                'placeholder' => ['statamic_attribute' => ['default' => '[#subfield]_placeholder']],
                'note' => ['statamic_attribute' => ['default' => '[#subfield]_note']],
            ],
            'fieldtypes' => [
                'combo' => [
                    'attributes' => [
                        'customLabel' => ['statamic_attribute' => ['default' => '[#subfield]_fieldtype']],
                        'placeholder' => ['statamic_attribute' => ['default' => '[#subfield]_hint']],
                    ],
                    'subfields' => [
                        'Part' => [
                            'handle' => 'part',
                            'attributes' => [
                                'customLabel' => ['statamic_attribute' => ['default' => '[#subfield]_subfield']],
                            ],
                        ],
                        'Unnamed' => [
                            'attributes' => [
                                'customLabel' => ['statamic_attribute' => ['default' => '[#subfield]_label']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        /**
         * The field-attribute options mapping under test.
         *
         * @var array<int|string, mixed>
         */
        protected static array $fieldAttributeOptionsMapping = [
            'size' => ['statamic_attribute' => 'width'],
        ];
    };
}

it('resolves a subfield statamic_attribute and replaces its placeholders', function (
    mixed $statamicAttribute,
    int|string|null $attrName,
    array $parentField,
    array $subfieldData,
    ?string $preset,
    ?array $expected
): void {
    expect(resolveFormsSubfieldAttribute($statamicAttribute, $attrName, $parentField, $subfieldData, $preset))->toBe($expected);
})->with([
    'null' => [null, 'attr', [], [], null, null],
    'an empty string' => ['', 'attr', [], [], null, null],
    'a string' => ['[#subfield]_[#preset]_label', 'attr', [], [], 'intl', ['city_intl_label', true]],
    'a string with no preset' => ['[#subfield]_[#preset]_label', 'attr', [], [], null, ['city_[#preset]_label', true]],
    'an addressType match' => [['based_on_other_attribute_value' => ['addressType' => ['us' => '[#subfield]_us'], 'other' => 'ignored']], 'attr', ['addressType' => 'us'], [], null, ['city_us', true]],
    'an addressType mapped to null, then the default' => [['based_on_other_attribute_value' => ['addressType' => ['us' => null]], 'default' => '[#subfield]_d'], 'attr', ['addressType' => 'us'], [], null, ['city_d', true]],
    'no addressType, then the default' => [['based_on_other_attribute_value' => ['addressType' => ['us' => '[#subfield]_us']], 'default' => '[#subfield]_d'], 'attr', [], [], null, ['city_d', true]],
    'an addressType with no entry and no default' => [['based_on_other_attribute_value' => ['addressType' => ['us' => '[#subfield]_us']]], 'attr', ['addressType' => 'ca'], [], null, null],
    'an own-value match' => [['based_on_attribute_value' => ['default' => ['x' => '[#subfield]_[#preset]_x']], 'default' => 'd'], 'attr', [], ['attr' => 'x'], 'p', ['city_p_x', true]],
    'an own-value match mapped to null' => [['based_on_attribute_value' => ['default' => ['x' => null]], 'default' => 'd'], 'attr', [], ['attr' => 'x'], null, null],
    'an own-value miss, then the default' => [['based_on_attribute_value' => ['default' => ['x' => null]], 'default' => '[#subfield]_d'], 'attr', [], ['attr' => 'y'], null, ['city_d', true]],
    'an own-value miss and no default' => [['based_on_attribute_value' => ['default' => ['x' => 'X']]], 'attr', [], ['attr' => 'y'], null, null],
    'an own-value string' => [['based_on_attribute_value' => '[#subfield]_s'], 'attr', [], [], null, ['city_s', true]],
    'an own-value match by an integer name' => [['based_on_attribute_value' => ['default' => ['1' => 'one']]], 5, [], [5 => '1'], null, ['one', true]],
    'only a default' => [['default' => '[#subfield]_d'], 'attr', [], [], null, ['city_d', true]],
    'a default of null' => [['default' => null], 'attr', [], [], null, null],
    'an array with nothing to resolve' => [['other' => 'x'], 'attr', [], [], null, null],
    'a number' => [5, 'attr', [], [], null, null],
]);

it('excludes a hidden flag that is false, and keeps one that is true', function (
    mixed $isHidden,
    ?array $expected
): void {
    $config = ['default' => '[#subfield]_include', 'based_on_attribute_value' => ['default' => ['false' => null, 0 => null]]];

    expect(resolveFormsSubfieldAttribute($config, 'isHidden', [], ['isHidden' => $isHidden]))->toBe($expected);
})->with([
    'false' => [false, null],
    "'false'" => ['false', null],
    '0' => [0, null],
    'true' => [true, ['city_include', true]],
]);

it('rewrites only the mappings whose statamic_attribute resolves', function (): void {
    $mappings = [
        'customLabel' => ['statamic_attribute' => '[#subfield]_label', 'alert_unsupported' => false],
        'excluded' => ['statamic_attribute' => ['based_on_attribute_value' => ['default' => ['x' => null]]]],
        'unresolved' => ['statamic_attribute' => ['other' => 'x']],
        'noAttribute' => ['value_translation' => []],
        'notAnArray' => 'label',
    ];

    expect(invokeFormsSubfieldMethod(FieldtypeMapping::class, 'processSubfieldAttributeMappings', $mappings, [], 'city', ['excluded' => 'x'], null))->toBe([
        'customLabel' => ['statamic_attribute' => 'city_label', 'alert_unsupported' => false],
        'excluded' => ['statamic_attribute' => ['based_on_attribute_value' => ['default' => ['x' => null]]]],
        'unresolved' => ['statamic_attribute' => ['other' => 'x']],
        'noAttribute' => ['value_translation' => []],
        'notAnArray' => [],
    ]);
});

it('maps a Gravity Forms address subfield for each address type', function (
    string $addressType,
    string $label,
    array $expected
): void {
    $subfield = ['id' => '1.1', 'label' => $label, 'customLabel' => 'CL', 'placeholder' => 'PH', 'defaultValue' => 'DV', 'isHidden' => true];

    expect(FieldtypeMapping::mapSubfieldAttributes('address', $subfield, ['id' => 1, 'type' => 'address', 'label' => 'Address', 'addressType' => $addressType]))->toBe($expected);
})->with([
    'an international street address' => ['international', 'Street Address', ['street_address_label' => 'CL', 'street_address_placeholder' => 'PH', 'street_address_default' => 'DV', 'street_address_include' => false]],
    'a US address line 2' => ['us', 'Address Line 2', ['address_line_2_label' => 'CL', 'address_line_2_placeholder' => 'PH', 'address_line_2_default' => 'DV', 'address_line_2_include' => false]],
    'a Canadian city' => ['canadian', 'City', ['city_label' => 'CL', 'city_placeholder' => 'PH', 'city_default' => 'DV', 'city_include' => false]],
    'an international country' => ['international', 'Country', ['country_label' => 'CL', 'country_include' => false]],
    'a US country' => ['us', 'Country', ['country_label' => 'CL']],
    'a Canadian country' => ['canadian', 'Country', ['country_label' => 'CL']],
]);

it('maps a Gravity Forms State or ZIP subfield whose label came from decoded JSON (#438)', function (
    string $json,
    string $addressType,
    array $expected
): void {
    // Decode the label the way JsonFileLoader and the migration API client do, so an
    // escaped slash reintroduced into a mapping key no longer matches it
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $label = is_array($decoded) ? $decoded['label'] : null;
    $subfield = ['id' => '1.4', 'label' => $label, 'customLabel' => 'CL', 'placeholder' => 'PH', 'defaultValue' => 'DV', 'isHidden' => true];

    expect($label)->not->toContain('\\')
        ->and(FieldtypeMapping::mapSubfieldAttributes('address', $subfield, ['id' => 1, 'type' => 'address', 'label' => 'Address', 'addressType' => $addressType]))->toBe($expected);
})->with([
    'a US state' => ['{"label":"State \/ Province"}', 'us', ['state_province_region_american_label' => 'CL', 'state_province_region_american_placeholder' => 'PH', 'state_province_region_american_include' => false]],
    'a Canadian province' => ['{"label":"State \/ Province"}', 'canadian', ['state_province_region_canadian_label' => 'CL', 'state_province_region_canadian_placeholder' => 'PH', 'state_province_region_canadian_default' => 'DV', 'state_province_region_canadian_include' => false]],
    'an international region' => ['{"label":"State \/ Province"}', 'international', ['state_province_region_international_label' => 'CL', 'state_province_region_international_placeholder' => 'PH', 'state_province_region_international_default' => 'DV', 'state_province_region_international_include' => false]],
    'a US ZIP code' => ['{"label":"ZIP \/ Postal Code"}', 'us', ['postal_code_label' => 'CL', 'postal_code_placeholder' => 'PH', 'postal_code_default' => 'DV', 'postal_code_include' => false]],
    'a Canadian postal code' => ['{"label":"ZIP \/ Postal Code"}', 'canadian', ['postal_code_label' => 'CL', 'postal_code_placeholder' => 'PH', 'postal_code_default' => 'DV', 'postal_code_include' => false]],
    'an international postal code' => ['{"label":"ZIP \/ Postal Code"}', 'international', ['postal_code_label' => 'CL', 'postal_code_placeholder' => 'PH', 'postal_code_default' => 'DV', 'postal_code_include' => false]],
]);

it('keys no Gravity Forms subfield by a label carrying a JSON-escaped slash (#438)', function (): void {
    // Collect every subfield label the mapping keys, across every field type
    $labels = [];
    $fieldtypes = FieldtypeMapping::getSubfieldMapping()['fieldtypes'] ?? [];
    foreach (is_array($fieldtypes) ? $fieldtypes : [] as $fieldtype) {
        $subfields = is_array($fieldtype) ? ($fieldtype['subfields'] ?? []) : [];
        foreach (is_array($subfields) ? array_keys($subfields) : [] as $label) {
            $labels[] = (string) $label;
        }
    }

    expect($labels)->toContain('State / Province', 'ZIP / Postal Code', 'AM/PM')
        ->and(array_values(array_filter($labels, static fn (string $label): bool => str_contains($label, '\\/'))))->toBe([]);
});

it('maps a Gravity Forms name subfield, inverting the hidden flag', function (
    string $label,
    bool $isHidden,
    array $expected
): void {
    $subfield = ['id' => '2.1', 'label' => $label, 'customLabel' => 'CL', 'placeholder' => 'PH', 'defaultValue' => 'DV', 'isHidden' => $isHidden];

    expect(FieldtypeMapping::mapSubfieldAttributes('name', $subfield, ['id' => 2, 'type' => 'name', 'label' => 'Name']))->toBe($expected);
})->with([
    'a hidden prefix' => ['Prefix', true, ['prefix_label' => 'CL', 'prefix_placeholder' => 'PH', 'prefix_default' => 'DV']],
    'a visible prefix' => ['Prefix', false, ['prefix_label' => 'CL', 'prefix_placeholder' => 'PH', 'prefix_default' => 'DV', 'prefix_include' => true]],
    'a hidden first name' => ['First', true, ['first_name_label' => 'CL', 'first_name_placeholder' => 'PH', 'first_name_default' => 'DV', 'first_name_include' => false]],
    'a visible first name' => ['First', false, ['first_name_label' => 'CL', 'first_name_placeholder' => 'PH', 'first_name_default' => 'DV']],
    'a visible middle name' => ['Middle', false, ['middle_name_label' => 'CL', 'middle_name_placeholder' => 'PH', 'middle_name_default' => 'DV', 'middle_name_include' => true]],
    'a hidden last name' => ['Last', true, ['last_name_label' => 'CL', 'last_name_placeholder' => 'PH', 'last_name_default' => 'DV', 'last_name_include' => false]],
    'a visible suffix' => ['Suffix', false, ['suffix_label' => 'CL', 'suffix_placeholder' => 'PH', 'suffix_default' => 'DV', 'suffix_include' => true]],
]);

it('maps nothing for a subfield it cannot place', function (
    string $fieldType,
    array $subfield
): void {
    expect(FieldtypeMapping::mapSubfieldAttributes($fieldType, $subfield, ['addressType' => 'us']))->toBe([]);
})->with([
    'no label' => ['address', ['customLabel' => 'CL']],
    'an empty label' => ['address', ['label' => '', 'customLabel' => 'CL']],
    'a label that is not a string' => ['address', ['label' => ['City'], 'customLabel' => 'CL']],
    'an unknown label' => ['address', ['label' => 'Region', 'customLabel' => 'CL']],
    'an unknown fieldtype' => ['text', ['label' => 'City', 'customLabel' => 'CL']],
    'a hidden flag that is false and nothing else' => ['address', ['label' => 'City', 'isHidden' => false]],
]);

it('falls back to the mapping handle when no Statamic fieldtype class resolves', function (): void {
    expect(FieldtypeMapping::mapSubfieldAttributes('address', ['label' => 'City', 'customLabel' => 'C'], ['addressType' => 'us'], 'gravity'))->toBe(['city_label' => 'C']);
});

it('layers general, fieldtype and subfield attributes, the most specific winning', function (): void {
    $class = formsSubfieldLayeredMappingClass();
    $subfield = ['label' => 'Part', 'customLabel' => 'CL', 'placeholder' => 'PH', 'note' => 'N'];

    expect($class::mapSubfieldAttributes('combo', $subfield))->toBe(['part_subfield' => 'CL', 'part_hint' => 'PH', 'part_note' => 'N'])
        ->and($class::mapSubfieldAttributes('other', $subfield))->toBe([])
        ->and($class::mapSubfieldAttributes('combo', ['label' => 'Unnamed', 'customLabel' => 'CL']))->toBe([]);
});

it('reads the optional subfield and options mappings a class defines', function (): void {
    $layered = formsSubfieldLayeredMappingClass();

    expect(array_keys($layered::getSubfieldMapping()))->toBe(['attributes', 'fieldtypes'])
        ->and(invokeFormsSubfieldMethod($layered::class, 'resolveFieldAttributeOptionsMapping'))->toBe(['size' => ['statamic_attribute' => 'width']])
        ->and(array_keys(FieldtypeMapping::getSubfieldMapping()))->toBe(['fieldtypes', 'attributes'])
        ->and(invokeFormsSubfieldMethod(FieldtypeMapping::class, 'resolveFieldAttributeOptionsMapping'))->toBe([])
        ->and(invokeFormsSubfieldMethod(FormidableFieldtypeMapping::class, 'resolveFieldAttributeOptionsMapping'))->not->toBe([]);
});
