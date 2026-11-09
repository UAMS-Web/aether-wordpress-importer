<?php

declare(strict_types=1);

/**
 * Unit tests for the Formidable Forms address subfield mapping (#496).
 *
 * Formidable Forms keeps an address field's subfields in the parent field: a
 * `<key>_desc` field option per subfield, a `placeholder` field option keyed by
 * subfield, and a `default_value` JSON object keyed by subfield.
 * `FormBlueprintCreator::getMappedSubfieldAttributes()` builds one subfield
 * record per key and runs it through `mapSubfieldAttributes()`, carrying the
 * preset that `FieldtypeMapping::getAddressPreset()` reads from `address_type`.
 *
 * The preset-specific handle (`state_province_region_international`) is resolved
 * by the host app's `Address` fieldtype, which this suite cannot load, so here
 * the State subfield keeps its canonical handle. The host-app half is
 * `FormidableFormsBlueprintImportTest` in `uams-statamic`.
 *
 * @command  php vendor/bin/pest tests/Unit/FormidableFormsAddressSubfieldMappingTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormBlueprintCreator;

/**
 * Map a Formidable Forms field's subfields through the blueprint creator.
 *
 * @param  array<int|string, mixed>  $field  The Formidable Forms field data
 * @return mixed The mapped subfield attributes
 */
function mapFormidableAddressSubfields(
    array $field
): mixed {
    $blueprintCreator = new FormBlueprintCreator;

    return new ReflectionMethod(
        $blueprintCreator,
        'getMappedSubfieldAttributes'
    )->invoke(
        $blueprintCreator,
        $field,
        'formidable-forms'
    );
}

it('reads the address preset from address_type through its translation', function (array $field, string $preset): void {
    expect(FieldtypeMapping::getAddressPreset($field))->toBe($preset);
})->with([
    'no field options' => [['type' => 'address'], 'international'],
    'no address_type' => [['type' => 'address', 'field_options' => '{"align":"block"}'], 'international'],
    'international' => [['type' => 'address', 'field_options' => '{"address_type":"international"}'], 'international'],
    'europe' => [['type' => 'address', 'field_options' => '{"address_type":"europe"}'], 'international'],
    'us' => [['type' => 'address', 'field_options' => '{"address_type":"us"}'], 'american'],
    'generic' => [['type' => 'address', 'field_options' => '{"address_type":"generic"}'], 'american'],
]);

it('maps an American address the way the exports carry one', function (): void {
    // The shape 25 exported address fields share: four descriptions, empty
    // placeholders, and a default state and country.
    $field = [
        'type' => 'address',
        'name' => 'Mailing Address',
        'field_options' => '{"address_type":"us","line1_desc":"Street Address","line2_desc":"Address Line 2","state_desc":"State / Province","zip_desc":"ZIP / Postal Code","placeholder":{"line1":"","line2":"","city":"","state":"","zip":"","country":""}}',
        'default_value' => '{"line1":"","line2":"","city":"","state":"Arkansas","zip":"","country":"United States"}',
    ];

    // The state name matches no state-code choice and the country name no country
    // code, so neither default is written.
    expect(mapFormidableAddressSubfields($field))->toBe([
        'street_address_label' => 'Street Address',
        'address_line_2_label' => 'Address Line 2',
        'state_province_region_label' => 'State / Province',
        'postal_code_label' => 'ZIP / Postal Code',
    ]);
});

it('maps every subfield description, placeholder and default of an international address', function (): void {
    $field = [
        'type' => 'address',
        'name' => 'Address',
        'field_options' => '{"address_type":"international","line1_desc":"Line 1","line2_desc":"Line 2","city_desc":"Town","state_desc":"Region","zip_desc":"Post code","country_desc":"Nation","placeholder":{"line1":"P1","line2":"P2","city":"P3","state":"P4","zip":"P5","country":"P6"}}',
        'default_value' => '{"line1":"D1","line2":"D2","city":"D3","state":"D4","zip":"D5","country":"D6"}',
    ];

    // The country subfield is a select keyed by code, so it takes its label only.
    expect(mapFormidableAddressSubfields($field))->toBe([
        'street_address_label' => 'Line 1',
        'street_address_placeholder' => 'P1',
        'street_address_default' => 'D1',
        'address_line_2_label' => 'Line 2',
        'address_line_2_placeholder' => 'P2',
        'address_line_2_default' => 'D2',
        'city_label' => 'Town',
        'city_placeholder' => 'P3',
        'city_default' => 'D3',
        'state_province_region_label' => 'Region',
        'state_province_region_placeholder' => 'P4',
        'state_province_region_default' => 'D4',
        'postal_code_label' => 'Post code',
        'postal_code_placeholder' => 'P5',
        'postal_code_default' => 'D5',
        'country_label' => 'Nation',
    ]);
});

it('writes an address default and placeholder per subfield, never on the field itself', function (): void {
    // Before #496 the field-level mappings wrote the whole default JSON object as
    // one `default` string, and a placeholder object with any non-empty entry as
    // `placeholder`.
    $address = [
        'type' => 'address',
        'name' => 'Address',
        'default_value' => '{"line1":"D1","state":"Arkansas"}',
        'field_options' => '{"address_type":"us","placeholder":{"line1":"P1"}}',
    ];
    $text = [
        'type' => 'text',
        'name' => 'Name',
        'default_value' => 'hello',
        'field_options' => '{"placeholder":"Type here"}',
    ];

    expect(FieldtypeMapping::mapFieldOptions($address))->toBe(['preset' => 'american'])
        ->and(FieldtypeMapping::mapFieldAttributesWithContext($address, 'Form', 'Address', 1))->toBe(['display' => 'Address'])
        ->and(FieldtypeMapping::mapFieldOptions($text))->toBe(['placeholder' => 'Type here'])
        ->and(FieldtypeMapping::mapFieldAttributesWithContext($text, 'Form', 'Name', 1))->toBe(['display' => 'Name', 'default' => 'hello']);
});

it('maps no subfields for an address with nothing set, or for another fieldtype', function (array $field): void {
    expect(mapFormidableAddressSubfields($field))->toBe([]);
})->with([
    'bare address' => [['type' => 'address', 'name' => 'Address']],
    'undecodable options' => [['type' => 'address', 'name' => 'Address', 'field_options' => '{', 'default_value' => '{']],
    'text field with a description option' => [['type' => 'text', 'name' => 'Name', 'field_options' => '{"line1_desc":"Street Address"}']],
]);
