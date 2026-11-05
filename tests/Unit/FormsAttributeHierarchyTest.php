<?php

declare(strict_types=1);

/**
 * Unit tests for how the Forms attribute mapping picks a Statamic attribute (#98).
 *
 * `mapAttributesFromMapping()` resolves an attribute's `statamic_attribute` in
 * a fixed order: `based_on_attribute_value` (specific, then default),
 * `based_on_other_attribute_value` (specific, then default), a `specific`
 * entry for the source fieldtype, `default`, and then three legacy forms (a
 * `specific_based_on_attribute_value` table, and the two `based_on` tables
 * without a `specific`/`default` split). A `*` in the resolved name is
 * replaced with the subfield handle. It also extracts a list from an array of
 * arrays (`extract_array_value`) or from a JSON object's values
 * (`extract_object_values`). A line-coverage run left the legacy forms, the
 * `*` replacement and `extract_object_values` unexecuted.
 *
 * The mappings here are written for each case rather than taken from the
 * shipped tables, so each case shows one rule.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsAttributeHierarchyTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Map attributes through `mapAttributesFromMapping()` on the Gravity Forms mapping class.
 *
 * @param  array<int|string, mixed>  $data  The source attributes
 * @param  array<int|string, mixed>  $mapping  The attribute mapping
 * @param  ?string  $handle  The subfield handle
 * @param  ?string  $fieldtype  The source fieldtype
 * @return mixed The mapped attributes
 */
function mapFormsAttributeHierarchy(
    array $data,
    array $mapping,
    ?string $handle = null,
    ?string $fieldtype = null
): mixed {
    return new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')
        ->invoke(null, $data, $mapping, [], $handle, $fieldtype);
}

it('picks the Statamic attribute in order', function (
    array $data,
    array $statamicAttribute,
    ?string $fieldtype,
    array $expected
): void {
    expect(mapFormsAttributeHierarchy($data, ['a' => ['statamic_attribute' => $statamicAttribute]], null, $fieldtype))->toBe($expected);
})->with([
    'based_on_attribute_value, specific' => [['a' => 'x'], ['based_on_attribute_value' => ['specific' => ['text' => ['x' => 'sx']], 'default' => ['x' => 'dx']]], 'text', ['sx' => 'x']],
    'based_on_attribute_value, default for another fieldtype' => [['a' => 'x'], ['based_on_attribute_value' => ['specific' => ['text' => ['x' => 'sx']], 'default' => ['x' => 'dx']]], 'radio', ['dx' => 'x']],
    'based_on_attribute_value, default' => [['a' => 'x'], ['based_on_attribute_value' => ['default' => ['x' => 'dx']]], null, ['dx' => 'x']],
    'based_on_attribute_value missing, then default' => [['a' => 'q'], ['based_on_attribute_value' => ['default' => ['x' => 'dx']], 'default' => 'plain'], null, ['plain' => 'q']],
    'based_on_other_attribute_value, specific' => [['a' => 'v', 'kind' => 'k1'], ['based_on_other_attribute_value' => ['specific' => ['text' => ['kind' => ['k1' => 'sk1']]], 'default' => ['kind' => ['k1' => 'dk1']]]], 'text', ['sk1' => 'v']],
    'based_on_other_attribute_value, default for another fieldtype' => [['a' => 'v', 'kind' => 'k1'], ['based_on_other_attribute_value' => ['specific' => ['text' => ['kind' => ['k1' => 'sk1']]], 'default' => ['kind' => ['k1' => 'dk1']]]], 'radio', ['dk1' => 'v']],
    'based_on_attribute_value before based_on_other_attribute_value' => [['a' => 'x', 'kind' => 'k1'], ['based_on_attribute_value' => ['default' => ['x' => 'dx']], 'based_on_other_attribute_value' => ['default' => ['kind' => ['k1' => 'dk1']]], 'default' => 'plain'], null, ['dx' => 'x']],
    'specific for the fieldtype' => [['a' => 'x'], ['specific' => ['text' => 'st'], 'default' => 'plain'], 'text', ['st' => 'x']],
    'specific, then default for another fieldtype' => [['a' => 'x'], ['specific' => ['text' => 'st'], 'default' => 'plain'], 'radio', ['plain' => 'x']],
    'specific, then default with no fieldtype' => [['a' => 'x'], ['specific' => ['text' => 'st'], 'default' => 'plain'], null, ['plain' => 'x']],
    'specific, then default with an empty fieldtype' => [['a' => 'x'], ['specific' => ['' => 'st'], 'default' => 'plain'], '', ['plain' => 'x']],
    'default before the legacy forms' => [['a' => 'x'], ['default' => 'plain', 'specific_based_on_attribute_value' => ['x' => 'ax']], null, ['plain' => 'x']],
    'legacy specific_based_on_attribute_value' => [['a' => 'x'], ['specific_based_on_attribute_value' => ['x' => 'ax', 'y' => 'ay']], null, ['ax' => 'x']],
    'legacy specific_based_on_attribute_value with no match' => [['a' => 'z'], ['specific_based_on_attribute_value' => ['x' => 'ax']], null, []],
    'legacy specific_based_on_attribute_value before legacy based_on_attribute_value' => [['a' => 'x'], ['specific_based_on_attribute_value' => ['x' => 'sb'], 'based_on_attribute_value' => ['x' => 'b']], null, ['sb' => 'x']],
    'legacy based_on_attribute_value' => [['a' => 'x'], ['based_on_attribute_value' => ['x' => 'ax']], null, ['ax' => 'x']],
    'legacy based_on_attribute_value with no match' => [['a' => 'q'], ['based_on_attribute_value' => ['x' => 'ax']], null, []],
    'legacy based_on_attribute_value before legacy based_on_other_attribute_value' => [['a' => 'x', 'kind' => 'k1'], ['based_on_attribute_value' => ['x' => 'b'], 'based_on_other_attribute_value' => ['kind' => ['k1' => 'o']]], null, ['b' => 'x']],
    'legacy based_on_other_attribute_value' => [['a' => 'v', 'kind' => 'k1'], ['based_on_other_attribute_value' => ['kind' => ['k1' => 'ak1']]], null, ['ak1' => 'v']],
    'legacy based_on_other_attribute_value with no match' => [['a' => 'v', 'kind' => 'k9'], ['based_on_other_attribute_value' => ['kind' => ['k1' => 'ak1']]], null, []],
]);

