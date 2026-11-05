<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms value-transformation resolution (#98).
 *
 * `resolveValueTransformations()` picks the transformation configuration for
 * one key out of an attribute's `value_transformations`, in a fixed order:
 * `specific_based_on_attribute_value`, then `based_on_attribute_value` (its
 * flat conditions, its per-fieldtype `specific` table and its `default`
 * table), then `based_on_other_attribute_value`, then `specific`, then
 * `default`, then the key at the top level. A scalar result is wrapped as
 * `[key => value]`. No standalone test named it before this file.
 *
 * Three behaviors are characterized rather than changed:
 *
 * - the flat and `default` conditions of `based_on_attribute_value` test every
 *   attribute in the field data, not the attribute being mapped, and their
 *   `#not_set` entries never match, since each attribute they test is present;
 * - a lookup for the key `based_on_attribute_value` that nothing else matches
 *   returns the whole `based_on_attribute_value` table through the top-level
 *   lookup;
 * - a flat `#not_empty` or `#empty` entry mapped to `null` resolves to
 *   `[key => null]` rather than to nothing.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsValueTransformationResolutionTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Resolve the transformation configuration for a key.
 *
 * @param  array<int|string, mixed>  $valueTransformations  The value_transformations configuration
 * @param  array<int|string, mixed>  $data  The field data
 * @param  string  $key  The key to resolve
 * @param  ?string  $fieldtype  The fieldtype
 * @return mixed The resolved configuration
 */
function resolveFormsTransformation(
    array $valueTransformations,
    array $data,
    string $key,
    ?string $fieldtype = null
): mixed {
    return new ReflectionMethod(
        FieldtypeMapping::class,
        'resolveValueTransformations'
    )->invoke(
        null,
        $valueTransformations,
        $data,
        $key,
        $fieldtype
    );
}

it('resolves a fieldtype- and value-specific transformation first', function (
    array $valueTransformations,
    array $data,
    ?string $fieldtype,
    mixed $expected
): void {
    expect(resolveFormsTransformation($valueTransformations, $data, 'k', $fieldtype))->toBe($expected);
})->with([
    'a match' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['k' => ['a' => 1]]]]]], ['t' => 'x'], 'radio', ['a' => 1]],
    'a scalar match, wrapped' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['k' => true]]]]], ['t' => 'x'], 'radio', ['k' => true]],
    'a match on a boolean value' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['1' => ['k' => 'B']]]]], ['t' => true], 'radio', ['k' => 'B']],
    'a match on an integer attribute name' => [['specific_based_on_attribute_value' => ['radio' => [0 => ['x' => ['k' => 'I']]]]], [0 => 'x'], 'radio', ['k' => 'I']],
    'a match on the second attribute' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['k' => 1]], 'u' => ['y' => ['k' => 2]]]]], ['u' => 'y'], 'radio', ['k' => 2]],
    'the first attribute, before the second' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['k' => 1]], 'u' => ['y' => ['k' => 2]]]]], ['t' => 'x', 'u' => 'y'], 'radio', ['k' => 1]],
    'a value without the key' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['other' => 1]]]]], ['t' => 'x'], 'radio', null],
    'an absent attribute' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['k' => 1]]]]], [], 'radio', null],
    'another fieldtype' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['k' => 1]]]]], ['t' => 'x'], 'select', null],
    'no fieldtype' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['k' => 1]]]]], ['t' => 'x'], null, null],
    'an empty fieldtype' => [['specific_based_on_attribute_value' => ['' => ['t' => ['x' => ['k' => 1]]]]], ['t' => 'x'], '', null],
    'a table that is not an array' => [['specific_based_on_attribute_value' => 'radio'], ['t' => 'x'], 'radio', null],
    'before every other table' => [['specific_based_on_attribute_value' => ['radio' => ['t' => ['x' => ['k' => 'S']]]], 'default' => ['k' => 'D'], 'k' => 'L'], ['t' => 'x'], 'radio', ['k' => 'S']],
]);

