<?php

declare(strict_types=1);

use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

uses(TestCase::class);

/**
 * Host-app tests for the preset-specific address subfield handle (#429).
 *
 * `FieldtypeMappingTrait::mapSubfieldAttributes()` asks the host app's
 * `App\Fieldtypes\Address::getPresetSpecificSubfieldHandle()` which State subfield
 * to write to, passing the preset `getStatamicPresetValue()` reads from the
 * Gravity Forms `addressType`. That lookup runs only when the host fieldtype
 * class exists, so these cases need the booted app. They assert the handle each
 * key is written under, not the full key: the State keys repeat the preset in the
 * host app (#497), and these cases pin the handle, not that defect.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/GravityFormsAddressSubfieldHandleTest.php
 */

/**
 * Find the subfield label the Gravity Forms mapping keys the State subfield by.
 *
 * @return string The mapping's label for the `state_province_region` subfield
 */
function gravityStateSubfieldLabel(): string
{
    $mapping = FieldtypeMapping::getSubfieldMapping();
    $fieldtypes = \is_array($mapping['fieldtypes'] ?? null) ? $mapping['fieldtypes'] : [];
    $address = \is_array($fieldtypes['address'] ?? null) ? $fieldtypes['address'] : [];
    $subfields = \is_array($address['subfields'] ?? null) ? $address['subfields'] : [];

    foreach ($subfields as $label => $subfield) {
        if (\is_array($subfield) && ($subfield['handle'] ?? null) === 'state_province_region') {
            return (string) $label;
        }
    }

    throw new RuntimeException('The Gravity Forms address mapping has no state_province_region subfield.');
}

it('resolves the host Address fieldtype for a Gravity Forms address', function (): void {
    expect(FieldtypeMapping::resolveStatamicFieldtypeClass('address', 'gravity-forms'))
        ->toBe('App\\Fieldtypes\\Address');
});

it('writes the State subfield under the handle for the Gravity Forms address type', function (
    string $addressType,
    string $expectedHandle
): void {
    $parent = [
        'id' => 67,
        'type' => 'address',
        'label' => 'Address',
        'addressType' => $addressType,
    ];
    $state = [
        'id' => '67.4',
        'label' => gravityStateSubfieldLabel(),
        'customLabel' => 'Foo State',
        'placeholder' => 'Bar State',
    ];

    $keys = \array_map(
        strval(...),
        \array_keys(FieldtypeMapping::mapSubfieldAttributes('address', $state, $parent, 'gravity-forms', [$parent]))
    );

    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        expect($key)->toStartWith($expectedHandle.'_');
    }
})->with([
    'us, which the mapping excludes from the field config' => ['us', 'state_province_region_american'],
    'canadian' => ['canadian', 'state_province_region_canadian'],
    'international' => ['international', 'state_province_region_international'],
]);
