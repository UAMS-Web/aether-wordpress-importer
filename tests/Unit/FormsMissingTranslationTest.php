<?php

declare(strict_types=1);

/**
 * Unit tests for the last Forms pass over attributes the field data lacks (#98).
 *
 * The final pass of `mapAttributesFromMapping()` visits each mapped attribute
 * the data lacks whose `value_translation` holds a `#not_set`, `#not_empty`
 * or `#empty` entry, plainly, by `default` or fieldtype, or under
 * `based_on_other_attribute_value`. It picks the table that applies, and each
 * special entry of that table that matches (`#not_set` against the original
 * field or the data, `#not_empty` and `#empty` against both, and a `null`
 * entry always) has the attribute mapped again with no value. A string picked
 * from `based_on_other_attribute_value` is written as it stands. Then
 * attributes left empty are dropped. A line-coverage run left most of this
 * unexecuted.
 *
 * Mapping again translates `null` through the whole `value_translation`
 * without the original field, so a `#not_set` entry always matches there: the
 * data lacks the attribute. The cases that show which entry opened the pass
 * therefore carry a `#not_set` entry as well, and read `NS` when the pass ran.
 *
 * The mappings here are written for each case rather than taken from the
 * shipped tables, so each case shows one rule.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsMissingTranslationTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Original field data whose options set nothing.
 */
const FORMS_MT_UNSET = ['field_options' => '{}'];

/**
 * Original field data whose options set `n`.
 */
const FORMS_MT_SET = ['field_options' => '{"n":1}'];

/**
 * Original field data whose options set `n` empty.
 */
const FORMS_MT_EMPTY = ['field_options' => '{"n":""}'];

/**
 * Map an attribute `n` that the data lacks through its `value_translation`.
 *
 * @param  array<int|string, mixed>  $translation  The attribute's `value_translation`
 * @param  array<int|string, mixed>  $data  The field data, without `n`
 * @param  ?string  $fieldtype  The source fieldtype
 * @param  ?array<int|string, mixed>  $original  The original field data
 * @param  mixed  $target  The attribute's `statamic_attribute`
 * @return mixed The mapped attributes
 */
function mapFormsMissingTranslation(
    array $translation,
    array $data = [],
    ?string $fieldtype = null,
    ?array $original = null,
    mixed $target = 'a'
): mixed {
    return new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')
        ->invoke(null, $data, ['n' => ['statamic_attribute' => $target, 'value_translation' => $translation]], [], null, $fieldtype, $original);
}

it('maps a missing attribute again for each special entry that matches', function (
    array $translation,
    ?array $original,
    array $expected
): void {
    expect(mapFormsMissingTranslation($translation, [], null, $original))->toBe($expected);
})->with([
    '#not_set, an unset option' => [['#not_set' => 'NS'], FORMS_MT_UNSET, ['a' => 'NS']],
    '#not_set, a set option' => [['#not_set' => 'NS'], FORMS_MT_SET, []],
    '#not_set, no original field' => [['#not_set' => 'NS'], null, ['a' => 'NS']],
    '#not_set, a list value' => [['#not_set' => ['p', 'q']], null, ['a' => ['p', 'q']]],
    '#not_set, a null value' => [['#not_set' => null], null, []],
    '#not_empty, a set option' => [['#not_empty' => 'NE', '#not_set' => 'NS'], FORMS_MT_SET, ['a' => 'NS']],
    '#not_empty, an empty option' => [['#not_empty' => 'NE', '#not_set' => 'NS'], FORMS_MT_EMPTY, []],
    '#not_empty, an unset option' => [['#not_empty' => 'NE', '#not_set' => 'NS'], FORMS_MT_UNSET, ['a' => 'NS']],
    '#not_empty alone' => [['#not_empty' => 'NE'], null, []],
    '#empty, an empty option' => [['#empty' => 'E', '#not_set' => 'NS'], FORMS_MT_EMPTY, ['a' => 'NS']],
    '#empty, a set option' => [['#empty' => 'E', '#not_set' => 'NS'], FORMS_MT_SET, []],
    '#empty alone' => [['#empty' => 'E'], null, []],
    'null, a set option' => [['null' => 'N', '#not_set' => 'NS'], FORMS_MT_SET, ['a' => 'NS']],
    'another entry, a set option' => [['x' => 'N', '#not_set' => 'NS'], FORMS_MT_SET, []],
    'no special entry' => [['x' => 'X', 'null' => 'N'], null, []],
]);