it('resolves the flat conditions of based_on_attribute_value against any attribute', function (
    array $basedOnAttributeValue,
    array $data,
    string $key,
    mixed $expected
): void {
    expect(resolveFormsTransformation(['based_on_attribute_value' => $basedOnAttributeValue], $data, $key))->toBe($expected);
})->with([
    'a not-empty entry for the key' => [['#not_empty' => ['k' => ['a' => 1]]], ['z' => 'v'], 'k', ['a' => 1]],
    'a not-empty scalar, wrapped' => [['#not_empty' => ['k' => 5]], ['z' => 'v'], 'k', ['k' => 5]],
    'a not-empty null, wrapped' => [['#not_empty' => ['k' => null]], ['z' => 'v'], 'k', ['k' => null]],
    'a not-empty entry on the second attribute' => [['#not_empty' => ['k' => 'N']], ['y' => '', 'z' => 'v'], 'k', ['k' => 'N']],
    'an empty entry for the key' => [['#empty' => ['k' => 'E']], ['z' => ''], 'k', ['k' => 'E']],
    'an empty entry, for null' => [['#empty' => ['k' => 'E']], ['z' => null], 'k', ['k' => 'E']],
    'an empty entry, for an empty list' => [['#empty' => ['k' => 'E']], ['z' => []], 'k', ['k' => 'E']],
    'an empty null, wrapped' => [['#empty' => ['k' => null]], ['z' => ''], 'k', ['k' => null]],
    'not empty before empty, on one attribute' => [['#not_empty' => ['k' => 'N'], '#empty' => ['k' => 'E']], ['z' => 'v'], 'k', ['k' => 'N']],
    'the first attribute decides' => [['#not_empty' => ['k' => 'N'], '#empty' => ['k' => 'E']], ['y' => '', 'z' => 'v'], 'k', ['k' => 'E']],
    'zero is not empty' => [['#not_empty' => ['k' => 'N'], '#empty' => ['k' => 'E']], ['z' => 0], 'k', ['k' => 'N']],
    'false is not empty' => [['#not_empty' => ['k' => 'N'], '#empty' => ['k' => 'E']], ['z' => false], 'k', ['k' => 'N']],
    'an entry without the key' => [['#not_empty' => ['other' => 1], '#empty' => ['other' => 1]], ['z' => 'v', 'y' => ''], 'k', null],
    'a not-set entry, never matched' => [['#not_set' => ['k' => 'S']], ['z' => 'v'], 'k', null],
    'no field data' => [['#not_empty' => ['k' => 'N'], '#empty' => ['k' => 'E']], [], 'k', null],
    'a not-empty table for the type key' => [['#not_empty' => ['as_numeric_string' => true]], ['z' => 'v'], 'based_on_attribute_value', ['as_numeric_string' => true]],
    'an empty table for the type key' => [['#empty' => ['x' => 1]], ['z' => ''], 'based_on_attribute_value', ['x' => 1]],
    'a value table for the type key' => [['+6 weeks' => ['x' => 1]], ['z' => '+6 weeks'], 'based_on_attribute_value', ['x' => 1]],
    'a value table on the second attribute' => [['+6 weeks' => ['x' => 1]], ['y' => 'q', 'z' => '+6 weeks'], 'based_on_attribute_value', ['x' => 1]],
    'a value table for an integer' => [[3 => ['x' => 3]], ['z' => 3], 'based_on_attribute_value', ['x' => 3]],
    'a value table for true' => [['1' => ['x' => 'T']], ['z' => true], 'based_on_attribute_value', ['x' => 'T']],
    'a value table for a float' => [['1.5' => ['x' => 'F']], ['z' => 1.5], 'based_on_attribute_value', ['x' => 'F']],
    'no value table for a list' => [['["a"]' => ['x' => 1], 'q' => ['x' => 2]], ['z' => ['a'], 'y' => 'q'], 'based_on_attribute_value', ['x' => 2]],
    'not empty before a value table' => [['#not_empty' => ['n' => 1], 'v' => ['x' => 1]], ['z' => 'v'], 'based_on_attribute_value', ['n' => 1]],
    'empty before a value table' => [['#empty' => ['e' => 1], '' => ['x' => 1]], ['z' => ''], 'based_on_attribute_value', ['e' => 1]],
    'a not-set table, never matched' => [['#not_set' => ['s' => 1]], ['z' => 'v'], 'based_on_attribute_value', ['#not_set' => ['s' => 1]]],
    'a miss for the type key, returning the table' => [['+6 weeks' => ['x' => 1]], ['z' => '+3 weeks'], 'based_on_attribute_value', ['+6 weeks' => ['x' => 1]]],
]);

