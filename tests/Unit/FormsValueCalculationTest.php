<?php

declare(strict_types=1);

/**
 * Unit tests for how the Forms attribute mapping picks a value calculation (#98).
 *
 * A `value_calculation` is either an expression, or a table of expressions
 * picked in a fixed order: `specific_based_on_attribute_value` (matched
 * against the attribute's own value, with wildcards, or `#not_set` in the
 * original field), `based_on_other_attribute_value` (by another attribute's
 * value or `#not_set`, split into `default` and `specific` or not), then
 * `specific` and `default`. A table holding only `specific` and `default` is
 * resolved for the fieldtype before the calculation runs, so a `null` specific
 * entry turns the calculation off. The expression is applied only to a
 * numeric value; `ApplyValueCalculationTest` covers the arithmetic itself.
 *
 * The same pass prepends, collapses whitespace, converts to and inverts a
 * boolean, and restores escaped newlines; those options are covered here as
 * well. A line-coverage run left all of this unexecuted.
 *
 * The mappings here are written for each case rather than taken from the
 * shipped tables, so each case shows one rule.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsValueCalculationTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Map one attribute `n` through `mapAttributesFromMapping()`.
 *
 * @param  array<int|string, mixed>  $config  The attribute's mapping, beside `statamic_attribute`
 * @param  mixed  $value  The attribute's value
 * @param  array<int|string, mixed>  $data  The other source attributes
 * @param  ?string  $fieldtype  The source fieldtype
 * @param  ?array<int|string, mixed>  $original  The original field data
 * @return mixed The mapped attributes
 */
function calculateFormsAttribute(
    array $config,
    mixed $value = 10,
    array $data = [],
    ?string $fieldtype = null,
    ?array $original = null
): mixed {
    return new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')
        ->invoke(
            null,
            ['n' => $value] + $data,
            ['n' => ['statamic_attribute' => 'n'] + $config],
            [],
            null,
            $fieldtype,
            $original
        );
}

it('applies a value calculation expression', function (
    mixed $calculation,
    mixed $value,
    mixed $expected
): void {
    expect(calculateFormsAttribute(['value_calculation' => $calculation], $value))->toBe(['n' => $expected]);
})->with([
    'an expression' => ['[value] * 2', 10, 20],
    'a numeric string' => ['[value] * 2', '7', 14],
    'a fractional result' => ['[value] / 4', 10, 2.5],
    'a value that is not numeric' => ['[value] * 2', 'abc', 'abc'],
    'an expression that is not arithmetic' => ['[value] +* 2', 10, 10],
    'neither an expression nor a table' => [5, 10, 10],
    'an empty table' => [[], 10, 10],
]);

