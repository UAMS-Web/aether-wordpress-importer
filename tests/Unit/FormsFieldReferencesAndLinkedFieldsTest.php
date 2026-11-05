<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms field references, linked fields and set attributes
 * (#98).
 *
 * `translateFieldReferences()` rewrites `[329]`, `[Field Label]` and
 * `[field_name]` references in a mapped string to Statamic handles.
 * `getLinkedFieldFromMapping()` picks a fieldtype's `linked_field` from its
 * `based_on_other_attribute_value` table, then its `based_on_attribute_value`
 * table (each with an optional `default`/`specific` split), then `default`.
 * `getSetAttributesFromMapping()` merges a fieldtype's `set_attributes`
 * `default` with the entry for the field's key and resolves `[#site_handle]`.
 * A line-coverage run left most of the first two unexecuted.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldReferencesAndLinkedFieldsTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Translate the field references in a string.
 *
 * @param  string  $text  The text to translate
 * @param  array<int|string, mixed>  $allFields  The form's fields
 * @param  array<int|string, mixed>|null  $currentField  The current field
 * @return mixed The translated text
 */
function translateFormsFieldReferences(
    string $text,
    array $allFields = [],
    ?array $currentField = null
): mixed {
    return new ReflectionMethod(
        FieldtypeMapping::class,
        'translateFieldReferences'
    )->invoke(
        null,
        $text,
        $allFields,
        $currentField
    );
}

it('translates field references to handles', function (
    string $text,
    array $allFields,
    ?array $currentField,
    string $expected
): void {
    expect(translateFormsFieldReferences($text, $allFields, $currentField))->toBe($expected);
})->with([
    'a field id' => ['Total: [7]', [['id' => 7, 'label' => 'Unit Price']], null, 'Total: [unit_price_7]'],
    'a field id, as a string' => ['[7]', [['id' => '7', 'label' => 'Color']], null, '[color_7]'],
    'the matching field among several' => ['[8]', [['id' => 7, 'label' => 'Color'], ['id' => 8, 'label' => 'Size']], null, '[size_8]'],
    'a field id with no field' => ['[9]', [['id' => 7, 'label' => 'Color']], null, '[9]'],
    'a field id whose field has no label' => ['[7]', [['id' => 7], ['id' => 7, 'label' => '']], null, '[7]'],
    'a field id beside a field with no id' => ['[7]', [['label' => 'Nameless'], ['id' => 7, 'label' => 'Color']], null, '[color_7]'],
    'a field that is not an array' => ['[7]', ['junk', ['id' => 7, 'label' => 'Color']], null, '[color_7]'],
    'a field label' => ['Hello [Full Name]', [['id' => 3, 'label' => 'Full Name']], null, 'Hello [full_name_3]'],
    'a field label with no id' => ['[Full Name]', [['label' => 'Full Name']], null, '[full_name_unknown]'],
    'an unknown label' => ['[Other]', [['id' => 3, 'label' => 'Full Name']], null, '[Other]'],
    'the current field' => ['[field_name] is required', [], ['id' => 4, 'label' => 'Email'], '[email_4] is required'],
    'the current field, no id' => ['[field_name]', [], ['label' => 'Email'], '[email_unknown]'],
    'no current field' => ['[field_name]', [], null, '[current_field_name]'],
    'an empty current field' => ['[field_name]', [], [], '[current_field_name]'],
    'a current field with no label' => ['[field_name]', [], ['id' => 4], '[current_field_name]'],
    'several references' => ['[7] and [Size]', [['id' => 7, 'label' => 'Color'], ['id' => 8, 'label' => 'Size']], null, '[color_7] and [size_8]'],
    'no references' => ['plain text', [['id' => 7, 'label' => 'Color']], null, 'plain text'],
    'an empty bracket pair' => ['[]', [['id' => 7, 'label' => 'Color']], null, '[]'],
]);

it('resolves a linked field from the mapping', function (
    array $linkedField,
    string|array $fieldOrType,
    ?string $expected
): void {
    expect(FieldtypeMapping::getLinkedFieldFromMapping(['t' => ['default' => 'D', 'linked_field' => $linkedField]], $fieldOrType))->toBe($expected);
})->with([
    'a default' => [['default' => 'common.d'], 't', 'common.d'],
    'a default, by field' => [['default' => 'common.d'], ['type' => 't'], 'common.d'],
    'no default' => [[], 't', null],
    'a default that is not a string' => [['default' => 5], 't', null],
    'an other-attribute value' => [['based_on_other_attribute_value' => ['o' => ['y' => 'common.y']], 'default' => 'common.d'], ['type' => 't', 'o' => 'y'], 'common.y'],
    'an other-attribute value from field_options' => [['based_on_other_attribute_value' => ['o' => ['y' => 'common.y']], 'default' => 'common.d'], ['type' => 't', 'field_options' => '{"o":"y"}'], 'common.y'],
    'field_options over the field' => [['based_on_other_attribute_value' => ['o' => ['y' => 'common.y']], 'default' => 'common.d'], ['type' => 't', 'o' => 'q', 'field_options' => '{"o":"y"}'], 'common.y'],
    'field_options that are not JSON' => [['based_on_other_attribute_value' => ['o' => ['y' => 'common.y']], 'default' => 'common.d'], ['type' => 't', 'o' => 'y', 'field_options' => '{'], 'common.y'],
    'an other-attribute miss, then the default' => [['based_on_other_attribute_value' => ['o' => ['y' => 'common.y']], 'default' => 'common.d'], ['type' => 't', 'o' => 'q'], 'common.d'],
    'an other-attribute value, by type only' => [['based_on_other_attribute_value' => ['o' => ['#not_set' => 'common.ns']], 'default' => 'common.d'], 't', 'common.ns'],
    'an other-attribute value mapped to null, then the default' => [['based_on_other_attribute_value' => ['o' => ['y' => null]], 'default' => 'common.d'], ['type' => 't', 'o' => 'y'], 'common.d'],
    'an other-attribute default table' => [['based_on_other_attribute_value' => ['default' => ['o' => ['y' => 'common.dy']]], 'default' => 'common.d'], ['type' => 't', 'o' => 'y'], 'common.dy'],
    'an other-attribute fieldtype table' => [['based_on_other_attribute_value' => ['default' => ['o' => ['y' => 'common.dy']], 'specific' => ['t' => ['o' => ['y' => 'common.ty']]]], 'default' => 'common.d'], ['type' => 't', 'o' => 'y'], 'common.ty'],
    'another fieldtype table, then the default table' => [['based_on_other_attribute_value' => ['default' => ['o' => ['y' => 'common.dy']], 'specific' => ['u' => ['o' => ['y' => 'common.uy']]]], 'default' => 'common.d'], ['type' => 't', 'o' => 'y'], 'common.dy'],
    'an other-attribute split miss, then the default' => [['based_on_other_attribute_value' => ['specific' => ['t' => ['o' => ['y' => 'common.ty']]]], 'default' => 'common.d'], ['type' => 't', 'o' => 'q'], 'common.d'],
    'an attribute value' => [['based_on_attribute_value' => ['a' => ['y' => 'common.a']], 'default' => 'common.d'], ['type' => 't', 'a' => 'y'], 'common.a'],
    'an attribute miss, then the default' => [['based_on_attribute_value' => ['a' => ['y' => 'common.a']], 'default' => 'common.d'], ['type' => 't', 'a' => 'q'], 'common.d'],
    'an attribute default table' => [['based_on_attribute_value' => ['default' => ['a' => ['y' => 'common.da']]], 'default' => 'common.d'], ['type' => 't', 'a' => 'y'], 'common.da'],
    'an attribute fieldtype table' => [['based_on_attribute_value' => ['default' => ['a' => ['y' => 'common.da']], 'specific' => ['t' => ['a' => ['y' => 'common.ta']]]], 'default' => 'common.d'], ['type' => 't', 'a' => 'y'], 'common.ta'],
    'an attribute split miss, then the default' => [['based_on_attribute_value' => ['default' => ['a' => ['y' => 'common.da']]], 'default' => 'common.d'], ['type' => 't', 'a' => 'q'], 'common.d'],
    'the other attribute before the attribute' => [['based_on_other_attribute_value' => ['o' => ['y' => 'common.o']], 'based_on_attribute_value' => ['a' => ['y' => 'common.a']]], ['type' => 't', 'o' => 'y', 'a' => 'y'], 'common.o'],
    'the attribute after an other-attribute miss' => [['based_on_other_attribute_value' => ['o' => ['y' => 'common.o']], 'based_on_attribute_value' => ['a' => ['y' => 'common.a']]], ['type' => 't', 'o' => 'q', 'a' => 'y'], 'common.a'],
]);

it('resolves no linked field without a mapped type', function (
    array $mappingArray,
    string|array $fieldOrType
): void {
    expect(FieldtypeMapping::getLinkedFieldFromMapping($mappingArray, $fieldOrType))->toBeNull();
})->with([
    'an unknown type' => [['t' => ['linked_field' => ['default' => 'common.d']]], 'u'],
    'an empty type' => [['' => ['linked_field' => ['default' => 'common.d']]], ''],
    'a field with no type' => [['t' => ['linked_field' => ['default' => 'common.d']]], ['label' => 'x']],
    'a field whose type is not a string' => [['t' => ['linked_field' => ['default' => 'common.d']]], ['type' => 5]],
    'a scalar mapping' => [['t' => 'text'], 't'],
    'a mapping with no linked field' => [['t' => ['default' => 'text']], 't'],
]);

it('resolves a conditional string mapping in order', function (
    array $config,
    array $field,
    ?string $expected
): void {
    expect(new ReflectionMethod(FieldtypeMapping::class, 'resolveStringMappingFromConditionalConfig')->invoke(null, $config, $field))->toBe($expected);
})->with([
    'an exact value' => [['o' => ['y' => 'Y']], ['o' => 'y'], 'Y'],
    'an exact integer' => [['o' => [3 => 'THREE']], ['o' => 3], 'THREE'],
    'an exact value that is not a string' => [['o' => ['y' => 5]], ['o' => 'y'], null],
    'not set' => [['o' => ['#not_set' => 'NS']], [], 'NS'],
    'not set, but present as null' => [['o' => ['#not_set' => 'NS']], ['o' => null], null],
    'not empty' => [['o' => ['#not_empty' => 'NE']], ['o' => 'q'], 'NE'],
    'not empty, for zero' => [['o' => ['#not_empty' => 'NE']], ['o' => 0], 'NE'],
    'not empty, but empty' => [['o' => ['#not_empty' => 'NE']], ['o' => ''], null],
    'not empty, but an empty list' => [['o' => ['#not_empty' => 'NE']], ['o' => []], null],
    'not empty, but absent' => [['o' => ['#not_empty' => 'NE']], [], null],
    'an empty value' => [['o' => ['' => 'E']], ['o' => ''], 'E'],
    'an empty key, for another value' => [['o' => ['' => 'E']], ['o' => 'q'], null],
    'an exact value before not empty' => [['o' => ['#not_empty' => 'NE', 'y' => 'Y']], ['o' => 'y'], 'Y'],
    'not set before not empty' => [['o' => ['#not_empty' => 'NE', '#not_set' => 'NS']], [], 'NS'],
    'the second attribute' => [['o' => ['y' => 'O'], 'p' => ['z' => 'P']], ['p' => 'z'], 'P'],
    'an integer attribute name' => [[0 => ['y' => 'I']], [0 => 'y'], 'I'],
    'a list value, unmatched' => [['o' => ['y' => 'Y']], ['o' => ['y']], null],
    'nothing' => [['o' => ['y' => 'Y']], ['o' => 'q'], null],
]);

it('resolves set attributes from the mapping', function (
    array $setAttributes,
    string|array $fieldOrType,
    ?string $siteHandle,
    ?array $expected
): void {
    expect(FieldtypeMapping::getSetAttributesFromMapping(['t' => ['default' => 'D', 'set_attributes' => $setAttributes]], $fieldOrType, $siteHandle))->toBe($expected);
})->with([
    'a default' => [['default' => ['a' => 1]], 't', null, ['a' => 1]],
    'a field key entry' => [['default' => ['a' => 1, 'b' => 1], 'specific' => ['k1' => ['b' => 2]]], ['type' => 't', 'field_key' => 'k1'], null, ['a' => 1, 'b' => 2]],
    'a field id entry' => [['default' => ['a' => 1], 'specific' => ['9' => ['b' => 2]]], ['type' => 't', 'id' => '9'], null, ['a' => 1, 'b' => 2]],
    'a field key before the id' => [['specific' => ['k1' => ['b' => 'key'], '9' => ['b' => 'id']]], ['type' => 't', 'field_key' => 'k1', 'id' => '9'], null, ['b' => 'key']],
    'an integer id, unmatched' => [['default' => ['a' => 1], 'specific' => ['9' => ['b' => 2]]], ['type' => 't', 'id' => 9], null, ['a' => 1]],
    'no entry for the field' => [['default' => ['a' => 1], 'specific' => ['k1' => ['b' => 2]]], ['type' => 't', 'field_key' => 'k2'], null, ['a' => 1]],
    'by type only' => [['default' => ['a' => 1], 'specific' => ['k1' => ['b' => 2]]], 't', null, ['a' => 1]],
    'nothing set' => [[], 't', null, []],
    'a site handle in keys and values' => [['default' => ['folder' => 'forms/[#site_handle]', '[#site_handle]_flag' => true]], 't', 'arch', ['folder' => 'forms/arch', 'arch_flag' => true]],
    'no site handle' => [['default' => ['folder' => 'forms/[#site_handle]']], 't', null, ['folder' => 'forms/[#site_handle]']],
    'an integer key with a site handle' => [['default' => [5 => 'v']], 't', 'arch', [5 => 'v']],
    'an unknown type' => [['default' => ['a' => 1]], 'u', null, null],
    'an empty type' => [['default' => ['a' => 1]], ['type' => ''], null, null],
    'no type' => [['default' => ['a' => 1]], ['label' => 'x'], null, null],
]);

it('resolves no set attributes for a mapping without them', function (): void {
    expect(FieldtypeMapping::getSetAttributesFromMapping(['t' => 'text'], 't'))->toBeNull()
        ->and(FieldtypeMapping::getSetAttributesFromMapping(['t' => ['default' => 'text']], 't'))->toBeNull();
});