it('resolves the per-fieldtype table of based_on_attribute_value', function (
    array $attributes,
    array $data,
    ?string $fieldtype,
    mixed $expected
): void {
    expect(resolveFormsTransformation(['based_on_attribute_value' => ['specific' => ['radio' => $attributes]]], $data, 'k', $fieldtype))->toBe($expected);
})->with([
    'a not-set entry' => [['t' => ['#not_set' => ['k' => 'S']]], [], 'radio', ['k' => 'S']],
    'a not-set entry, attribute present' => [['t' => ['#not_set' => ['k' => 'S']]], ['t' => 'x'], 'radio', null],
    'a not-set entry, attribute null' => [['t' => ['#not_set' => ['k' => 'S']]], ['t' => null], 'radio', null],
    'a not-empty entry' => [['t' => ['#not_empty' => ['k' => 'N']]], ['t' => 'x'], 'radio', ['k' => 'N']],
    'a not-empty entry, attribute empty' => [['t' => ['#not_empty' => ['k' => 'N']]], ['t' => ''], 'radio', null],
    'a not-empty entry, attribute zero' => [['t' => ['#not_empty' => ['k' => 'N']]], ['t' => 0], 'radio', ['k' => 'N']],
    'an empty entry' => [['t' => ['#empty' => ['k' => 'E']]], ['t' => ''], 'radio', ['k' => 'E']],
    'an empty entry, attribute absent' => [['t' => ['#empty' => ['k' => 'E']]], [], 'radio', ['k' => 'E']],
    'an empty entry, attribute an empty list' => [['t' => ['#empty' => ['k' => 'E']]], ['t' => []], 'radio', ['k' => 'E']],
    'an empty entry, attribute set' => [['t' => ['#empty' => ['k' => 'E']]], ['t' => 'x'], 'radio', null],
    'a value entry' => [['t' => ['x' => ['k' => ['a' => 1]]]], ['t' => 'x'], 'radio', ['a' => 1]],
    'a value entry on a boolean' => [['t' => ['1' => ['k' => 'B']]], ['t' => true], 'radio', ['k' => 'B']],
    'a value entry without the key' => [['t' => ['x' => ['other' => 1]]], ['t' => 'x'], 'radio', null],
    'a value entry on a list' => [['t' => ['x' => ['k' => 1]]], ['t' => ['x']], 'radio', null],
    'not set before not empty' => [['t' => ['#not_set' => ['k' => 'S'], '#empty' => ['k' => 'E']]], [], 'radio', ['k' => 'S']],
    'not empty before a value entry' => [['t' => ['#not_empty' => ['k' => 'N'], 'x' => ['k' => 'X']]], ['t' => 'x'], 'radio', ['k' => 'N']],
    'empty before a value entry' => [['t' => ['#empty' => ['k' => 'E'], '' => ['k' => 'X']]], ['t' => ''], 'radio', ['k' => 'E']],
    'the second attribute' => [['t' => ['x' => ['k' => 1]], 'u' => ['y' => ['k' => 2]]], ['u' => 'y'], 'radio', ['k' => 2]],
    'an integer attribute name' => [[0 => ['x' => ['k' => 'I']]], [0 => 'x'], 'radio', ['k' => 'I']],
    'another fieldtype' => [['t' => ['x' => ['k' => 1]]], ['t' => 'x'], 'select', null],
    'no fieldtype' => [['t' => ['x' => ['k' => 1]]], ['t' => 'x'], null, null],
    'an attribute table that is not an array' => [['t' => 'x'], ['t' => 'x'], 'radio', null],
]);

