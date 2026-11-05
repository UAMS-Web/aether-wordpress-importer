<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms single-attribute mapping and its guard (#98).
 *
 * `processAttributeMapping()` resolves one attribute's `statamic_attribute` in
 * a fixed order: the own-value table (`based_on_attribute_value`: `specific`,
 * `default`, then legacy), the other-attribute table
 * (`based_on_other_attribute_value`: `specific`, `default`, then legacy), then
 * the configuration's own `specific` and `default`, applying any
 * `value_translation` to the result and handing a configuration it cannot
 * resolve to `mapAttributesFromMapping()`. A table entry mapped to `null`
 * drops the attribute. `processAttributeIfValid()` is the guard in front of it
 * for subfield mapping. No standalone test named either method before this
 * file.
 *
 * This method passes no fieldtype on, so an own-value table with only a
 * `specific` entry never matches here, and a configuration-level `specific`
 * fieldtype table resolves to an empty attribute name. The one shipped mapping
 * that reaches the second case, Gravity Forms `rcwdupload_repeater`, still
 * maps `max_files` correctly end to end, which the last test asserts.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsAttributeMappingPrecedenceTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a non-public static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsPrecedenceMethod(
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

it('resolves an attribute through its tables in order', function (
    mixed $attrValue,
    array $config,
    array $data,
    ?array $expected
): void {
    expect(invokeFormsPrecedenceMethod('processAttributeMapping', 'a', $attrValue, $config, $data, null, null))->toBe($expected);
})->with([
    'a string' => ['v', ['statamic_attribute' => 'target'], [], ['target' => 'v']],
    'no statamic_attribute' => ['v', ['x' => 1], [], null],
    'an own-value specific table, never read' => ['x', ['statamic_attribute' => ['based_on_attribute_value' => ['specific' => ['radio' => ['x' => 'S']]]]], ['a' => 'x'], []],
    'an own-value default beside a specific table' => ['x', ['statamic_attribute' => ['based_on_attribute_value' => ['specific' => ['radio' => ['x' => 'S']], 'default' => ['x' => 'D']]]], ['a' => 'x'], ['D' => 'x']],
    'an own-value default table' => ['x', ['statamic_attribute' => ['based_on_attribute_value' => ['default' => ['x' => 'D']]]], ['a' => 'x'], ['D' => 'x']],
    'an own-value default mapped to null' => ['x', ['statamic_attribute' => ['based_on_attribute_value' => ['default' => ['x' => null]], 'default' => 'F']], ['a' => 'x'], null],
    'an own-value miss, then the default' => ['y', ['statamic_attribute' => ['based_on_attribute_value' => ['default' => ['x' => 'D']], 'default' => 'F']], ['a' => 'y'], ['F' => 'y']],
    'an own-value legacy table' => ['x', ['statamic_attribute' => ['based_on_attribute_value' => ['x' => 'L']]], ['a' => 'x'], ['L' => 'x']],
    'an own-value legacy entry mapped to null' => ['x', ['statamic_attribute' => ['based_on_attribute_value' => ['x' => null]]], ['a' => 'x'], null],
    'an other-attribute specific table' => ['v', ['statamic_attribute' => ['based_on_other_attribute_value' => ['specific' => ['t' => ['k' => 'S']]]]], ['t' => 'k'], ['S' => 'v']],
    'an other-attribute specific entry mapped to null' => ['v', ['statamic_attribute' => ['based_on_other_attribute_value' => ['specific' => ['t' => ['k' => null]]], 'default' => 'F']], ['t' => 'k'], null],
    'an other-attribute default table' => ['v', ['statamic_attribute' => ['based_on_other_attribute_value' => ['default' => ['t' => ['k' => 'D']]]]], ['t' => 'k'], ['D' => 'v']],
    'an other-attribute default entry mapped to null' => ['v', ['statamic_attribute' => ['based_on_other_attribute_value' => ['default' => ['t' => ['k' => null]]], 'default' => 'F']], ['t' => 'k'], null],
    'an other-attribute legacy table' => ['v', ['statamic_attribute' => ['based_on_other_attribute_value' => ['t' => ['k' => 'L']]]], ['t' => 'k'], ['L' => 'v']],
    'an other-attribute legacy entry mapped to null' => ['v', ['statamic_attribute' => ['based_on_other_attribute_value' => ['t' => ['k' => null]], 'default' => 'F']], ['t' => 'k'], null],
    'an other-attribute miss, then specific before default' => ['v', ['statamic_attribute' => ['based_on_other_attribute_value' => ['default' => ['t' => ['z' => 'D']]], 'specific' => 'SP', 'default' => 'F']], ['t' => 'k'], ['SP' => 'v']],
    'only a default' => ['v', ['statamic_attribute' => ['default' => 'F']], [], ['F' => 'v']],
    'a specific fieldtype table, resolved to an empty name' => ['x', ['statamic_attribute' => ['specific' => ['rcwdupload' => 'max_files']]], [], ['' => 'x']],
    'an empty array, handed on' => ['v', ['statamic_attribute' => []], ['a' => 'v'], []],
    'an unresolved array, handed on' => ['v', ['statamic_attribute' => ['other' => 1]], ['a' => 'v'], []],
    'a number' => ['v', ['statamic_attribute' => 5], [], [5 => 'v']],
    'a string with a placeholder, left for the caller' => ['v', ['statamic_attribute' => '*_x'], [], ['*_x' => 'v']],
]);

it('translates the value of a resolved attribute', function (): void {
    $translation = ['value_translation' => ['default' => [['search' => 'yes', 'replace' => true], ['search' => 'x', 'replace' => 'X']]]];

    expect(invokeFormsPrecedenceMethod('processAttributeMapping', 'a', 'yes', ['statamic_attribute' => ['default' => 'F']] + $translation, [], null, null))->toBe(['F' => true])
        ->and(invokeFormsPrecedenceMethod('processAttributeMapping', 'a', 'x', ['statamic_attribute' => ['based_on_attribute_value' => ['default' => ['x' => 'D']]]] + $translation, ['a' => 'x'], null, null))->toBe(['D' => 'X'])
        ->and(invokeFormsPrecedenceMethod('processAttributeMapping', 'a', 'yes', ['statamic_attribute' => 't'] + $translation, [], null, null))->toBe(['t' => true]);
});

it('hands nothing on for an unresolved attribute with no name', function (): void {
    expect(invokeFormsPrecedenceMethod('processAttributeMapping', null, 'v', ['statamic_attribute' => ['other' => 1]], ['a' => 'v'], null, null))->toBeNull();
});

it('maps a subfield attribute only when it is mapped, unprocessed and not empty', function (
    int|string|null $attrName,
    mixed $attrValue,
    array $mappings,
    array $subfieldData,
    ?array $expected
): void {
    expect(invokeFormsPrecedenceMethod('processAttributeIfValid', $attrName, $attrValue, $mappings, [], $subfieldData, null, null))->toBe($expected);
})->with([
    'a mapped attribute' => ['a', 'v', ['a' => ['statamic_attribute' => 'A']], [], ['A' => 'v']],
    'an integer name' => [0, 'v', [0 => ['statamic_attribute' => 'Z']], [], ['Z' => 'v']],
    'zero' => ['a', 0, ['a' => ['statamic_attribute' => 'A']], [], ['A' => 0]],
    'false' => ['a', false, ['a' => ['statamic_attribute' => 'A']], [], ['A' => false]],
    'a subfield that carries another attribute' => ['a', 'v', ['a' => ['statamic_attribute' => 'A']], ['b' => 's'], ['A' => 'v']],
    'a subfield that carries it as null' => ['a', 'v', ['a' => ['statamic_attribute' => 'A']], ['a' => null], ['A' => 'v']],
    'no name' => [null, 'v', ['a' => ['statamic_attribute' => 'A']], [], null],
    'an unmapped attribute' => ['z', 'v', ['a' => ['statamic_attribute' => 'A']], [], null],
    'an attribute the subfield already carries' => ['a', 'v', ['a' => ['statamic_attribute' => 'A']], ['a' => 's'], null],
    'no statamic_attribute' => ['a', 'v', ['a' => ['x' => 1]], [], null],
    'a mapping that is not an array' => ['a', 'v', ['a' => 'A'], [], null],
    'null' => ['a', null, ['a' => ['statamic_attribute' => 'A']], [], null],
    'an empty string' => ['a', '', ['a' => ['statamic_attribute' => 'A']], [], null],
]);

it('maps the shipped Gravity Forms upload repeater limit end to end', function (
    ?string $repeater,
    mixed $expected
): void {
    $field = ['id' => 3, 'type' => 'rcwdupload', 'label' => 'Upload'];
    if ($repeater !== null) {
        $field['rcwdupload_repeater'] = $repeater;
    }

    expect(FieldtypeMapping::mapFieldAttributes($field, [$field], 'rcwdupload')['max_files'] ?? 'absent')->toBe($expected);
})->with([
    'unset' => [null, 1],
    'empty' => ['', 1],
    'N' => ['N', 1],
    'Y' => ['Y', 'absent'],
    'a count' => ['5', '5'],
]);
