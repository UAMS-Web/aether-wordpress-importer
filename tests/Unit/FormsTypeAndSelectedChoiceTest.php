<?php

declare(strict_types=1);

/**
 * Unit tests for the last Forms attribute options before the value is written (#98).
 *
 * After `mapAttributesFromMapping()` has mapped an attribute's value, five
 * more options can add to or change what it writes:
 * `statamic_other_field_value_based_on_nested_attribute_value` writes further
 * attributes when the value holds a nested pattern, by default and by
 * fieldtype; `statamic_type_based_on_attribute_value` changes the field's
 * `type` by the value; `statamic_form_config` copies the value to a
 * `form_config_` attribute; `get_selected_choice` fills an empty default from
 * the choice marked selected; and `as_array` appends the value rather than
 * replacing it. A line-coverage run left most of this unexecuted.
 *
 * The `#not_set` entry of `statamic_type_based_on_attribute_value` is matched
 * against the original field only: the mapping visits the attributes the data
 * carries, so the data never lacks the attribute being mapped.
 *
 * The mappings here are written for each case rather than taken from the
 * shipped tables, so each case shows one rule. `get_selected_choice` reads the
 * mapping class's own `choices` table, so those cases run on a subclass whose
 * table each case sets.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsTypeAndSelectedChoiceTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * The Gravity Forms mapping with a `choices` table set by each test.
 */
final class FormsSelectedChoiceMapping extends FieldtypeMapping
{
    /**
     * The mapping table, holding only the `choices` entry a test sets.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeMapping = [];

    /**
     * Replace the `choices` entry of the mapping table.
     *
     * @param  array<int|string, mixed>  $choices  The `choices` entry
     */
    public static function useChoices(array $choices): void
    {
        self::$fieldAttributeMapping = ['choices' => $choices];
    }
}

/**
 * Map one attribute `n` through `mapAttributesFromMapping()`.
 *
 * @param  array<int|string, mixed>  $config  The attribute's mapping
 * @param  mixed  $value  The attribute's value
 * @param  ?string  $fieldtype  The source fieldtype
 * @param  ?array<int|string, mixed>  $original  The original field data
 * @return mixed The mapped attributes
 */
function mapFormsTypeAndChoice(
    array $config,
    mixed $value = 'v',
    ?string $fieldtype = null,
    ?array $original = null
): mixed {
    return new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')
        ->invoke(null, ['n' => $value], ['n' => $config], [], null, $fieldtype, $original);
}

/**
 * Map a `defaultValue` of null beside `choices` on the selected-choice subclass.
 *
 * @param  array<int|string, mixed>  $choicesConfig  The class's `choices` entry
 * @param  mixed  $choices  The field's choices
 * @param  mixed  $flag  The `get_selected_choice` option
 * @param  mixed  $default  The field's `defaultValue`
 * @param  string  $target  The statamic attribute `defaultValue` maps to
 * @return mixed The mapped attributes
 */
function selectFormsChoice(
    array $choicesConfig,
    mixed $choices,
    mixed $flag = true,
    mixed $default = null,
    string $target = 'default'
): mixed {
    FormsSelectedChoiceMapping::useChoices($choicesConfig);

    return new ReflectionMethod(FormsSelectedChoiceMapping::class, 'mapAttributesFromMapping')
        ->invoke(
            null,
            ['defaultValue' => $default, 'choices' => $choices, 'mode' => 'm1', 'kind' => 'k1'],
            ['defaultValue' => ['statamic_attribute' => $target, 'get_selected_choice' => $flag]],
            [],
            null,
            'radio'
        );
}

/**
 * Two choices, the second marked selected.
 */
const FORMS_SC_CHOICES = [
    ['text' => 'One', 'value' => 'one', 'isSelected' => false],
    ['text' => 'Two', 'value' => 'two', 'isSelected' => true],
];

it('writes further attributes when the value holds a nested pattern', function (
    array $table,
    ?string $fieldtype,
    array $expected
): void {
    expect(mapFormsTypeAndChoice(
        ['statamic_other_field_value_based_on_nested_attribute_value' => $table],
        ['k' => 'v'],
        $fieldtype
    ))->toBe($expected);
})->with([
    'a default match' => [['default' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 1]]]], null, ['f' => 1]],
    'a default miss' => [['default' => [['nested_attribute' => ['k' => 'w'], 'other_attribute' => ['f' => 1]]]], null, []],
    'a specific match' => [['default' => [], 'specific' => ['radio' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 2, 'g' => 3]]]]], 'radio', ['f' => 2, 'g' => 3]],
    'a specific match over a default one' => [['default' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 1, 'h' => 0]]], 'specific' => ['radio' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 2]]]]], 'radio', ['f' => 2, 'h' => 0]],
    'the first specific match' => [['default' => [], 'specific' => ['radio' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 2]], ['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 3, 'g' => 4]]]]], 'radio', ['f' => 2]],
    'a specific miss' => [['default' => [], 'specific' => ['radio' => [['nested_attribute' => ['k' => 'w'], 'other_attribute' => ['f' => 2]]]]], 'radio', []],
    'another fieldtype' => [['default' => [], 'specific' => ['radio' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 2]]]]], 'select', []],
    'no fieldtype' => [['default' => [], 'specific' => ['' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 2]]]]], null, []],
    'an empty fieldtype' => [['default' => [], 'specific' => ['' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 2]]]]], '', []],
    'a table with no default' => [['specific' => ['radio' => [['nested_attribute' => ['k' => 'v'], 'other_attribute' => ['f' => 2]]]]], 'radio', []],
]);

