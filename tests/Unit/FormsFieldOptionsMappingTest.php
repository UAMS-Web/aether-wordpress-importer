<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms field-options mapping (#98).
 *
 * `mapFieldOptions()` maps a field's options through the implementing class's
 * `$fieldAttributeOptionsMapping`: it decodes a `field_options` JSON string
 * over the field data, maps the result with `mapAttributesFromMapping()`,
 * merges the class's conditional logic over it, and drops `null` values. A
 * class without the property maps nothing, which is the Gravity Forms case.
 * No standalone test named it before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldOptionsMappingTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping as FormidableFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FieldtypeMapping as NinjaFieldtypeMapping;

/**
 * A Gravity Forms mapping class with its own field-options mapping.
 *
 * @return FieldtypeMapping The mapping class
 */
function formsOptionsMappingClass(): FieldtypeMapping
{
    return new class extends FieldtypeMapping
    {
        /**
         * The field-options mapping these tests resolve against.
         *
         * @var array<string, array<int|string, mixed>>
         */
        protected static array $fieldAttributeOptionsMapping = [
            'a' => ['statamic_attribute' => 'A'],
            'gone' => ['statamic_attribute' => 'G', 'value_translation' => ['default' => [['search' => 'x', 'replace' => null]]]],
            'radio_only' => ['statamic_attribute' => 'R', 'exclude_fieldtypes' => ['radio']],
            'folder' => ['statamic_attribute' => 'F'],
            'if' => ['statamic_attribute' => 'if'],
        ];
    };
}

it('maps field options through the class mapping', function (
    array $field,
    array $expected
): void {
    expect(formsOptionsMappingClass()::mapFieldOptions($field))->toBe($expected);
})->with([
    'a mapped option' => [['type' => 'text', 'a' => 'v'], ['A' => 'v']],
    'an unmapped option' => [['type' => 'text', 'z' => 'v'], []],
    'an option from field_options' => [['type' => 'text', 'field_options' => '{"a":"j"}'], ['A' => 'j']],
    'field_options over the field' => [['type' => 'text', 'a' => 'v', 'field_options' => '{"a":"j"}'], ['A' => 'j']],
    'field_options that are not JSON' => [['type' => 'text', 'a' => 'v', 'field_options' => '{'], ['A' => 'v']],
    'field_options that decode to a scalar' => [['type' => 'text', 'a' => 'v', 'field_options' => '5'], ['A' => 'v']],
    'field_options already decoded' => [['type' => 'text', 'a' => 'v', 'field_options' => ['a' => 'j']], ['A' => 'v']],
    'a value translated to null, dropped' => [['type' => 'text', 'gone' => 'x', 'a' => 'v'], ['A' => 'v']],
    'an option for another fieldtype' => [['type' => 'text', 'radio_only' => 'v'], ['R' => 'v']],
    'an option excluded for the field type' => [['type' => 'radio', 'radio_only' => 'v'], []],
    'an option excluded for the field_options type' => [['field_options' => '{"type":"radio","radio_only":"v"}'], ['R' => 'v']],
]);

it('resolves the site handle in mapped options', function (): void {
    expect(formsOptionsMappingClass()::mapFieldOptions(['type' => 'text', 'folder' => 'forms/[#site_handle]'], [], null, 'arch'))->toBe(['F' => 'forms/arch'])
        ->and(formsOptionsMappingClass()::mapFieldOptions(['type' => 'text', 'folder' => 'forms/[#site_handle]']))->toBe(['F' => 'forms/[#site_handle]']);
});

it('merges the conditional logic over the mapped options', function (): void {
    $allFields = [
        ['id' => 7, 'type' => 'radio', 'label' => 'Color'],
    ];
    $logic = ['actionType' => 'show', 'logicType' => 'all', 'rules' => [['fieldId' => '7', 'operator' => 'is', 'value' => 'red']]];

    expect(formsOptionsMappingClass()::mapFieldOptions(['type' => 'text', 'a' => 'v', 'conditionalLogic' => $logic], $allFields))->toBe(['A' => 'v', 'if' => ['color_7' => 'equals red']])
        ->and(formsOptionsMappingClass()::mapFieldOptions(['type' => 'text', 'if' => 'mine', 'conditionalLogic' => \json_encode($logic)], $allFields))->toBe(['if' => ['color_7' => 'equals red']])
        ->and(formsOptionsMappingClass()::mapFieldOptions(['type' => 'text', 'if' => 'mine', 'conditionalLogic' => $logic], []))->toBe(['if' => 'mine']);
});

it('maps no field options for a class without a mapping', function (): void {
    $logic = ['actionType' => 'show', 'logicType' => 'all', 'rules' => [['fieldId' => '7', 'operator' => 'is', 'value' => 'red']]];

    expect(FieldtypeMapping::mapFieldOptions(['type' => 'text', 'a' => 'v', 'conditionalLogic' => $logic], [['id' => 7, 'type' => 'radio', 'label' => 'Color']]))->toBe([])
        ->and(NinjaFieldtypeMapping::mapFieldOptions(['type' => 'textbox', 'label' => 'Name', 'required' => 1]))->toBe([]);
});

it('maps the shipped Formidable field options', function (
    array $field,
    array $allFields,
    array $expected
): void {
    expect(FormidableFieldtypeMapping::mapFieldOptions($field, $allFields))->toBe($expected);
})->with([
    'a repeater button label' => [['type' => 'divider', 'field_options' => '{"add_label":"Add another"}'], [], ['button_label' => 'Add another']],
    'a US address' => [['type' => 'address', 'field_options' => '{"address_type":"us"}'], [], ['preset' => 'american']],
    'an unset address type' => [['type' => 'address', 'field_options' => '{}'], [], ['preset' => 'international']],
    'a conditional field' => [['type' => 'text', 'field_options' => '{"show_hide":"show","any_all":"all","hide_field":["915"],"hide_opt":["red"]}'], [['id' => '915', 'type' => 'radio', 'name' => 'Color']], ['if' => ['color_915' => 'equals red']]],
]);