it('resolves the default table of based_on_attribute_value', function (
    array $default,
    array $data,
    string $key,
    mixed $expected
): void {
    expect(resolveFormsTransformation(['based_on_attribute_value' => ['default' => $default]], $data, $key))->toBe($expected);
})->with([
    'the shipped not-empty table, for the type key' => [['#not_empty' => ['as_numeric_string' => true]], ['z' => 'v'], 'based_on_attribute_value', ['as_numeric_string' => true]],
    'a not-empty entry for the key' => [['#not_empty' => ['k' => ['a' => 1]]], ['z' => 'v'], 'k', ['a' => 1]],
    'a not-empty scalar for the key, wrapped' => [['#not_empty' => ['k' => true]], ['z' => 'v'], 'k', ['k' => true]],
    'a not-empty entry without the key' => [['#not_empty' => ['other' => 1]], ['z' => 'v'], 'k', null],
    'a not-empty entry, all attributes empty' => [['#not_empty' => ['k' => 'N']], ['z' => ''], 'k', null],
    'a not-empty entry that is not an array, for the type key' => [['#not_empty' => true], ['z' => 'v'], 'based_on_attribute_value', []],
    'an empty entry for the type key' => [['#empty' => ['x' => 1]], ['z' => null], 'based_on_attribute_value', ['x' => 1]],
    'an empty entry for the key' => [['#empty' => ['k' => 'E']], ['z' => ''], 'k', ['k' => 'E']],
    'an empty entry for an empty list' => [['#empty' => ['k' => 'E']], ['z' => []], 'k', ['k' => 'E']],
    'an empty entry without the key' => [['#empty' => ['other' => 1]], ['z' => ''], 'k', null],
    'an empty entry, all attributes set' => [['#empty' => ['k' => 'E']], ['z' => 'v'], 'k', null],
    'a not-set entry, never matched by the data' => [['#not_set' => ['k' => 'S']], ['z' => 'v'], 'k', null],
    'not empty before empty' => [['#not_empty' => ['k' => 'N'], '#empty' => ['k' => 'E']], ['z' => 'v'], 'k', ['k' => 'N']],
    'the first attribute decides' => [['#not_empty' => ['k' => 'N'], '#empty' => ['k' => 'E']], ['y' => '', 'z' => 'v'], 'k', ['k' => 'E']],
    'a shipped value table, for the type key' => [['+6 weeks' => ['as_complex_array' => [['number' => 6]]], '+2 weeks' => ['as_complex_array' => [['number' => 2]]]], ['z' => '+2 weeks'], 'based_on_attribute_value', ['as_complex_array' => [['number' => 2]]]],
    'a value table for the key' => [['v' => ['k' => ['a' => 1]]], ['z' => 'v'], 'k', ['a' => 1]],
    'a value table without the key' => [['v' => ['other' => 1]], ['z' => 'v'], 'k', null],
    'a value table for an integer' => [[3 => ['k' => 'I']], ['z' => 3], 'k', ['k' => 'I']],
    'no value table for a list' => [['["a"]' => ['k' => 1]], ['z' => ['a']], 'k', null],
    'a value table on the second attribute' => [['v' => ['k' => 'V']], ['y' => 'q', 'z' => 'v'], 'k', ['k' => 'V']],
    'not empty before a value table' => [['#not_empty' => ['k' => 'N'], 'v' => ['k' => 'V']], ['z' => 'v'], 'k', ['k' => 'N']],
    'empty before a value table' => [['#empty' => ['k' => 'E'], '' => ['k' => 'V']], ['z' => ''], 'k', ['k' => 'E']],
    'an attribute not-set entry' => [['t' => ['#not_set' => ['k' => 'S']]], ['z' => 'q'], 'k', ['k' => 'S']],
    'an attribute not-set entry, attribute present' => [['t' => ['#not_set' => ['k' => 'S']]], ['t' => 'q'], 'k', null],
    'an attribute not-empty entry' => [['t' => ['#not_empty' => ['k' => 'N']]], ['t' => 'x'], 'k', ['k' => 'N']],
    'an attribute not-empty entry, attribute absent' => [['t' => ['#not_empty' => ['k' => 'N']]], ['z' => 'q'], 'k', null],
    'an attribute empty entry' => [['t' => ['#empty' => ['k' => 'E']]], ['t' => ''], 'k', ['k' => 'E']],
    'an attribute empty entry, attribute set' => [['t' => ['#empty' => ['k' => 'E']]], ['t' => 'q'], 'k', null],
    'an attribute value entry' => [['t' => ['x' => ['k' => ['a' => 1]]]], ['t' => 'x'], 'k', ['a' => 1]],
    'an attribute value entry without the key' => [['t' => ['x' => ['other' => 1]]], ['t' => 'x'], 'k', null],
    'an attribute value entry, for the type key' => [['t' => ['x' => ['a' => 1]]], ['t' => 'x'], 'based_on_attribute_value', ['a' => 1]],
    'an attribute value scalar, for the type key' => [['t' => ['x' => true]], ['t' => 'x'], 'based_on_attribute_value', ['based_on_attribute_value' => true]],
    'an attribute value null, for the type key' => [['t' => ['x' => null]], ['t' => 'x'], 'based_on_attribute_value', ['default' => ['t' => ['x' => null]]]],
    'an attribute value entry on a list, for the type key' => [['t' => ['x' => ['a' => 1]]], ['t' => ['x']], 'based_on_attribute_value', ['default' => ['t' => ['x' => ['a' => 1]]]]],
    'an attribute not set before not empty' => [['t' => ['#not_set' => ['k' => 'S'], '#empty' => ['k' => 'E']]], ['z' => 'q'], 'k', ['k' => 'S']],
    'an attribute not empty before a value entry' => [['t' => ['#not_empty' => ['k' => 'N'], 'x' => ['k' => 'X']]], ['t' => 'x'], 'k', ['k' => 'N']],
    'an attribute empty before a value entry' => [['t' => ['#empty' => ['k' => 'E'], '' => ['k' => 'X']]], ['t' => ''], 'k', ['k' => 'E']],
    'the second attribute' => [['t' => ['x' => ['k' => 1]], 'u' => ['y' => ['k' => 2]]], ['u' => 'y'], 'k', ['k' => 2]],
    'an integer attribute name' => [[0 => ['x' => ['k' => 'I']]], [0 => 'x'], 'k', ['k' => 'I']],
    'an attribute table that is not an array' => [['t' => 'x'], ['t' => 'x'], 'k', null],
]);