it('sets the field type by the value', function (
    array $config,
    mixed $value,
    ?array $original,
    array $expected
): void {
    expect(mapFormsTypeAndChoice($config, $value, null, $original))->toBe($expected);
})->with([
    'an exact value' => [['statamic_attribute' => 'a', 'statamic_type_based_on_attribute_value' => ['v' => 'textarea']], 'v', null, ['type' => 'textarea', 'a' => 'v']],
    'a pattern' => [['statamic_type_based_on_attribute_value' => ['v*' => 'textarea']], 'vee', null, ['type' => 'textarea']],
    'no match' => [['statamic_type_based_on_attribute_value' => ['w' => 'textarea']], 'v', null, []],
    'the first match' => [['statamic_type_based_on_attribute_value' => ['v*' => 'a', 'v' => 'b']], 'v', null, ['type' => 'a']],
    '#not_set, absent from the original field' => [['statamic_type_based_on_attribute_value' => ['#not_set' => 'ns']], 'v', ['field_options' => '{}'], ['type' => 'ns']],
    '#not_set, present in the original field' => [['statamic_type_based_on_attribute_value' => ['#not_set' => 'ns']], 'v', ['field_options' => '{"n":1}'], []],
    '#not_set before a matching value' => [['statamic_type_based_on_attribute_value' => ['#not_set' => 'ns', 'v' => 'b']], 'v', ['field_options' => '{}'], ['type' => 'ns']],
    '#not_set with no original field' => [['statamic_type_based_on_attribute_value' => ['#not_set' => 'ns', 'v' => 'b']], 'v', null, ['type' => 'b']],
]);

it('copies a non-empty value to a form config attribute', function (
    array $config,
    mixed $value,
    array $expected
): void {
    expect(mapFormsTypeAndChoice($config, $value))->toBe($expected);
})->with([
    'a string key' => [['statamic_attribute' => 'a', 'statamic_form_config' => 'honeypot'], 'v', ['form_config_honeypot' => 'v', 'a' => 'v']],
    'no target attribute' => [['statamic_form_config' => 'honeypot'], 'v', []],
    'an empty value' => [['statamic_attribute' => 'a', 'statamic_form_config' => 'honeypot'], '', []],
    'a key that is not a string' => [['statamic_attribute' => 'a', 'statamic_form_config' => ['x']], 'v', ['a' => 'v']],
    'a number key' => [['statamic_attribute' => 'a', 'statamic_form_config' => 5], 'v', ['a' => 'v']],
]);

it('appends the value when as_array is true', function (): void {
    $map = new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping');

    expect(mapFormsTypeAndChoice(['statamic_attribute' => 'a', 'as_array' => true]))->toBe(['a' => ['v']])
        ->and(mapFormsTypeAndChoice(['statamic_attribute' => 'a', 'as_array' => 1]))->toBe(['a' => 'v'])
        ->and(mapFormsTypeAndChoice(['statamic_attribute' => 'a', 'as_array' => true], null))->toBe([])
        ->and($map->invoke(
            null,
            ['n' => 'v', 'o' => 'w'],
            ['n' => ['statamic_attribute' => 'a', 'as_array' => true], 'o' => ['statamic_attribute' => 'a', 'as_array' => true]]
        ))->toBe(['a' => ['v', 'w']])
        ->and($map->invoke(
            null,
            ['n' => 'v', 'o' => 'w'],
            ['n' => ['statamic_attribute' => 'a'], 'o' => ['statamic_attribute' => 'a', 'as_array' => true]]
        ))->toBe(['a' => ['w']]);
});

it('fills a choice field default from the shipped Gravity Forms mapping', function (
    string $fieldtype,
    mixed $default,
    array $expected
): void {
    $mapping = new ReflectionProperty(FieldtypeMapping::class, 'fieldAttributeMapping')->getValue();
    expect($mapping)->toBeArray()->toHaveKey('defaultValue');
    \assert(\is_array($mapping));

    expect(new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')->invoke(
        null,
        ['defaultValue' => $default, 'choices' => FORMS_SC_CHOICES],
        ['defaultValue' => $mapping['defaultValue']],
        [],
        null,
        $fieldtype
    ))->toBe($expected);
})->with([
    'radio' => ['radio', null, ['default' => 'two']],
    'radio, an empty default' => ['radio', '', ['default' => 'two']],
    'select' => ['select', null, ['default' => 'two']],
    'text' => ['text', null, []],
]);

