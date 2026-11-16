<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms attribute-value-conditional mapping (#98).
 *
 * A mapping can choose its Statamic attribute from the attribute's own value
 * (`based_on_attribute_value`) or from another attribute's value
 * (`based_on_other_attribute_value`). `processBasedOnAttributeValue()` and
 * `processBasedOnOtherAttributeValue()` read the fieldtype's `specific` table,
 * then `default`, then a legacy table with neither key, and return a
 * `[value, found]` pair. `processPatternMappings()` matches the attribute's own
 * value, and `processBasedOnOtherAttributeValueMapping()` is the subfield
 * variant, which returns the resolved attribute with the value. No standalone
 * test named any of these methods before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsBasedOnAttributeValueTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsBasedOnMethod(
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

it('drops a Gravity Forms default value that is a date merge tag', function (): void {
    $field = static fn (string $defaultValue): array => [
        'id' => 2,
        'type' => 'text',
        'label' => 'Visit',
        'defaultValue' => $defaultValue,
    ];

    expect(FieldtypeMapping::mapFieldAttributes($field('{date_mdy}'), [$field('{date_mdy}')], 'text'))->not->toHaveKey('default')
        ->and(FieldtypeMapping::mapFieldAttributes($field('Clinic'), [$field('Clinic')], 'text')['default'] ?? 'absent')->toBe('Clinic');
});

it('matches the attribute value against each pattern in turn', function (
    array $patterns,
    array $data,
    mixed $attrValue,
    ?array $expected
): void {
    expect(invokeFormsBasedOnMethod('processPatternMappings', $patterns, 'attr', $attrValue, $data))->toBe($expected);
})->with([
    'an exact value' => [['a' => 'A', 'b' => 'B'], ['attr' => 'b'], 'b', ['B', true]],
    'a value mapping to null' => [['a' => null], ['attr' => 'a'], 'a', [null, true]],
    'the first that matches' => [['#not_empty' => 'any', 'a' => 'A'], ['attr' => 'a'], 'a', ['any', true]],
    'an attribute that is not set' => [['#not_set' => 'unset', 'a' => 'A'], [], null, ['unset', true]],
    'an attribute that is set, against #not_set' => [['#not_set' => 'unset'], ['attr' => 'a'], 'a', null],
    'an attribute that is empty, against #not_empty' => [['#not_empty' => 'any'], ['attr' => ''], '', null],
    'no pattern that matches' => [['a' => 'A'], ['attr' => 'z'], 'z', null],
    'no patterns' => [[], ['attr' => 'a'], 'a', null],
]);

it('resolves an attribute-value table from specific, default, then legacy', function (
    mixed $config,
    ?string $fieldtype,
    mixed $attrValue,
    ?array $expected
): void {
    expect(invokeFormsBasedOnMethod('processBasedOnAttributeValue', $config, $fieldtype, 'attr', $attrValue, ['attr' => $attrValue]))->toBe($expected);
})->with([
    'the fieldtype table' => [['specific' => ['radio' => ['a' => 'R']], 'default' => ['a' => 'D']], 'radio', 'a', ['R', true]],
    'the default after a fieldtype table with no match' => [['specific' => ['radio' => ['b' => 'R']], 'default' => ['a' => 'D']], 'radio', 'a', ['D', true]],
    'the default for another fieldtype' => [['specific' => ['radio' => ['a' => 'R']], 'default' => ['a' => 'D']], 'text', 'a', ['D', true]],
    'the default with no fieldtype' => [['specific' => ['' => ['a' => 'R']], 'default' => ['a' => 'D']], null, 'a', ['D', true]],
    'a legacy fieldtype table' => [['radio' => ['a' => 'R'], 'a' => 'G'], 'radio', 'a', ['R', true]],
    'a legacy table for another fieldtype' => [['radio' => ['a' => 'R'], 'a' => 'G'], 'text', 'a', ['G', true]],
    'a legacy fieldtype table with no match' => [['radio' => ['b' => 'R'], 'a' => 'G'], 'radio', 'a', null],
    'a string' => ['label', 'text', 'a', ['label', true]],
    'no match' => [['specific' => ['radio' => ['a' => 'R']], 'default' => ['b' => 'D']], 'text', 'a', null],
    'a fieldtype table with no match, never read as a legacy table' => [['specific' => ['a' => 'R']], 'specific', 'a', null],
    'an empty table' => [[], 'text', 'a', null],
    'a number' => [5, 'text', 'a', null],
]);

it('resolves an other-attribute table from specific, default, then legacy', function (
    mixed $config,
    ?string $fieldtype,
    array $data,
    ?array $expected
): void {
    expect(invokeFormsBasedOnMethod('processBasedOnOtherAttributeValue', $config, $fieldtype, $data))->toBe($expected);
})->with([
    'the fieldtype table' => [['specific' => ['radio' => ['t' => ['x' => 'R']]], 'default' => ['t' => ['x' => 'D']]], 'radio', ['t' => 'x'], ['R', true]],
    'the default after a fieldtype table with no match' => [['specific' => ['radio' => ['t' => ['y' => 'R']]], 'default' => ['t' => ['x' => 'D']]], 'radio', ['t' => 'x'], ['D', true]],
    'the default for another fieldtype' => [['specific' => ['radio' => ['t' => ['x' => 'R']]], 'default' => ['t' => ['x' => 'D']]], 'text', ['t' => 'x'], ['D', true]],
    'a later attribute in the default' => [['default' => ['u' => ['x' => 'U'], 't' => ['x' => 'T']]], null, ['t' => 'x'], ['T', true]],
    'a legacy table' => [['t' => ['x' => 'L']], null, ['t' => 'x'], ['L', true]],
    'an unset attribute, against #not_set' => [['default' => ['t' => ['#not_set' => 'N']]], null, [], ['N', true]],
    'an empty attribute, against #empty' => [['default' => ['t' => ['#empty' => 'E']]], null, ['t' => ''], ['E', true]],
    'a set attribute, against #not_empty' => [['default' => ['t' => ['#not_empty' => 'S']]], null, ['t' => 'x'], ['S', true]],
    'an array value, by its JSON' => [['default' => ['t' => ['["a","b"]' => 'J']]], null, ['t' => ['a', 'b']], ['J', true]],
    'an integer value' => [['default' => ['t' => [5 => 'I']]], null, ['t' => 5], ['I', true]],
    'a string' => ['label', null, [], ['label', true]],
    'no match' => [['default' => ['t' => ['y' => 'D']]], null, ['t' => 'x'], null],
    'a fieldtype table with no match, never read as a legacy table' => [['specific' => ['x' => 'L']], 'text', ['specific' => 'x'], null],
    'an empty table' => [[], null, ['t' => 'x'], null],
]);

it('resolves a subfield attribute from another attribute, with its value', function (
    array $config,
    array $data,
    ?array $expected
): void {
    expect(invokeFormsBasedOnMethod('processBasedOnOtherAttributeValueMapping', $config, $data, 'value'))->toBe($expected);
})->with([
    'a default table' => [['default' => ['t' => ['x' => 'label']]], ['t' => 'x'], ['label', 'value']],
    'a legacy table' => [['t' => ['x' => 'label']], ['t' => 'x'], ['label', 'value']],
    'a later attribute' => [['u' => ['x' => 'U'], 't' => ['x' => 'T']], ['t' => 'x'], ['T', 'value']],
    'a match resolving to null' => [['t' => ['x' => null]], ['t' => 'x'], [null, 'value']],
    'no match' => [['t' => ['y' => 'label']], ['t' => 'x'], null],
    'an unset attribute' => [['t' => ['x' => 'label']], [], null],
    'only a specific table, which is not read' => [['specific' => ['t' => ['x' => 'label']]], ['t' => 'x'], null],
]);
