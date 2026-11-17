<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms attribute options that pick a target by value (#98).
 *
 * After `mapAttributesFromMapping()` has mapped an attribute's value, three
 * options can still decide where it goes or what it brings with it:
 * `check_against_list` drops a value its named list does not hold;
 * `statamic_attribute.specific_based_on_attribute_value` picks the target
 * attribute by fieldtype and by the value (an exact value or a `*` pattern,
 * `#not_set` in the original field, or `#not_empty`); and
 * `statamic_other_field_value` and
 * `statamic_other_field_value_based_on_attribute_value` write further
 * attributes, always or by the value. A line-coverage run left most of this
 * unexecuted.
 *
 * The `#not_set` entries are matched against the original field only: the
 * mapping visits the attributes the data carries, so the data never lacks the
 * attribute being mapped.
 *
 * The mappings here are written for each case rather than taken from the
 * shipped tables, so each case shows one rule.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsSpecificAttributeTargetTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping as FormidableFormsFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Map one attribute `n` through `mapAttributesFromMapping()`.
 *
 * @param  array<int|string, mixed>  $config  The attribute's mapping
 * @param  mixed  $value  The attribute's value
 * @param  ?string  $fieldtype  The source fieldtype
 * @param  ?array<int|string, mixed>  $original  The original field data
 * @return mixed The mapped attributes
 */
function mapFormsAttributeTarget(
    array $config,
    mixed $value = 'v',
    ?string $fieldtype = null,
    ?array $original = null
): mixed {
    return new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')
        ->invoke(null, ['n' => $value], ['n' => $config], [], null, $fieldtype, $original);
}

it('keeps only a value its validation list holds', function (
    mixed $list,
    mixed $value,
    array $expected
): void {
    expect(mapFormsAttributeTarget(['statamic_attribute' => 'vis', 'check_against_list' => $list], $value))->toBe($expected);
})->with([
    'a listed value' => ['visibility_values', 'hidden', ['vis' => 'hidden']],
    'a value the list does not hold' => ['visibility_values', 'visible', []],
    'a number' => ['visibility_values', 5, []],
    'a list that does not exist' => ['nope', 'hidden', []],
    'a list name that is not a string' => [['visibility_values'], 'visible', ['vis' => 'visible']],
]);

it('maps the attributes after a value its list does not hold', function (): void {
    expect(new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')->invoke(
        null,
        ['n' => 'visible', 'o' => 'kept'],
        ['n' => ['statamic_attribute' => 'vis', 'check_against_list' => 'visibility_values'], 'o' => ['statamic_attribute' => 'oo']]
    ))->toBe(['oo' => 'kept']);
});

it('ignores an unsupported attribute flag', function (): void {
    expect(mapFormsAttributeTarget(['statamic_attribute' => 'a', 'alert_unsupported' => true]))->toBe(['a' => 'v']);
});

it('picks the target attribute by fieldtype and value', function (
    array $table,
    array $extra,
    mixed $value,
    ?string $fieldtype,
    ?array $original,
    array $expected
): void {
    expect(mapFormsAttributeTarget(['statamic_attribute' => ['specific_based_on_attribute_value' => $table]] + $extra, $value, $fieldtype, $original))->toBe($expected);
})->with([
    'an exact value' => [['radio' => ['v' => 'tv'], 'select' => ['v' => 'sv']], [], 'v', 'radio', null, ['tv' => 'v']],
    'a pattern' => [['radio' => ['pre_*' => 'xy']], [], 'pre_abc', 'radio', null, ['xy' => 'pre_abc']],
    // Resolved once, with the star replaced, and no attribute named with
    // the star (#455)
    'a pattern with a star in the target' => [['radio' => ['pre_*' => 'x_*_y']], [], 'pre_abc', 'radio', null, ['x_abc_y' => 'pre_abc']],
    'a pattern with a star in the target, the first match' => [['radio' => ['pre_*' => 'x_*_y', '*' => 'other']], [], 'pre_abc', 'radio', null, ['x_abc_y' => 'pre_abc']],
    'the first match' => [['radio' => ['v*' => 'a', 'v' => 'b']], [], 'v', 'radio', null, ['a' => 'v']],
    'no match' => [['radio' => ['w' => 'tw']], [], 'v', 'radio', null, []],
    'another fieldtype' => [['radio' => ['v' => 'tv']], [], 'v', 'select', null, []],
    'no fieldtype' => [['' => ['v' => 'tv']], [], 'v', null, null, []],
    'an empty fieldtype' => [['' => ['v' => 'tv']], [], 'v', '', null, []],
    'a substitution' => [['radio' => ['v' => 'tv']], ['value_substitution_regex' => ['/v/' => 'w']], 'v', 'radio', null, ['tv' => 'w']],
    'a substitution that is not a table' => [['radio' => ['v' => 'tv']], ['value_substitution_regex' => 'x'], 'v', 'radio', null, ['tv' => 'v']],
    'not set in the original field' => [['radio' => ['#not_set' => 'ns']], [], 'v', 'radio', ['field_options' => '{}'], ['ns' => '']],
    'not set in the original field, substituted' => [['radio' => ['#not_set' => 'ns']], ['value_substitution_regex' => ['/^$/' => 'filled']], 'v', 'radio', ['field_options' => '{}'], ['ns' => 'filled']],
    'set in the original field' => [['radio' => ['#not_set' => 'ns']], [], 'v', 'radio', ['field_options' => '{"n":"x"}'], []],
    'not set, without the original field' => [['radio' => ['#not_set' => 'ns']], [], 'v', 'radio', null, []],
    'not empty' => [['radio' => ['#not_empty' => 'ne']], [], 'v', 'radio', null, ['ne' => 'v']],
    'not empty, substituted' => [['radio' => ['#not_empty' => 'ne']], ['value_substitution_regex' => ['/v/' => 'w']], 'v', 'radio', null, ['ne' => 'w']],
    'not empty, a star in the target' => [['radio' => ['#not_empty' => 'ne_*']], [], 'v', 'radio', null, ['ne_*' => 'v']],
    'with a further attribute' => [['radio' => ['v' => 'tv']], ['statamic_other_field_value' => ['default' => ['flag' => true]]], 'v', 'radio', null, ['tv' => 'v', 'flag' => true]],
]);