it('reads the selected choice through the key translation table', function (
    array $choicesConfig,
    array $expected
): void {
    expect(selectFormsChoice($choicesConfig, FORMS_SC_CHOICES))->toBe($expected);
})->with([
    'a plain table' => [['key_translation' => ['value' => 'key', 'text' => 'label']], ['default' => 'two']],
    'a default table' => [['key_translation' => ['default' => ['value' => 'key']]], ['default' => 'two']],
    'a table for the fieldtype' => [['key_translation' => ['default' => ['value' => 'key'], 'specific' => ['radio' => ['text' => 'key']]]], ['default' => 'Two']],
    'a table for another fieldtype' => [['key_translation' => ['default' => ['value' => 'key'], 'specific' => ['select' => ['text' => 'key']]]], ['default' => 'two']],
    'a table by another attribute' => [['key_translation' => ['default' => ['value' => 'key'], 'based_on_other_attribute_value' => ['mode' => ['m1' => ['value' => 'key']]]]], ['default' => 'two']],
    'a table by another attribute, over the fieldtype' => [['key_translation' => ['default' => ['value' => 'key'], 'specific' => ['radio' => ['text' => 'key']], 'based_on_other_attribute_value' => ['mode' => ['m1' => ['value' => 'key']]]]], ['default' => 'two']],
    'no table for the other attribute' => [['key_translation' => ['default' => ['value' => 'key'], 'based_on_other_attribute_value' => ['mode' => ['m9' => ['value' => 'key']]]]], ['default' => 'Two']],
    'an other attribute the data lacks' => [['key_translation' => ['default' => ['value' => 'key'], 'based_on_other_attribute_value' => ['gone' => ['' => ['value' => 'key']]]]], ['default' => 'Two']],
    'the first other attribute that matches' => [['key_translation' => ['default' => ['value' => 'key'], 'based_on_other_attribute_value' => ['mode' => ['m1' => ['value' => 'key']], 'kind' => ['k1' => ['text' => 'key']]]]], ['default' => 'two']],
    'an empty other attribute table' => [['key_translation' => ['default' => ['value' => 'key'], 'based_on_other_attribute_value' => '']], ['default' => 'two']],
    'the first attribute mapped to key' => [['key_translation' => ['value' => 'key', 'text' => 'key']], ['default' => 'two']],
    'no attribute mapped to key' => [['key_translation' => ['value' => 'label']], ['default' => 'Two']],
    'a key attribute the choice lacks' => [['key_translation' => ['price' => 'key']], ['default' => 'Two']],
    'no key translation' => [['expected_keys' => ['default' => ['key', 'value']]], []],
    'other expected keys' => [['key_translation' => ['value' => 'key'], 'expected_keys' => ['default' => ['label']]], []],
    'no choices entry' => [[], []],
]);

it('fills the default only from a choice selected with true', function (
    mixed $choices,
    mixed $flag,
    mixed $default,
    string $target,
    array $expected
): void {
    expect(selectFormsChoice(['key_translation' => ['value' => 'key']], $choices, $flag, $default, $target))->toBe($expected);
})->with([
    'a selected choice' => [FORMS_SC_CHOICES, true, null, 'default', ['default' => 'two']],
    'no choice selected' => [[['text' => 'One', 'value' => 'one']], true, null, 'default', []],
    'a choice selected with 1' => [[['text' => 'One', 'value' => 'one', 'isSelected' => 1]], true, null, 'default', []],
    'a choice that is not an array' => [['x', ['text' => 'Two', 'value' => 'two', 'isSelected' => true]], true, null, 'default', ['default' => 'two']],
    'choices that are not a list' => ['x', true, null, 'default', []],
    'a default already given' => [FORMS_SC_CHOICES, true, 'given', 'default', ['default' => 'given']],
    'another target attribute' => [FORMS_SC_CHOICES, true, null, 'other', []],
    'the option off' => [FORMS_SC_CHOICES, false, null, 'default', []],
    'the option not true' => [FORMS_SC_CHOICES, 'choices.isSelected', null, 'default', []],
]);

it('falls back to nothing when the selected choice has no text', function (): void {
    expect(selectFormsChoice(['key_translation' => ['price' => 'key']], [['value' => 'one', 'isSelected' => true]]))->toBe([]);
});

it('reads no selected choice when the field has no choices', function (): void {
    FormsSelectedChoiceMapping::useChoices(['key_translation' => ['value' => 'key']]);

    expect(new ReflectionMethod(FormsSelectedChoiceMapping::class, 'mapAttributesFromMapping')->invoke(
        null,
        ['defaultValue' => null],
        ['defaultValue' => ['statamic_attribute' => 'default', 'get_selected_choice' => true]],
        [],
        null,
        'radio'
    ))->toBe([]);
});