it('picks the default or fieldtype table', function (
    array $translation,
    ?string $fieldtype,
    ?array $original,
    array $expected
): void {
    expect(mapFormsMissingTranslation($translation, [], $fieldtype, $original))->toBe($expected);
})->with([
    'the default' => [['default' => ['#not_set' => 'D']], null, null, ['a' => 'D']],
    'the default, #not_empty' => [['default' => ['#not_empty' => 'D', '#not_set' => 'DS']], null, FORMS_MT_SET, ['a' => 'DS']],
    'the default, no special entry' => [['default' => ['x' => 'D']], null, null, []],
    'the fieldtype' => [['default' => [], 'specific' => ['radio' => ['#not_set' => 'P']]], 'radio', null, ['a' => 'P']],
    'the fieldtype, #not_empty' => [['default' => [], 'specific' => ['radio' => ['#not_empty' => 'P', '#not_set' => 'PS']]], 'radio', FORMS_MT_SET, ['a' => 'PS']],
    'the fieldtype, no special entry' => [['default' => [], 'specific' => ['radio' => ['x' => 'P']]], 'radio', null, []],
    'the fieldtype over the default' => [['default' => ['#not_set' => 'D'], 'specific' => ['radio' => ['#not_set' => 'P']]], 'radio', null, ['a' => 'P']],
    'another fieldtype' => [['default' => ['#not_set' => 'D'], 'specific' => ['radio' => ['#not_set' => 'P']]], 'select', null, ['a' => 'D']],
    'an empty fieldtype' => [['default' => ['#not_set' => 'D'], 'specific' => ['' => ['#not_set' => 'P']]], '', null, ['a' => 'D']],
    'only the fieldtype' => [['specific' => ['radio' => ['#not_set' => 'P']]], 'radio', null, ['a' => 'P']],
    'a string for the fieldtype' => [['specific' => ['radio' => 'S']], 'radio', null, []],
]);

it('picks a table by another attribute', function (
    array $table,
    array $data,
    ?array $original,
    array $expected
): void {
    expect(mapFormsMissingTranslation(['based_on_other_attribute_value' => $table], $data, null, $original))->toBe($expected);
})->with([
    'another attribute not set' => [['o' => ['#not_set' => ['#not_set' => 'B']]], [], null, ['a' => 'B']],
    'another attribute set' => [['o' => ['#not_set' => ['#not_set' => 'B']]], ['o' => 'v'], null, []],
    'another attribute not empty' => [['o' => ['#not_empty' => ['#not_set' => 'B']]], ['o' => 'v'], null, ['a' => 'B']],
    'another attribute empty, for #not_empty' => [['o' => ['#not_empty' => ['#not_set' => 'B']]], ['o' => ''], null, []],
    'another attribute empty' => [['o' => ['#empty' => ['#not_set' => 'B']]], ['o' => ''], null, ['a' => 'B']],
    'another attribute full, for #empty' => [['o' => ['#empty' => ['#not_set' => 'B']]], ['o' => 'v'], null, []],
    'an exact value after a special entry' => [['p' => ['#not_set' => ['#not_set' => 'C']], 'o' => ['v' => ['#not_set' => 'B']]], ['o' => 'v', 'p' => 'w'], null, ['a' => 'B']],
    'no exact value after a special entry' => [['p' => ['#not_set' => ['#not_set' => 'C']], 'o' => ['z' => ['#not_set' => 'B']]], ['o' => 'v', 'p' => 'w'], null, []],
    'the first attribute that matches' => [['o' => ['#not_set' => ['#not_set' => 'B']], 'p' => ['#not_set' => ['#not_set' => 'C']]], [], null, ['a' => 'B']],
    'only exact values' => [['o' => ['v' => ['#not_set' => 'B']]], ['o' => 'v'], null, []],
    '#not_set for this attribute, an unset option' => [['o' => ['#not_empty' => ['#not_set' => 'B']]], ['o' => 'v'], FORMS_MT_UNSET, ['a' => 'B']],
    '#not_set for this attribute, a set option' => [['o' => ['#not_empty' => ['#not_set' => 'B']]], ['o' => 'v'], FORMS_MT_SET, []],
    '#not_empty for this attribute, a set option' => [['o' => ['#not_empty' => ['#not_empty' => 'B']]], ['o' => 'v'], FORMS_MT_SET, ['a' => 'B']],
    '#not_empty for this attribute, an unset option' => [['o' => ['#not_empty' => ['#not_empty' => 'B']]], ['o' => 'v'], FORMS_MT_UNSET, []],
    '#empty for this attribute, an empty option' => [['o' => ['#not_empty' => ['#empty' => 'B']]], ['o' => 'v'], FORMS_MT_EMPTY, ['a' => 'B']],
    '#empty for this attribute, a set option' => [['o' => ['#not_empty' => ['#empty' => 'B']]], ['o' => 'v'], FORMS_MT_SET, []],
    'an empty key for this attribute' => [['o' => ['#not_empty' => ['' => 'B']]], ['o' => 'v'], null, ['a' => 'B']],
    'another key for this attribute' => [['o' => ['#not_empty' => ['x' => 'B']]], ['o' => 'v'], null, []],
    'a table for this attribute' => [['o' => ['#not_empty' => ['#not_set' => ['#not_set' => 'BB']]]], ['o' => 'v'], null, ['a' => ['#not_set' => 'BB']]],
]);