it('maps a Formidable Forms text field format to one input mask attribute', function (string $format, array $expected): void {
    expect(new ReflectionMethod(FormidableFormsFieldtypeMapping::class, 'mapAttributesFromMapping')->invoke(
        null,
        ['format' => $format],
        ['format' => FormidableFormsFieldtypeMapping::getFieldOptionsMapping()['format']],
        [],
        null,
        'text',
        null
    ))->toBe($expected);
})->with([
    'a regular expression' => ['^\d{3}$', ['input_mask_mask_MaskedRegExp' => '^\d{3}$', 'input_mask_class' => 'MaskedRegExp', 'input_mask' => true]],
    'a pattern' => ['(999) 999-9999', ['input_mask_mask_MaskedPattern' => '(000) 000-0000', 'input_mask_class' => 'MaskedPattern', 'input_mask' => true]],
]);

it('writes the target beside a default Statamic attribute', function (): void {
    expect(mapFormsAttributeTarget(['statamic_attribute' => ['default' => 'plain', 'specific_based_on_attribute_value' => ['radio' => ['v' => 'tv']]]], 'v', 'radio'))
        ->toBe(['tv' => 'v', 'plain' => 'v']);
});

it('writes further attributes for a mapped value', function (
    array $further,
    mixed $value,
    ?string $fieldtype,
    array $expected
): void {
    expect(mapFormsAttributeTarget(['statamic_attribute' => 'a', 'statamic_other_field_value' => $further], $value, $fieldtype))->toBe($expected);
})->with([
    'the default' => [['default' => ['flag' => true, 'k' => 'x']], 'v', null, ['flag' => true, 'k' => 'x', 'a' => 'v']],
    'the specific over the default' => [['default' => ['flag' => true], 'specific' => ['radio' => ['flag' => false, 'z' => 1]]], 'v', 'radio', ['flag' => false, 'z' => 1, 'a' => 'v']],
    'no specific for another fieldtype' => [['specific' => ['radio' => ['z' => 1]]], 'v', 'select', ['a' => 'v']],
    'no specific with no fieldtype' => [['specific' => ['' => ['z' => 1]]], 'v', null, ['a' => 'v']],
    'no specific with an empty fieldtype' => [['specific' => ['' => ['z' => 1]]], 'v', '', ['a' => 'v']],
    'a zero' => [['default' => ['flag' => true]], '0', null, ['flag' => true, 'a' => '0']],
    'nothing for an empty value' => [['default' => ['flag' => true]], '', null, []],
    'nothing for an empty list' => [['default' => ['flag' => true]], [], null, []],
]);

it('writes no further attributes without a Statamic attribute', function (): void {
    expect(mapFormsAttributeTarget(['statamic_other_field_value' => ['default' => ['flag' => true]]]))->toBe([]);
});

