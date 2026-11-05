<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms returning modifier resolution and the field-option
 * emptiness checks (#98).
 *
 * `processModifierPatternsWithReturn()` resolves a modifier in the same order as
 * `processModifierPatterns()` (`based_on_attribute_value`, then
 * `based_on_other_attribute_value`, then the fieldtype's `specific` entry, then
 * `default`) but returns the value itself, or a fallback; `getExpectedKeys()`
 * reads a field's expected option keys through it. A `based_on_attribute_value`
 * match returns the value it maps to. `isFieldOptionEmpty()` and
 * `isFieldOptionNotEmpty()` read an attribute from a Formidable field's
 * `field_options` JSON, and `mappingLookupKey()`, `numericChoiceCount()` and
 * `resolveOptionalStringMappingValue()` are the small conversions beside them.
 * No standalone test named any of these methods before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsReturningModifierPatternsTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a non-public static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsReturningMethod(
    string $method,
    mixed ...$arguments
): mixed {
    return new ReflectionMethod(
        FieldtypeMapping::class,
        $method
    )->invoke(
        null,
        ...$arguments
    );
}

/**
 * Resolve a modifier configuration to its value, or the fallback `'fallback'`.
 *
 * @param  mixed  $config  The modifier configuration
 * @param  mixed  $attrValue  The attribute's value
 * @param  array<int|string, mixed>  $data  The field data
 * @param  ?string  $fieldtype  The field's source fieldtype
 * @param  ?callable  $processor  The result processor
 * @return mixed The resolved value
 */
function resolveReturningModifier(
    mixed $config,
    mixed $attrValue = null,
    array $data = [],
    ?string $fieldtype = null,
    ?callable $processor = null
): mixed {
    return invokeFormsReturningMethod(
        'processModifierPatternsWithReturn',
        $config,
        $attrValue,
        $data,
        'fallback',
        $processor,
        $fieldtype,
        'attr'
    );
}

it('reads the shipped expected keys for a fieldtype, through the returning resolution', function (): void {
    expect(FieldtypeMapping::getExpectedKeys([], null, 'product'))->toBe(['id', 'key', 'label', 'price'])
        ->and(FieldtypeMapping::getExpectedKeys([], null, 'radio'))->toBe(['key', 'value']);
});

it('resolves a returning modifier to the value it maps to', function (
    mixed $config,
    mixed $attrValue,
    array $data,
    ?string $fieldtype,
    mixed $expected
): void {
    expect(resolveReturningModifier($config, $attrValue, $data, $fieldtype))->toBe($expected);
})->with([
    'a value that is not an array' => ['keys', null, [], null, 'keys'],
    'an attribute-value default pattern' => [['based_on_attribute_value' => ['default' => ['a' => ['x', 'y']]], 'default' => ['d']], 'a', ['attr' => 'a'], null, ['x', 'y']],
    'an attribute-value pattern mapping to false' => [['based_on_attribute_value' => ['default' => ['a' => false]], 'default' => true], 'a', ['attr' => 'a'], null, false],
    'an attribute-value pattern mapping to null' => [['based_on_attribute_value' => ['default' => ['a' => null]], 'default' => true], 'a', ['attr' => 'a'], null, null],
    'an attribute-value fieldtype pattern' => [['based_on_attribute_value' => ['specific' => ['specific' => ['radio' => ['a' => 'R']], 'default' => ['a' => 'D']]]], 'a', ['attr' => 'a'], 'radio', 'R'],
    'the attribute-value specific default for another fieldtype' => [['based_on_attribute_value' => ['specific' => ['specific' => ['radio' => ['a' => 'R']], 'default' => ['a' => 'D']]]], 'a', ['attr' => 'a'], 'text', 'D'],
    'an other-attribute fieldtype table' => [['based_on_other_attribute_value' => ['specific' => ['specific' => ['radio' => ['t' => ['x' => 'R']]], 'default' => ['t' => ['x' => 'D']]]]], null, ['t' => 'x'], 'radio', 'R'],
    'the other-attribute specific default for another fieldtype' => [['based_on_other_attribute_value' => ['specific' => ['specific' => ['radio' => ['t' => ['x' => 'R']]], 'default' => ['t' => ['x' => 'D']]]]], null, ['t' => 'x'], 'text', 'D'],
    'an other-attribute table directly under specific' => [['based_on_other_attribute_value' => ['specific' => ['t' => ['x' => 'L']]]], null, ['t' => 'x'], null, 'L'],
    'an other-attribute default table' => [['based_on_other_attribute_value' => ['default' => ['t' => ['x' => 'O']]], 'default' => 'd'], null, ['t' => 'x'], null, 'O'],
    'the fieldtype entry' => [['default' => 'd', 'specific' => ['radio' => 'r']], null, [], 'radio', 'r'],
    'the default for another fieldtype' => [['default' => 'd', 'specific' => ['radio' => 'r']], null, [], 'text', 'd'],
    'the default with an empty fieldtype' => [['default' => 'd', 'specific' => ['' => 'r']], null, [], '', 'd'],
    'the fallback when nothing applies' => [['specific' => ['radio' => 'r']], null, [], 'text', 'fallback'],
    'the fallback after tables with no match' => [['based_on_attribute_value' => ['default' => ['a' => 'A']], 'based_on_other_attribute_value' => ['specific' => ['t' => ['y' => 'L']], 'default' => ['t' => ['y' => 'O']]]], 'z', ['attr' => 'z', 't' => 'x'], null, 'fallback'],
]);

it('passes a resolved value through the result processor', function (): void {
    $processor = fn (mixed $value): array => ['processed' => $value];

    expect(resolveReturningModifier('keys', processor: $processor))->toBe(['processed' => 'keys'])
        ->and(resolveReturningModifier(['based_on_attribute_value' => ['default' => ['a' => 'A']]], 'a', ['attr' => 'a'], null, $processor))->toBe(['processed' => 'A'])
        ->and(resolveReturningModifier(['based_on_other_attribute_value' => ['default' => ['t' => ['x' => 'O']]]], null, ['t' => 'x'], null, $processor))->toBe(['processed' => 'O'])
        ->and(resolveReturningModifier(['specific' => ['radio' => 'r']], null, [], 'radio', $processor))->toBe(['processed' => 'r'])
        ->and(resolveReturningModifier(['default' => 'd'], null, [], null, $processor))->toBe(['processed' => 'd'])
        ->and(resolveReturningModifier([], null, [], null, $processor))->toBe('fallback');
});

it('reads whether a field option is empty or not from field_options', function (
    mixed $fieldOptions,
    bool $empty,
    bool $notEmpty
): void {
    $field = ['field_options' => $fieldOptions];

    expect(invokeFormsReturningMethod('isFieldOptionEmpty', 'size', $field))->toBe($empty)
        ->and(invokeFormsReturningMethod('isFieldOptionNotEmpty', 'size', $field))->toBe($notEmpty);
})->with([
    'a value' => ['{"size":"10"}', false, true],
    'zero' => ['{"size":0}', false, true],
    'an empty string' => ['{"size":""}', true, false],
    'null' => ['{"size":null}', false, false],
    'an empty list' => ['{"size":[]}', false, false],
    'an object of empty values' => ['{"size":{"a":"","b":null}}', false, false],
    'an object with a value' => ['{"size":{"a":"","b":"x"}}', false, true],
    'not set' => ['{"other":"x"}', false, false],
    'invalid JSON' => ['{not json', false, false],
    'field_options that are not a string' => [['size' => '10'], false, false],
]);

it('reads no field option for a missing name or missing field_options', function (): void {
    foreach (['isFieldOptionEmpty', 'isFieldOptionNotEmpty'] as $method) {
        expect(invokeFormsReturningMethod($method, null, ['field_options' => '{"":""}']))->toBeFalse()
            ->and(invokeFormsReturningMethod($method, 'size', []))->toBeFalse();
    }
});

it('converts values to lookup keys, choice counts and optional strings', function (): void {
    expect(invokeFormsReturningMethod('mappingLookupKey', ['a', 'b']))->toBe('["a","b"]')
        ->and(invokeFormsReturningMethod('mappingLookupKey', 5))->toBe(5)
        ->and(invokeFormsReturningMethod('mappingLookupKey', 'x'))->toBe('x')
        ->and(invokeFormsReturningMethod('mappingLookupKey', true))->toBe('1')
        ->and(invokeFormsReturningMethod('mappingLookupKey', null))->toBe('')
        ->and(invokeFormsReturningMethod('numericChoiceCount', 0))->toBe(1)
        ->and(invokeFormsReturningMethod('numericChoiceCount', '4'))->toBe(5)
        ->and(invokeFormsReturningMethod('numericChoiceCount', 'a'))->toBeNull()
        ->and(invokeFormsReturningMethod('resolveOptionalStringMappingValue', 'x'))->toBe('x')
        ->and(invokeFormsReturningMethod('resolveOptionalStringMappingValue', 5))->toBeNull();
});