it('prefers a table by another attribute and does not fall back from one', function (): void {
    $table = ['o' => ['#not_set' => ['#not_set' => 'B']]];

    expect(mapFormsMissingTranslation(['based_on_other_attribute_value' => $table, 'specific' => ['radio' => ['#not_set' => 'P']]], [], 'radio'))->toBe(['a' => 'B'])
        ->and(mapFormsMissingTranslation(['based_on_other_attribute_value' => $table, 'default' => ['#not_set' => 'D']], ['o' => 'v']))->toBe([]);
});

it('writes to the target the mapping names', function (): void {
    expect(mapFormsMissingTranslation(['#not_set' => 'NS'], [], null, null, ['default' => 'b']))->toBe(['b' => 'NS'])
        ->and(mapFormsMissingTranslation(['#not_set' => 'NS'], [], null, null, ''))->toBe(['' => 'NS'])
        ->and(mapFormsMissingTranslation(['based_on_other_attribute_value' => ['o' => ['#not_set' => 'B']]], [], null, null, ['default' => 'b']))->toBe([])
        ->and(mapFormsMissingTranslation(['based_on_other_attribute_value' => ['o' => ['#not_set' => 'B']]], [], null, null, ''))->toBe([]);
});

it('skips a missing attribute it has nothing to map to', function (): void {
    $map = new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping');

    expect($map->invoke(null, [], ['n' => ['value_translation' => ['#not_set' => 'NS']]]))->toBe([])
        ->and($map->invoke(null, [], ['n' => ['statamic_attribute' => 'a', 'exclude_fieldtypes' => ['radio'], 'value_translation' => ['#not_set' => 'NS']]], [], null, 'radio'))->toBe([])
        ->and($map->invoke(null, [], ['n' => ['statamic_attribute' => 'a', 'exclude_fieldtypes' => ['radio'], 'value_translation' => ['#not_set' => 'NS']]], [], null, 'select'))->toBe(['a' => 'NS'])
        ->and($map->invoke(null, [], ['n' => ['statamic_attribute' => 'a', 'value_translation' => 'NS']]))->toBe([]);
});

it('drops attributes left empty', function (): void {
    expect(new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')->invoke(
        null,
        ['p' => ['', null], 'q' => [], 'r' => ['x', ''], 's' => 0, 't' => null],
        ['p' => ['statamic_attribute' => 'p'], 'q' => ['statamic_attribute' => 'q'], 'r' => ['statamic_attribute' => 'r'], 's' => ['statamic_attribute' => 's'], 't' => ['statamic_attribute' => 't']]
    ))->toBe(['r' => ['x', ''], 's' => 0]);
});