it('writes further attributes by the value', function (
    array $table,
    array $extra,
    mixed $value,
    ?string $fieldtype,
    ?array $original,
    array $expected
): void {
    expect(mapFormsAttributeTarget(['statamic_other_field_value_based_on_attribute_value' => $table] + $extra, $value, $fieldtype, $original))->toBe($expected);
})->with([
    // Split into default and specific
    'default, an exact value' => [['default' => ['v' => ['f' => 1]]], [], 'v', null, null, ['f' => 1]],
    'default, a pattern' => [['default' => ['v*' => ['f' => 1]]], [], 'vee', null, null, ['f' => 1]],
    'default, no match' => [['default' => ['w' => ['f' => 1]]], [], 'v', null, null, []],
    'default, the first match' => [['default' => ['v*' => ['f' => 1], 'v' => ['f' => 2, 'g' => 3]]], [], 'v', null, null, ['f' => 1]],
    'default, not set in the original field' => [['default' => ['#not_set' => ['f' => 1]]], [], 'v', null, ['field_options' => '{}'], ['f' => 1]],
    'default, set in the original field' => [['default' => ['#not_set' => ['f' => 1]]], [], 'v', null, ['field_options' => '{"n":1}'], []],
    'default, not set without the original field' => [['default' => ['#not_set' => ['f' => 1]]], [], 'v', null, null, []],
    'default, not empty' => [['default' => ['#not_empty' => ['f' => 1]]], [], 'v', null, null, ['f' => 1]],
    'default, empty in the original field' => [['default' => ['#not_empty' => ['f' => 1]]], [], 'v', null, ['field_options' => '{"n":""}'], []],
    'specific after default' => [['default' => ['v' => ['f' => 1]], 'specific' => ['radio' => ['v' => ['f' => 2, 'g' => 3]]]], [], 'v', 'radio', null, ['f' => 2, 'g' => 3]],
    'specific, another fieldtype' => [['default' => ['v' => ['f' => 1]], 'specific' => ['radio' => ['v' => ['f' => 2]]]], [], 'v', 'select', null, ['f' => 1]],
    'specific, no fieldtype' => [['default' => ['v' => ['f' => 1]], 'specific' => ['' => ['v' => ['f' => 2]]]], [], 'v', null, null, ['f' => 1]],
    'specific, an empty fieldtype' => [['default' => ['v' => ['f' => 1]], 'specific' => ['' => ['v' => ['f' => 2]]]], [], 'v', '', null, ['f' => 1]],
    'specific, no match' => [['default' => [], 'specific' => ['radio' => ['w' => ['f' => 2]]]], [], 'v', 'radio', null, []],
    'specific, the first match' => [['default' => [], 'specific' => ['radio' => ['v*' => ['f' => 2], 'v' => ['f' => 3, 'g' => 4]]]], [], 'v', 'radio', null, ['f' => 2]],
    'specific, not set in the original field' => [['default' => [], 'specific' => ['radio' => ['#not_set' => ['f' => 2]]]], [], 'v', 'radio', ['field_options' => '{}'], ['f' => 2]],
    'specific, set in the original field' => [['default' => [], 'specific' => ['radio' => ['#not_set' => ['f' => 2]]]], [], 'v', 'radio', ['field_options' => '{"n":1}'], []],
    'specific, not set without the original field' => [['default' => [], 'specific' => ['radio' => ['#not_set' => ['f' => 2]]]], [], 'v', 'radio', null, []],
    'specific, not empty' => [['default' => [], 'specific' => ['radio' => ['#not_empty' => ['f' => 2]]]], [], 'v', 'radio', null, ['f' => 2]],
    'specific, empty in the original field' => [['default' => [], 'specific' => ['radio' => ['#not_empty' => ['f' => 2]]]], [], 'v', 'radio', ['field_options' => '{"n":""}'], []],
    'specific with no default' => [['specific' => ['radio' => ['v' => ['f' => 2]]]], [], 'v', 'radio', null, []],

    // Not split
    'an exact value' => [['v' => ['f' => 1]], [], 'v', null, null, ['f' => 1]],
    'a pattern' => [['v*' => ['f' => 1]], [], 'vee', null, null, ['f' => 1]],
    'no match' => [['w' => ['f' => 1]], [], 'v', null, null, []],
    'the first match' => [['v*' => ['f' => 1], 'v' => ['f' => 2, 'g' => 3]], [], 'v', null, null, ['f' => 1]],
    'not set in the original field' => [['#not_set' => ['f' => 1]], [], 'v', null, ['field_options' => '{}'], ['f' => 1]],
    'set in the original field' => [['#not_set' => ['f' => 1]], [], 'v', null, ['field_options' => '{"n":1}'], []],
    'not set without the original field' => [['#not_set' => ['f' => 1]], [], 'v', null, null, []],
    '#not_empty is not a special key' => [['#not_empty' => ['f' => 1]], [], 'v', null, null, []],
    'a boolean value' => [['true' => ['f' => 1]], [], true, null, null, ['f' => 1]],
    'a list value' => [['["a"]' => ['f' => 1]], [], ['a'], null, null, ['f' => 1]],
    'beside a Statamic attribute' => [['v' => ['f' => 1]], ['statamic_attribute' => 'a'], 'v', null, null, ['f' => 1, 'a' => 'v']],
]);