it('picks the value calculation expression', function (
    array $calculation,
    array $data,
    ?string $fieldtype,
    ?array $original,
    mixed $expected
): void {
    expect(calculateFormsAttribute(['value_calculation' => $calculation], 10, $data, $fieldtype, $original))->toBe(['n' => $expected]);
})->with([
    // default and specific alone, resolved for the fieldtype first
    'default' => [['default' => '[value] * 3'], [], null, null, 30],
    'a default that is not an expression' => [['default' => 3], [], null, null, 10],
    'specific for the fieldtype' => [['default' => '[value] * 3', 'specific' => ['radio' => '[value] + 1']], [], 'radio', null, 11],
    'specific, then default for another fieldtype' => [['default' => '[value] * 3', 'specific' => ['radio' => '[value] + 1']], [], 'select', null, 30],
    'specific, then default with no fieldtype' => [['default' => '[value] * 3', 'specific' => ['radio' => '[value] + 1']], [], null, null, 30],
    'specific, then default with an empty fieldtype' => [['default' => '[value] * 3', 'specific' => ['' => '[value] + 1']], [], '', null, 30],
    'a null specific entry turns it off' => [['default' => '[value] * 3', 'specific' => ['radio' => null]], [], 'radio', null, 10],

    // specific_based_on_attribute_value, matched against the attribute's own value
    'by own value' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['10' => '[value] + 1']]], [], 'radio', null, 11],
    'by own value, a wildcard' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['1*' => '[value] + 1']]], [], 'radio', null, 11],
    'by own value, no match' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['2*' => '[value] + 1']]], [], 'radio', null, 30],
    'by own value, the second pattern matches' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['9' => '[value] + 1', '10' => '[value] + 2']]], [], 'radio', null, 12],
    'by own value, another fieldtype' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['1*' => '[value] + 1']]], [], 'select', null, 30],
    'by own value, no fieldtype' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['' => ['1*' => '[value] + 1']]], [], null, null, 30],
    'by own value, an empty fieldtype' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['' => ['1*' => '[value] + 1']]], [], '', null, 30],
    'by own value, not set without the original field' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['#not_set' => '[value] + 1']]], [], 'radio', null, 30],
    'by own value, not set in the original field' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['#not_set' => '[value] + 1']]], [], 'radio', ['field_options' => '{}'], 11],
    'by own value, set in the original field' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['#not_set' => '[value] + 1']]], [], 'radio', ['field_options' => '{"n":5}'], 30],
    'by own value, #not_empty is not a special key' => [['default' => '[value] * 3', 'specific_based_on_attribute_value' => ['radio' => ['#not_empty' => '[value] + 1']]], [], 'radio', null, 30],
    'by own value, before based_on_other_attribute_value' => [['specific_based_on_attribute_value' => ['radio' => ['10' => '[value] + 1']], 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 2']]], ['mode' => 'm1'], 'radio', null, 11],

    // based_on_other_attribute_value, without the specific/default split
    'by other attribute' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 1']]], ['mode' => 'm1'], null, null, 11],
    'by other attribute, no match' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 1']]], ['mode' => 'm2'], null, null, 30],
    'by other attribute, the second attribute matches' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['mode' => ['m9' => '[value] + 1'], 'kind' => ['k1' => '[value] + 2']]], ['mode' => 'm1', 'kind' => 'k1'], null, null, 12],
    'by other attribute, not set' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['mode' => ['#not_set' => '[value] + 1']]], [], null, null, 11],
    'by other attribute, not set but set' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['mode' => ['#not_set' => '[value] + 1']]], ['mode' => 'x'], null, null, 30],
    'by other attribute, a value before not set' => [['based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 1', '#not_set' => '[value] + 2']]], ['mode' => 'm1'], null, null, 11],
    'by other attribute, a null not set entry' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['mode' => ['#not_set' => null]]], [], null, null, 30],
    'by other attribute, #not_empty is not a special key' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['mode' => ['#not_empty' => '[value] + 1']]], ['mode' => 'x'], null, null, 30],
    'by other attribute, a table that is not an array' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => 'x'], [], null, null, 30],
    'by other attribute, before specific' => [['specific' => ['radio' => '[value] + 9'], 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 2']]], ['mode' => 'm1'], 'radio', null, 12],
    'by other attribute, no match, then specific' => [['default' => '[value] * 3', 'specific' => ['radio' => '[value] + 9'], 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 2']]], ['mode' => 'm2'], 'radio', null, 19],
    'by other attribute, no match, then default for another fieldtype' => [['default' => '[value] * 3', 'specific' => ['radio' => '[value] + 9'], 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 2']]], ['mode' => 'm2'], 'select', null, 30],
    'by other attribute, no match, then default with an empty fieldtype' => [['default' => '[value] * 3', 'specific' => ['' => '[value] + 9'], 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 2']]], ['mode' => 'm2'], '', null, 30],
    'by other attribute, no match, a null specific entry falls back to default' => [['default' => '[value] * 3', 'specific' => ['radio' => null], 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 2']]], ['mode' => 'm2'], 'radio', null, 30],
    'by other attribute, no match and no default' => [['based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 2']]], ['mode' => 'm2'], null, null, 10],

    // based_on_other_attribute_value, split into default and specific
    'by other attribute split, default' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => '[value] + 1']]]], ['mode' => 'm1'], null, null, 11],
    'by other attribute split, specific' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['specific' => ['radio' => ['mode' => ['m1' => '[value] + 1']]]]], ['mode' => 'm1'], 'radio', null, 11],
    'by other attribute split, default before specific' => [['based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => '[value] + 1']], 'specific' => ['radio' => ['mode' => ['m1' => '[value] + 2']]]]], ['mode' => 'm1'], 'radio', null, 11],
    'by other attribute split, default misses, then specific' => [['based_on_other_attribute_value' => ['default' => ['mode' => ['m9' => '[value] + 1']], 'specific' => ['radio' => ['mode' => ['m1' => '[value] + 2']]]]], ['mode' => 'm1'], 'radio', null, 12],
    'by other attribute split, specific for another fieldtype' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['specific' => ['radio' => ['mode' => ['m1' => '[value] + 1']]]]], ['mode' => 'm1'], 'select', null, 30],
    'by other attribute split, specific with an empty fieldtype' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['specific' => ['' => ['mode' => ['m1' => '[value] + 1']]]]], ['mode' => 'm1'], '', null, 30],
    'by other attribute split, specific with no fieldtype' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['specific' => ['' => ['mode' => ['m1' => '[value] + 1']]]]], ['mode' => 'm1'], null, null, 30],
    'by other attribute split, default not set' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['default' => ['mode' => ['#not_set' => '[value] + 1']]]], [], null, null, 11],
    'by other attribute split, default not set but set' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['default' => ['mode' => ['#not_set' => '[value] + 1']]]], ['mode' => 'x'], null, null, 30],
    'by other attribute split, specific not set' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['specific' => ['radio' => ['mode' => ['#not_set' => '[value] + 1']]]]], [], 'radio', null, 11],
    'by other attribute split, specific not set but set' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['specific' => ['radio' => ['mode' => ['#not_set' => '[value] + 1']]]]], ['mode' => 'x'], 'radio', null, 30],
    'by other attribute split, the second default attribute matches' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['default' => ['mode' => ['m9' => '[value] + 1'], 'kind' => ['k1' => '[value] + 2']]]], ['mode' => 'm1', 'kind' => 'k1'], null, null, 12],
    'by other attribute split, the second specific attribute matches' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['specific' => ['radio' => ['mode' => ['m9' => '[value] + 1'], 'kind' => ['k1' => '[value] + 2']]]]], ['mode' => 'm1', 'kind' => 'k1'], 'radio', null, 12],
    'by other attribute split, no match' => [['default' => '[value] * 3', 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => '[value] + 1']]]], ['mode' => 'm2'], null, null, 30],

    // The shipped Formidable textarea character limit
    'a word limit in characters' => [['based_on_other_attribute_value' => ['specific' => ['textarea' => ['max_limit_type' => ['#not_set' => '[value]', 'char' => '[value]', 'word' => 'round([value] * 4.79)']]]]], ['max_limit_type' => 'word'], 'textarea', null, 48],
    'a limit with no type' => [['based_on_other_attribute_value' => ['specific' => ['textarea' => ['max_limit_type' => ['#not_set' => '[value]', 'char' => '[value]', 'word' => 'round([value] * 4.79)']]]]], [], 'textarea', null, 10],
]);

