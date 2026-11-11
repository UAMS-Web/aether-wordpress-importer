<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms mapping of attributes the field data lacks (#98).
 *
 * After `mapAttributesFromMapping()` has mapped the attributes the data
 * carries, it visits the mapping again for the ones the data lacks. One pass
 * writes a `value_translation` list entry whose search is `#not_set`. When
 * the original field data is given, a second pass writes the `#not_set`
 * entries of `value_translation`, `statamic_other_field_value_based_on_attribute_value`,
 * `statamic_attribute.specific_based_on_attribute_value`,
 * `statamic_type_based_on_attribute_value` and `value_calculation` for an
 * attribute the original field's options do not set. A line-coverage run left
 * most of this unexecuted.
 *
 * A `#not_set` entry of a plain `value_translation` table is written once,
 * by the pass over the original field data, with the subfield handle,
 * `deduplicate_values` and `value_transformations` applied (#458). The later
 * pass over missing attributes leaves that write in place, and an empty target
 * writes nothing in either pass.
 *
 * The mappings here are written for each case rather than taken from the
 * shipped tables, so each case shows one rule, except for the cases that map
 * through the two shipped tables with a `#not_set` translation.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsMissingAttributeTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping as FormidableFormsFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Original field data whose options set nothing.
 */
const FORMS_MA_UNSET = ['field_options' => '{}'];

/**
 * Original field data whose options set `n`.
 */
const FORMS_MA_SET = ['field_options' => '{"n":1}'];

/**
 * Map an attribute `n` that the data lacks through `mapAttributesFromMapping()`.
 *
 * @param  array<int|string, mixed>  $config  The attribute's mapping
 * @param  ?string  $fieldtype  The source fieldtype
 * @param  ?array<int|string, mixed>  $original  The original field data
 * @param  array<int|string, mixed>  $data  The field data, without `n`
 * @param  ?string  $handle  The subfield handle
 * @return mixed The mapped attributes
 */
function mapFormsMissingAttribute(
    array $config,
    ?string $fieldtype = null,
    ?array $original = null,
    array $data = [],
    ?string $handle = null
): mixed {
    return new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')
        ->invoke(null, $data, ['n' => $config], [], $handle, $fieldtype, $original);
}

it('writes a #not_set search entry for a missing attribute', function (
    array $config,
    ?string $fieldtype,
    array $data,
    array $expected
): void {
    expect(mapFormsMissingAttribute($config, $fieldtype, null, $data))->toBe($expected);
})->with([
    'the first #not_set entry' => [['statamic_attribute' => 'a', 'value_translation' => [['search' => '#not_set', 'replace' => 'R'], ['search' => '#not_set', 'replace' => 'S']]], null, [], ['a' => 'R']],
    'after another entry' => [['statamic_attribute' => 'a', 'value_translation' => [['search' => 'x', 'replace' => 'X'], ['search' => '#not_set', 'replace' => 'R']]], null, [], ['a' => 'R']],
    'no #not_set entry' => [['statamic_attribute' => 'a', 'value_translation' => [['search' => 'x', 'replace' => 'X']]], null, [], []],
    'a default list' => [['statamic_attribute' => 'a', 'value_translation' => ['default' => [['search' => '#not_set', 'replace' => 'D']]]], null, [], ['a' => 'D']],
    'a list for the fieldtype' => [['statamic_attribute' => 'a', 'value_translation' => ['default' => [], 'specific' => ['radio' => [['search' => '#not_set', 'replace' => 'P']]]]], 'radio', [], ['a' => 'P']],
    'only a list for the fieldtype' => [['statamic_attribute' => 'a', 'value_translation' => ['specific' => ['radio' => [['search' => '#not_set', 'replace' => 'P']]]]], 'radio', [], ['a' => 'P']],
    'only a list for another fieldtype' => [['statamic_attribute' => 'a', 'value_translation' => ['specific' => ['radio' => [['search' => '#not_set', 'replace' => 'P']]]]], 'select', [], []],
    'the default target' => [['statamic_attribute' => ['default' => 'b'], 'value_translation' => [['search' => '#not_set', 'replace' => 'R']]], null, [], ['b' => 'R']],
    'the target for the fieldtype' => [['statamic_attribute' => ['default' => 'b', 'specific' => ['radio' => 'c']], 'value_translation' => [['search' => '#not_set', 'replace' => 'R']]], 'radio', [], ['c' => 'R']],
    'no target for the fieldtype' => [['statamic_attribute' => ['specific' => ['radio' => 'c']], 'value_translation' => [['search' => '#not_set', 'replace' => 'R']]], 'select', [], []],
    'an empty target' => [['statamic_attribute' => '', 'value_translation' => [['search' => '#not_set', 'replace' => 'R']]], null, [], []],
    'an excluded fieldtype' => [['statamic_attribute' => 'a', 'exclude_fieldtypes' => ['radio'], 'value_translation' => [['search' => '#not_set', 'replace' => 'R']]], 'radio', [], []],
    'an attribute the data carries' => [['statamic_attribute' => 'a', 'value_translation' => [['search' => '#not_set', 'replace' => 'R']]], null, ['n' => 'v'], ['a' => 'v']],
]);

it('writes a #not_set translation for an option the original field does not set', function (
    array $config,
    ?array $original,
    ?string $handle,
    array $data,
    array $expected
): void {
    expect(mapFormsMissingAttribute($config, null, $original, $data, $handle))->toBe($expected);
})->with([
    'an unset option' => [['statamic_attribute' => 'a', 'value_translation' => ['#not_set' => 'M']], FORMS_MA_UNSET, null, [], ['a' => 'M']],
    'a set option' => [['statamic_attribute' => 'a', 'value_translation' => ['#not_set' => 'M']], FORMS_MA_SET, null, [], []],
    'an empty translation' => [['statamic_attribute' => 'x_*', 'value_translation' => ['#not_set' => '']], FORMS_MA_UNSET, 'hd', [], []],
    'a handle' => [['statamic_attribute' => 'x_*', 'value_translation' => ['#not_set' => 'M']], FORMS_MA_UNSET, 'hd', [], ['x_hd' => 'M']],
    'no handle' => [['statamic_attribute' => 'x_*', 'value_translation' => ['#not_set' => 'M']], FORMS_MA_UNSET, null, [], ['x_*' => 'M']],
    'an empty handle' => [['statamic_attribute' => 'x_*', 'value_translation' => ['#not_set' => 'M']], FORMS_MA_UNSET, '', [], ['x_*' => 'M']],
    'a default target' => [['statamic_attribute' => ['default' => 'x_*'], 'value_translation' => ['#not_set' => 'M']], FORMS_MA_UNSET, 'hd', [], ['x_hd' => 'M']],
    'an empty target' => [['statamic_attribute' => '', 'value_translation' => ['#not_set' => 'M']], FORMS_MA_UNSET, 'hd', [], []],
    'deduplicated' => [['statamic_attribute' => 'x_*', 'deduplicate_values' => true, 'value_translation' => ['#not_set' => ['x', 'x', 'y']]], FORMS_MA_UNSET, 'hd', [], ['x_hd' => ['x', 'y']]],
    'deduplicate not true' => [['statamic_attribute' => 'x_*', 'deduplicate_values' => 1, 'value_translation' => ['#not_set' => ['x', 'x', 'y']]], FORMS_MA_UNSET, 'hd', [], ['x_hd' => ['x', 'x', 'y']]],
    'deduplicating a string' => [['statamic_attribute' => 'x_*', 'deduplicate_values' => true, 'value_translation' => ['#not_set' => 'x']], FORMS_MA_UNSET, 'hd', [], ['x_hd' => 'x']],
    'deduplicated without a handle' => [['statamic_attribute' => 'a', 'deduplicate_values' => true, 'value_translation' => ['#not_set' => ['x', 'x', 'y']]], FORMS_MA_UNSET, null, [], ['a' => ['x', 'y']]],
    'transformed' => [['statamic_attribute' => 'x_*', 'value_transformations' => ['based_on_attribute_value' => ['#not_empty' => ['as_numeric_string' => true]]], 'value_translation' => ['#not_set' => 5]], FORMS_MA_UNSET, 'hd', ['o' => 'v'], ['x_hd' => '5']],
    'transformed by a nested table' => [['statamic_attribute' => 'x_*', 'value_transformations' => ['based_on_attribute_value' => ['#not_empty' => ['based_on_attribute_value' => ['as_numeric_string' => true]]]], 'value_translation' => ['#not_set' => 5]], FORMS_MA_UNSET, 'hd', ['o' => 'v'], ['x_hd' => '5']],
    'transformed without a handle' => [['statamic_attribute' => 'a', 'value_transformations' => ['based_on_attribute_value' => ['#not_empty' => ['as_numeric_string' => true]]], 'value_translation' => ['#not_set' => 5]], FORMS_MA_UNSET, null, ['o' => 'v'], ['a' => '5']],
    'deduplicated then transformed' => [['statamic_attribute' => 'x_*', 'deduplicate_values' => true, 'value_transformations' => ['based_on_attribute_value' => ['#not_empty' => ['as_numeric_string' => true]]], 'value_translation' => ['#not_set' => [1, 1, 2]]], FORMS_MA_UNSET, 'hd', ['o' => 'v'], ['x_hd' => ['1', '2']]],
    'no transformation for empty data' => [['statamic_attribute' => 'x_*', 'value_transformations' => ['based_on_attribute_value' => ['#not_empty' => ['as_numeric_string' => true]]], 'value_translation' => ['#not_set' => 5]], FORMS_MA_UNSET, 'hd', ['o' => ''], ['x_hd' => 5]],
    'no transformation without data' => [['statamic_attribute' => 'x_*', 'value_transformations' => ['based_on_attribute_value' => ['#not_empty' => ['as_numeric_string' => true]]], 'value_translation' => ['#not_set' => 5]], FORMS_MA_UNSET, 'hd', [], ['x_hd' => 5]],
    'a transformation that is not a table' => [['statamic_attribute' => 'x_*', 'value_transformations' => 'x', 'value_translation' => ['#not_set' => 5]], FORMS_MA_UNSET, 'hd', ['o' => 'v'], ['x_hd' => 5]],
    'a default transformation only' => [['statamic_attribute' => 'x_*', 'value_transformations' => ['default' => ['as_numeric_string' => true]], 'value_translation' => ['#not_set' => 5]], FORMS_MA_UNSET, 'hd', ['o' => 'v'], ['x_hd' => 5]],
    'nothing to map to' => [['value_translation' => ['#not_set' => 'M']], FORMS_MA_UNSET, 'hd', [], []],
    'only other field values' => [['statamic_other_field_value' => ['f' => 1]], FORMS_MA_UNSET, 'hd', [], []],
]);

it('skips an excluded fieldtype when the original field is given', function (): void {
    $config = ['statamic_attribute' => 'x_*', 'exclude_fieldtypes' => ['radio'], 'value_translation' => ['#not_set' => 'M']];

    expect(mapFormsMissingAttribute($config, 'radio', FORMS_MA_UNSET, [], 'hd'))->toBe([])
        ->and(mapFormsMissingAttribute($config, 'select', FORMS_MA_UNSET, [], 'hd'))->toBe(['x_hd' => 'M']);
});

it('writes nothing for an empty target', function (
    array $translation,
    ?array $original
): void {
    expect(mapFormsMissingAttribute(['statamic_attribute' => '', 'value_translation' => $translation], null, $original))->toBe([])
        ->and(mapFormsMissingAttribute(['statamic_attribute' => 'a', 'value_translation' => $translation], null, $original))->not->toBe([]);
})->with([
    'a plain table, no original field' => [['#not_set' => 'M'], null],
    'a default table' => [['default' => ['#not_set' => 'D']], FORMS_MA_UNSET],
    'a default table, no original field' => [['default' => ['#not_set' => 'D']], null],
]);

/*
 * The shipped tables with a `#not_set` translation are search-and-replace
 * lists, which the pass over the original field data does not read, so a
 * missing attribute maps through them as it did before #458.
 */
it('maps a missing attribute through the shipped #not_set translations', function (
    string $class,
    string $attribute,
    mixed $config,
    string $fieldtype,
    ?array $original,
    ?string $handle,
    array $expected
): void {
    expect(new ReflectionMethod($class, 'mapAttributesFromMapping')->invoke(null, [], [$attribute => $config], [], $handle, $fieldtype, $original))->toBe($expected);
})->with([
    'Gravity Forms rcwdupload_repeater' => [FieldtypeMapping::class, 'rcwdupload_repeater', FieldtypeMapping::getFieldAttributeMapping()['rcwdupload_repeater'], 'rcwdupload', FORMS_MA_UNSET, null, ['max_files' => 1]],
    'Gravity Forms rcwdupload_repeater, a handle' => [FieldtypeMapping::class, 'rcwdupload_repeater', FieldtypeMapping::getFieldAttributeMapping()['rcwdupload_repeater'], 'rcwdupload', FORMS_MA_UNSET, 'hd', ['max_files' => 1]],
    'Gravity Forms rcwdupload_repeater, no original field' => [FieldtypeMapping::class, 'rcwdupload_repeater', FieldtypeMapping::getFieldAttributeMapping()['rcwdupload_repeater'], 'rcwdupload', null, null, ['max_files' => 1]],
    'Formidable Forms address_type' => [FormidableFormsFieldtypeMapping::class, 'address_type', FormidableFormsFieldtypeMapping::getFieldOptionsMapping()['address_type'], 'address', FORMS_MA_UNSET, null, ['preset' => 'international']],
    'Formidable Forms address_type, a handle' => [FormidableFormsFieldtypeMapping::class, 'address_type', FormidableFormsFieldtypeMapping::getFieldOptionsMapping()['address_type'], 'address', FORMS_MA_UNSET, 'hd', ['preset' => 'international']],
    'Formidable Forms address_type, no original field' => [FormidableFormsFieldtypeMapping::class, 'address_type', FormidableFormsFieldtypeMapping::getFieldOptionsMapping()['address_type'], 'address', null, null, ['preset' => 'international']],
]);

it('writes #not_set other field values for an option the original field does not set', function (
    array $table,
    ?string $fieldtype,
    ?array $original,
    array $expected
): void {
    expect(mapFormsMissingAttribute(['statamic_other_field_value_based_on_attribute_value' => $table], $fieldtype, $original))->toBe($expected);
})->with([
    'by default' => [['default' => ['#not_set' => ['f' => 1]]], null, FORMS_MA_UNSET, ['f' => 1]],
    'by default, a set option' => [['default' => ['#not_set' => ['f' => 1]]], null, FORMS_MA_SET, []],
    'for the fieldtype' => [['default' => [], 'specific' => ['radio' => ['#not_set' => ['g' => 2]]]], 'radio', FORMS_MA_UNSET, ['g' => 2]],
    'for the fieldtype over the default' => [['default' => ['#not_set' => ['f' => 1, 'h' => 0]], 'specific' => ['radio' => ['#not_set' => ['f' => 2]]]], 'radio', FORMS_MA_UNSET, ['f' => 2, 'h' => 0]],
    'for the fieldtype, a set option' => [['default' => [], 'specific' => ['radio' => ['#not_set' => ['g' => 2]]]], 'radio', FORMS_MA_SET, []],
    'for another fieldtype' => [['default' => [], 'specific' => ['radio' => ['#not_set' => ['g' => 2]]]], 'select', FORMS_MA_UNSET, []],
    'for an empty fieldtype' => [['default' => [], 'specific' => ['' => ['#not_set' => ['g' => 2]]]], '', FORMS_MA_UNSET, []],
    'with no fieldtype' => [['default' => [], 'specific' => ['' => ['#not_set' => ['g' => 2]]]], null, FORMS_MA_UNSET, []],
    'unsplit' => [['#not_set' => ['h' => 3]], null, FORMS_MA_UNSET, ['h' => 3]],
    'unsplit, a set option' => [['#not_set' => ['h' => 3]], null, FORMS_MA_SET, []],
    'unsplit, no #not_set' => [['x' => ['h' => 3]], null, FORMS_MA_UNSET, []],
    'with no original field' => [['#not_set' => ['h' => 3]], null, null, []],
]);

it('writes a #not_set target for the fieldtype as an empty attribute', function (
    array $table,
    ?string $fieldtype,
    ?array $original,
    array $expected
): void {
    expect(mapFormsMissingAttribute(['statamic_attribute' => ['specific_based_on_attribute_value' => $table]], $fieldtype, $original))->toBe($expected);
})->with([
    'a plain target' => [['radio' => ['#not_set' => 't']], 'radio', FORMS_MA_UNSET, ['t' => '']],
    'a target with a star' => [['radio' => ['#not_set' => 't_*']], 'radio', FORMS_MA_UNSET, ['t_*' => '']],
    'a set option' => [['radio' => ['#not_set' => 't']], 'radio', FORMS_MA_SET, []],
    'another fieldtype' => [['radio' => ['#not_set' => 't']], 'select', FORMS_MA_UNSET, []],
    'an empty fieldtype' => [['' => ['#not_set' => 't']], '', FORMS_MA_UNSET, []],
    'no #not_set entry' => [['radio' => ['x' => 't']], 'radio', FORMS_MA_UNSET, []],
]);

it('sets the field type for an option the original field does not set', function (): void {
    $config = ['statamic_type_based_on_attribute_value' => ['#not_set' => 'ns']];

    expect(mapFormsMissingAttribute($config, null, FORMS_MA_UNSET))->toBe(['type' => 'ns'])
        ->and(mapFormsMissingAttribute($config, null, FORMS_MA_SET))->toBe([])
        ->and(mapFormsMissingAttribute(['statamic_type_based_on_attribute_value' => ['x' => 'ns']], null, FORMS_MA_UNSET))->toBe([]);
});

it('calculates from zero for an option the original field does not set', function (
    array $config,
    ?string $fieldtype,
    ?array $original,
    array $expected
): void {
    expect(mapFormsMissingAttribute($config, $fieldtype, $original))->toBe($expected);
})->with([
    'the default' => [['statamic_attribute' => 'a', 'value_calculation' => ['default' => '[value] + 3']], null, FORMS_MA_UNSET, ['a' => 3]],
    'the default, a set option' => [['statamic_attribute' => 'a', 'value_calculation' => ['default' => '[value] + 3']], null, FORMS_MA_SET, []],
    'a result of zero' => [['statamic_attribute' => 'a', 'value_calculation' => ['default' => '[value] * 3']], null, FORMS_MA_UNSET, []],
    'a negative result' => [['statamic_attribute' => 'a', 'value_calculation' => ['default' => '[value] - 3']], null, FORMS_MA_UNSET, ['a' => -3]],
    'an expression that is not a string' => [['statamic_attribute' => 'a', 'value_calculation' => ['default' => 3]], null, FORMS_MA_UNSET, []],
    'a calculation that is not a table' => [['statamic_attribute' => 'a', 'value_calculation' => '[value] + 3'], null, FORMS_MA_UNSET, []],
    'no target' => [['value_calculation' => ['default' => '[value] + 3']], null, FORMS_MA_UNSET, []],
    'a default target' => [['statamic_attribute' => ['default' => 'a'], 'value_calculation' => ['default' => '[value] + 3']], null, FORMS_MA_UNSET, ['a' => 3]],
    'for the fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['specific_based_on_attribute_value' => ['radio' => ['#not_set' => '[value] + 4']], 'default' => '[value] + 3']], 'radio', FORMS_MA_UNSET, ['a' => 4]],
    'for the fieldtype, a set option' => [['statamic_attribute' => 'a', 'value_calculation' => ['specific_based_on_attribute_value' => ['radio' => ['#not_set' => '[value] + 4']]]], 'radio', FORMS_MA_SET, []],
    'for another fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['specific_based_on_attribute_value' => ['radio' => ['#not_set' => '[value] + 4']], 'default' => '[value] + 3']], 'select', FORMS_MA_UNSET, ['a' => 3]],
    'for an empty fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['specific_based_on_attribute_value' => ['' => ['#not_set' => '[value] + 4']], 'default' => '[value] + 3']], '', FORMS_MA_UNSET, ['a' => 3]],
    'for no fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['specific_based_on_attribute_value' => ['' => ['#not_set' => '[value] + 4']], 'default' => '[value] + 3']], null, FORMS_MA_UNSET, ['a' => 3]],
    'for the fieldtype, no #not_set' => [['statamic_attribute' => 'a', 'value_calculation' => ['specific_based_on_attribute_value' => ['radio' => ['x' => '[value] + 4']], 'default' => '[value] + 3']], 'radio', FORMS_MA_UNSET, ['a' => 3]],
    'by another attribute, by default' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['default' => ['o' => ['#not_set' => '[value] + 5']]], 'default' => '[value] + 3']], null, FORMS_MA_UNSET, ['a' => 5]],
    'by another attribute the original field sets' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['default' => ['o' => ['#not_set' => '[value] + 5']]], 'default' => '[value] + 3']], null, FORMS_MA_UNSET + ['o' => 'x'], ['a' => 3]],
    'by another attribute, a set option' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['default' => ['o' => ['#not_set' => '[value] + 5']]]]], null, FORMS_MA_SET, []],
    'by another attribute, the first match' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['default' => ['o' => ['#not_set' => '[value] + 5'], 'p' => ['#not_set' => '[value] + 8']]]]], null, FORMS_MA_UNSET, ['a' => 5]],
    'by another attribute, no #not_set' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['default' => ['o' => ['x' => '[value] + 5']]], 'default' => '[value] + 3']], null, FORMS_MA_UNSET, ['a' => 3]],
    'by another attribute, for the fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['specific' => ['radio' => ['o' => ['#not_set' => '[value] + 6']]]]]], 'radio', FORMS_MA_UNSET, ['a' => 6]],
    'by another attribute, the default over the fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['default' => ['o' => ['#not_set' => '[value] + 5']], 'specific' => ['radio' => ['o' => ['#not_set' => '[value] + 6']]]]]], 'radio', FORMS_MA_UNSET, ['a' => 5]],
    'by another attribute, the first match for the fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['specific' => ['radio' => ['o' => ['#not_set' => '[value] + 6'], 'p' => ['#not_set' => '[value] + 9']]]]]], 'radio', FORMS_MA_UNSET, ['a' => 6]],
    'by another attribute, set for the fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['specific' => ['radio' => ['o' => ['#not_set' => '[value] + 6']]]]]], 'radio', FORMS_MA_UNSET + ['o' => 'x'], []],
    'by another attribute, a set option for the fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['specific' => ['radio' => ['o' => ['#not_set' => '[value] + 6']]]]]], 'radio', FORMS_MA_SET, []],
    'by another attribute, for another fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['specific' => ['radio' => ['o' => ['#not_set' => '[value] + 6']]]]]], 'select', FORMS_MA_UNSET, []],
    'by another attribute, for an empty fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['specific' => ['' => ['o' => ['#not_set' => '[value] + 6']]]]]], '', FORMS_MA_UNSET, []],
    'by another attribute, for no fieldtype' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['specific' => ['' => ['o' => ['#not_set' => '[value] + 6']]]]]], null, FORMS_MA_UNSET, []],
    'by another attribute, unsplit' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['o' => ['#not_set' => '[value] + 7']]]], null, FORMS_MA_UNSET, ['a' => 7]],
    'by another attribute, unsplit, the first match' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['o' => ['#not_set' => '[value] + 7'], 'p' => ['#not_set' => '[value] + 10']]]], null, FORMS_MA_UNSET, ['a' => 7]],
    'by another attribute, unsplit, a set option' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['o' => ['#not_set' => '[value] + 7']]]], null, FORMS_MA_SET, []],
    'by another attribute, unsplit, set' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['o' => ['#not_set' => '[value] + 7']], 'default' => '[value] + 3']], null, FORMS_MA_UNSET + ['o' => 'x'], ['a' => 3]],
    'by another attribute, unsplit, no #not_set' => [['statamic_attribute' => 'a', 'value_calculation' => ['based_on_other_attribute_value' => ['o' => ['x' => '[value] + 7']]]], null, FORMS_MA_UNSET, []],
    'for the fieldtype over another attribute' => [['statamic_attribute' => 'a', 'value_calculation' => ['specific_based_on_attribute_value' => ['radio' => ['#not_set' => '[value] + 4']], 'based_on_other_attribute_value' => ['o' => ['#not_set' => '[value] + 7']]]], 'radio', FORMS_MA_UNSET, ['a' => 4]],
]);