it('maps nothing for an attribute with no Statamic attribute', function (
    array $config,
    ?string $fieldtype
): void {
    expect(mapFormsAttributeHierarchy(['a' => 'x'], ['a' => $config], null, $fieldtype))->toBe([]);
})->with([
    'an empty attribute' => [['statamic_attribute' => ''], null],
    'a null attribute' => [['statamic_attribute' => null], null],
    'no mapping keys' => [['other' => 'y'], null],
    'an excluded fieldtype' => [['statamic_attribute' => 'aa', 'exclude_fieldtypes' => ['text']], 'text'],
]);

it('maps only the attributes the mapping names', function (): void {
    expect(mapFormsAttributeHierarchy(['a' => 1, 'b' => 2, 'c' => 3], ['b' => ['statamic_attribute' => 'bb']]))->toBe(['bb' => 2]);
});

it('replaces a star in the Statamic attribute with the subfield handle', function (
    ?string $handle,
    array $expected
): void {
    expect(mapFormsAttributeHierarchy(['a' => 'v'], ['a' => ['statamic_attribute' => '*_label']], $handle))->toBe($expected);
})->with([
    'a handle' => ['city', ['city_label' => 'v']],
    'no handle' => [null, ['*_label' => 'v']],
    'an empty handle' => ['', ['*_label' => 'v']],
]);

it('extracts one key from each item of an array', function (): void {
    expect(mapFormsAttributeHierarchy(['a' => [['text' => 'one'], ['text' => 'two'], ['no' => 1], 'flat']], ['a' => ['statamic_attribute' => 'opts', 'extract_array_value' => 'text']]))
        ->toBe(['opts' => ['one', 'two']]);
});

it('extracts the values of a JSON object as a list', function (
    mixed $value,
    array $config,
    array $expected
): void {
    expect(mapFormsAttributeHierarchy(['a' => $value], ['a' => ['statamic_attribute' => 'mime'] + $config]))->toBe($expected);
})->with([
    'distinct non-empty strings, in order' => [['jpg' => 'image/jpeg', 'png' => 'image/png', 'jpe' => 'image/jpeg', 'n' => 5, 'e' => '', 'x' => ['y'], 'z' => null], ['extract_object_values' => true], ['mime' => ['image/jpeg', 'image/png']]],
    'translated first' => [['gz' => 'application/x-gzip', 'tgz' => 'application/gzip'], ['extract_object_values' => true, 'value_translation' => ['default' => ['application/x-gzip' => 'application/gzip']]], ['mime' => ['application/gzip']]],
    'nothing left' => [['n' => 5, 'e' => ''], ['extract_object_values' => true], []],
    'a flag that is not true' => [['jpg' => 'image/jpeg'], ['extract_object_values' => 1], ['mime' => ['jpg' => 'image/jpeg']]],
    'a value that is not an object' => ['image/jpeg', ['extract_object_values' => true], ['mime' => 'image/jpeg']],
]);