it('applies a picked expression only to a numeric value', function (): void {
    expect(calculateFormsAttribute(['value_calculation' => ['default' => '[value] * 3', 'based_on_other_attribute_value' => ['mode' => ['m1' => '[value] + 1']]]], 'abc', ['mode' => 'm1']))
        ->toBe(['n' => 'abc']);
});

it('finishes the mapped value', function (
    array $config,
    mixed $value,
    ?string $fieldtype,
    array $expected
): void {
    expect(calculateFormsAttribute($config, $value, [], $fieldtype))->toBe($expected);
})->with([
    'a prefix' => [['value_prepend' => 'pre-'], 'v', null, ['n' => 'pre-v']],
    'a prefix on a number' => [['value_prepend' => 'pre-'], 5, null, ['n' => 'pre-5']],
    'a prefix on a list' => [['value_prepend' => 'pre-'], ['a'], null, ['n' => ['a']]],
    'a prefix that is not a string' => [['value_prepend' => 5], 'v', null, ['n' => 'v']],
    'a prefix on an empty value' => [['value_prepend' => 'pre-'], '', null, []],
    'whitespace collapsed' => [['collapse_whitespace' => true], " a b\t c\n", null, ['n' => 'abc']],
    'whitespace collapsed only when true' => [['collapse_whitespace' => 1], 'a b', null, ['n' => 'a b']],
    'whitespace collapsed only in a string' => [['collapse_whitespace' => true], 5, null, ['n' => 5]],
    'a boolean' => [['as_boolean' => true], '1', null, ['n' => true]],
    'a boolean only when true' => [['as_boolean' => 1], '1', null, ['n' => '1']],
    'an inverted boolean' => [['as_boolean' => true, 'invert_boolean' => true], '1', null, ['n' => false]],
    'an inverted boolean only when true' => [['as_boolean' => true, 'invert_boolean' => 1], '1', null, ['n' => true]],
    'an inversion without a boolean' => [['invert_boolean' => true], '1', null, ['n' => '1']],
    'escaped newlines kept' => [['preserve_newlines' => true], 'a\\nb', null, ['n' => "a\nb"]],
    'escaped newlines kept for the fieldtype' => [['preserve_newlines' => ['default' => false, 'specific' => ['radio' => true]]], 'a\\nb', 'radio', ['n' => "a\nb"]],
    'escaped newlines left for another fieldtype' => [['preserve_newlines' => ['default' => false, 'specific' => ['radio' => true]]], 'a\\nb', 'select', ['n' => 'a\\nb']],
    'escaped newlines left' => [['preserve_newlines' => false], 'a\\nb', null, ['n' => 'a\\nb']],
]);