it('resolves the remaining tables in order', function (
    array $valueTransformations,
    array $data,
    ?string $fieldtype,
    mixed $expected
): void {
    expect(resolveFormsTransformation($valueTransformations, $data, 'k', $fieldtype))->toBe($expected);
})->with([
    'an other-attribute value' => [['based_on_other_attribute_value' => ['t' => ['x' => ['k' => ['a' => 1]]]]], ['t' => 'x'], null, ['a' => 1]],
    'an other-attribute scalar, wrapped' => [['based_on_other_attribute_value' => ['t' => ['x' => ['k' => 'O']]]], ['t' => 'x'], null, ['k' => 'O']],
    'an other-attribute value on a boolean' => [['based_on_other_attribute_value' => ['t' => ['1' => ['k' => 'B']]]], ['t' => true], null, ['k' => 'B']],
    'an other-attribute integer name' => [['based_on_other_attribute_value' => [0 => ['x' => ['k' => 'I']]]], [0 => 'x'], null, ['k' => 'I']],
    'an other attribute on the second name' => [['based_on_other_attribute_value' => ['t' => ['x' => ['k' => 1]], 'u' => ['y' => ['k' => 2]]]], ['u' => 'y'], null, ['k' => 2]],
    'an other attribute absent' => [['based_on_other_attribute_value' => ['t' => ['x' => ['k' => 1]]]], [], null, null],
    'an other attribute on a list' => [['based_on_other_attribute_value' => ['t' => ['x' => ['k' => 1]]]], ['t' => ['x']], null, null],
    'an other-attribute value without the key' => [['based_on_other_attribute_value' => ['t' => ['x' => ['other' => 1]]]], ['t' => 'x'], null, null],
    'an other-attribute table that is not an array' => [['based_on_other_attribute_value' => ['t' => 'x']], ['t' => 'x'], null, null],
    'a fieldtype table' => [['specific' => ['radio' => ['k' => ['a' => 1]]]], [], 'radio', ['a' => 1]],
    'a fieldtype scalar, wrapped' => [['specific' => ['radio' => ['k' => true]]], [], 'radio', ['k' => true]],
    'a fieldtype null, stopping the lookup' => [['specific' => ['radio' => ['k' => null]], 'default' => ['k' => 'D']], [], 'radio', null],
    'a fieldtype table without the key' => [['specific' => ['radio' => ['other' => 1]], 'default' => ['k' => 'D']], [], 'radio', ['k' => 'D']],
    'another fieldtype' => [['specific' => ['radio' => ['k' => 'S']], 'default' => ['k' => 'D']], [], 'select', ['k' => 'D']],
    'no fieldtype' => [['specific' => ['radio' => ['k' => 'S']], 'default' => ['k' => 'D']], [], null, ['k' => 'D']],
    'an empty fieldtype' => [['specific' => ['' => ['k' => 'S']], 'default' => ['k' => 'D']], [], '', ['k' => 'D']],
    'a default table' => [['default' => ['k' => ['a' => 1]]], [], null, ['a' => 1]],
    'a default scalar, wrapped' => [['default' => ['k' => true]], [], null, ['k' => true]],
    'a default table without the key' => [['default' => ['other' => 1]], [], null, null],
    'a default that is not an array' => [['default' => 'k'], [], null, null],
    'a top-level entry' => [['k' => ['a' => 1]], [], null, ['a' => 1]],
    'a top-level scalar, wrapped' => [['k' => true], [], null, ['k' => true]],
    'nothing for the key' => [['other' => 1], [], null, null],
    'other attribute before fieldtype' => [['based_on_other_attribute_value' => ['t' => ['x' => ['k' => 'O']]], 'specific' => ['radio' => ['k' => 'S']]], ['t' => 'x'], 'radio', ['k' => 'O']],
    'fieldtype before default' => [['specific' => ['radio' => ['k' => 'S']], 'default' => ['k' => 'D']], [], 'radio', ['k' => 'S']],
    'default before top level' => [['default' => ['k' => 'D'], 'k' => 'L'], [], null, ['k' => 'D']],
    'based_on_attribute_value before other attribute' => [['based_on_attribute_value' => ['#not_empty' => ['k' => 'N']], 'based_on_other_attribute_value' => ['t' => ['x' => ['k' => 'O']]]], ['t' => 'x'], null, ['k' => 'N']],
    'a based_on_attribute_value miss, falling through' => [['based_on_attribute_value' => ['#empty' => ['k' => 'E']], 'default' => ['k' => 'D']], ['t' => 'x'], null, ['k' => 'D']],
]);
