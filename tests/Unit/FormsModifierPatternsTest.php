<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms modifier resolution (#98).
 *
 * The mapping modifiers `separate_value_field_option`, `preserve_newlines`,
 * `choices_as_fields` and `get_selected_choice` are each resolved by
 * `processModifierPatterns()`, in a fixed order:
 * `based_on_attribute_value` (`specific`, then `default`),
 * `based_on_other_attribute_value` (`specific`, then `default`), the field's
 * `specific` fieldtype, then `default`. Other-attribute tables are matched by
 * `matchModifierOtherAttributeValue()`. A `based_on_attribute_value` match
 * resolves to the value it maps to, so one mapping to `false` resolves to
 * `false`. No standalone test named either method before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsModifierPatternsTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Resolve a modifier configuration.
 *
 * @param  mixed  $config  The modifier configuration
 * @param  array<int|string, mixed>  $data  The field data
 * @param  ?string  $fieldtype  The field's source fieldtype
 * @param  ?string  $attrName  The attribute being mapped
 * @param  mixed  $attrValue  The attribute's value
 * @param  ?callable  $processor  The result processor
 * @return mixed The `value` and `matchFound` pair
 */
function resolveModifier(
    mixed $config,
    array $data = [],
    ?string $fieldtype = null,
    ?string $attrName = null,
    mixed $attrValue = null,
    ?callable $processor = null
): mixed {
    return new ReflectionMethod(
        FieldtypeMapping::class,
        'processModifierPatterns'
    )->invoke(
        null,
        $config,
        $data,
        $processor,
        $fieldtype,
        $attrName,
        $attrValue
    );
}

/**
 * The shipped Gravity Forms `get_selected_choice` modifier on `defaultValue`.
 *
 * @return array<string, mixed> The modifier configuration
 */
function selectedChoiceModifier(): array
{
    $choiceTypes = ['radio' => 'choices.isSelected', 'checkbox' => 'choices.isSelected'];

    return [
        'default' => null,
        'specific' => $choiceTypes,
        'based_on_other_attribute_value' => ['default' => ['inputType' => $choiceTypes]],
    ];
}

it('resolves a modifier that is not an array', function (
    mixed $config,
    array $data,
    array $expected
): void {
    expect(resolveModifier($config, $data))->toBe($expected);
})->with([
    'an attribute name that is set' => ['enableColumns', ['enableColumns' => 1], ['value' => true, 'matchFound' => true]],
    'an attribute name that is falsy' => ['enableColumns', ['enableColumns' => '0'], ['value' => false, 'matchFound' => true]],
    'an attribute name that is not set' => ['enableColumns', [], ['value' => false, 'matchFound' => true]],
    'an attribute name set to null' => ['enableColumns', ['enableColumns' => null], ['value' => false, 'matchFound' => true]],
    'true' => [true, [], ['value' => true, 'matchFound' => true]],
    'null' => [null, [], ['value' => null, 'matchFound' => true]],
]);

it('passes a resolved value through the result processor', function (): void {
    $processor = fn (mixed $value): array => ['processed' => $value];

    expect(resolveModifier(true, processor: $processor))->toBe(['value' => ['processed' => true], 'matchFound' => true])
        ->and(resolveModifier(['default' => 'x'], processor: $processor))->toBe(['value' => ['processed' => 'x'], 'matchFound' => true])
        ->and(resolveModifier(['based_on_attribute_value' => ['default' => ['a' => 'x']]], [], null, 'n', 'a', $processor))
        ->toBe(['value' => ['processed' => 'x'], 'matchFound' => true]);
});

it('resolves the shipped fieldtype modifiers from specific, then default', function (
    array $config,
    ?string $fieldtype,
    array $expected
): void {
    expect(resolveModifier($config, [], $fieldtype))->toBe($expected);
})->with([
    'preserve_newlines on consent' => [['default' => false, 'specific' => ['consent' => true]], 'consent', ['value' => true, 'matchFound' => true]],
    'preserve_newlines on text' => [['default' => false, 'specific' => ['consent' => true]], 'text', ['value' => false, 'matchFound' => true]],
    'separate_value_field_option on survey' => [['default' => 'enableChoiceValue', 'specific' => ['survey' => null]], 'survey', ['value' => false, 'matchFound' => true]],
    'separate_value_field_option on radio' => [['default' => 'enableChoiceValue', 'specific' => ['survey' => null]], 'radio', ['value' => true, 'matchFound' => true]],
    'no fieldtype' => [['default' => false, 'specific' => ['' => true]], null, ['value' => false, 'matchFound' => true]],
    'an empty fieldtype' => [['default' => false, 'specific' => ['' => true]], '', ['value' => false, 'matchFound' => true]],
    'nothing that applies' => [['specific' => ['consent' => true]], 'text', ['value' => false, 'matchFound' => false]],
]);

it('resolves choices_as_fields from enableColumns', function (
    array $data,
    bool $expected
): void {
    $config = [
        'default' => false,
        'based_on_other_attribute_value' => ['default' => ['enableColumns' => ['true' => true, 'false' => false, 1 => true, 0 => null]]],
    ];

    expect(resolveModifier($config, $data, 'list'))->toBe(['value' => $expected, 'matchFound' => true]);
})->with([
    'true' => [['enableColumns' => true], true],
    'the string true' => [['enableColumns' => 'true'], true],
    'one' => [['enableColumns' => 1], true],
    'false' => [['enableColumns' => false], false],
    'the string false' => [['enableColumns' => 'false'], false],
    'zero, which maps to null and falls to the default' => [['enableColumns' => 0], false],
    'not set' => [[], false],
]);

it('resolves get_selected_choice from the input type before the fieldtype', function (
    array $data,
    string $fieldtype,
    bool $expected
): void {
    expect(resolveModifier(selectedChoiceModifier(), $data, $fieldtype))->toBe(['value' => $expected, 'matchFound' => true]);
})->with([
    'a radio field' => [[], 'radio', true],
    'a survey with a radio input' => [['inputType' => 'radio'], 'survey', true],
    'a survey with a likert input' => [['inputType' => 'likert'], 'survey', false],
    'a text field' => [[], 'text', false],
]);

it('resolves a modifier from the attribute value, including to false', function (
    array $config,
    ?string $fieldtype,
    array $data,
    mixed $attrValue,
    array $expected
): void {
    expect(resolveModifier($config, $data, $fieldtype, 'enableX', $attrValue))->toBe($expected);
})->with([
    'a default pattern mapping to false' => [['based_on_attribute_value' => ['default' => ['yes' => false, 'on' => true]], 'default' => true], null, [], 'yes', ['value' => false, 'matchFound' => true]],
    'a default pattern mapping to true' => [['based_on_attribute_value' => ['default' => ['yes' => false, 'on' => true]], 'default' => false], null, [], 'on', ['value' => true, 'matchFound' => true]],
    'a default pattern mapping to null' => [['based_on_attribute_value' => ['default' => ['yes' => null]], 'default' => true], null, [], 'yes', ['value' => false, 'matchFound' => true]],
    'no pattern that matches' => [['based_on_attribute_value' => ['default' => ['yes' => false]], 'default' => true], null, [], 'no', ['value' => true, 'matchFound' => true]],
    'an attribute that is not set' => [['based_on_attribute_value' => ['default' => ['#not_set' => false]], 'default' => true], null, [], null, ['value' => false, 'matchFound' => true]],
    'an attribute that is not empty' => [['based_on_attribute_value' => ['default' => ['#not_empty' => false]], 'default' => true], null, ['enableX' => 'v'], 'v', ['value' => false, 'matchFound' => true]],
    'a specific fieldtype pattern' => [['based_on_attribute_value' => ['specific' => ['specific' => ['radio' => ['a' => false]], 'default' => ['a' => true]]], 'default' => true], 'radio', [], 'a', ['value' => false, 'matchFound' => true]],
    'the specific default pattern for another fieldtype' => [['based_on_attribute_value' => ['specific' => ['specific' => ['radio' => ['a' => true]], 'default' => ['a' => false]]], 'default' => true], 'text', [], 'a', ['value' => false, 'matchFound' => true]],
    'the attribute value before the other attribute' => [['based_on_attribute_value' => ['default' => ['a' => false]], 'based_on_other_attribute_value' => ['default' => ['t' => ['x' => true]]]], null, ['t' => 'x'], 'a', ['value' => false, 'matchFound' => true]],
]);

it('resolves a modifier from another attribute, specific before default', function (
    array $config,
    ?string $fieldtype,
    array $expected
): void {
    expect(resolveModifier($config, ['t' => 'x'], $fieldtype))->toBe($expected);
})->with([
    'a specific fieldtype table' => [['based_on_other_attribute_value' => ['specific' => ['specific' => ['radio' => ['t' => ['x' => false]]], 'default' => ['t' => ['x' => true]]]], 'default' => true], 'radio', ['value' => false, 'matchFound' => true]],
    'the specific default table for another fieldtype' => [['based_on_other_attribute_value' => ['specific' => ['specific' => ['radio' => ['t' => ['x' => true]]], 'default' => ['t' => ['x' => false]]]], 'default' => true], 'text', ['value' => false, 'matchFound' => true]],
    'a table directly under specific' => [['based_on_other_attribute_value' => ['specific' => ['t' => ['x' => false]]], 'default' => true], 'text', ['value' => false, 'matchFound' => true]],
    'specific before default' => [['based_on_other_attribute_value' => ['specific' => ['t' => ['x' => true]], 'default' => ['t' => ['x' => false]]]], null, ['value' => true, 'matchFound' => true]],
    'no table that matches' => [['based_on_other_attribute_value' => ['specific' => ['t' => ['y' => true]], 'default' => ['t' => ['y' => true]]], 'default' => false], null, ['value' => false, 'matchFound' => true]],
]);

it('matches another attribute value against its table', function (
    mixed $mappings,
    array $data,
    mixed $expected
): void {
    expect(new ReflectionMethod(FieldtypeMapping::class, 'matchModifierOtherAttributeValue')->invoke(null, $mappings, $data))->toBe($expected);
})->with([
    'a match' => [['t' => ['x' => 'X']], ['t' => 'x'], 'X'],
    'a match in a later table' => [['u' => ['x' => 'U'], 't' => ['x' => 'T']], ['t' => 'x'], 'T'],
    'an attribute that is not set' => [['t' => ['x' => 'X']], [], null],
    'a value with no entry' => [['t' => ['x' => 'X']], ['t' => 'y'], null],
    'a value that is an array' => [['t' => ['x' => 'X']], ['t' => ['x']], null],
    'a table that is not an array' => [['t' => 'x'], ['t' => 'x'], null],
    'mappings that are not an array' => ['t', ['t' => 'x'], null],
]);
