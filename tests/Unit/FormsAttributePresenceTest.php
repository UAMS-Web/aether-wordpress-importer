<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms attribute-presence checks and key helpers (#98).
 *
 * The `#not_set`, `#not_empty` and `#empty` placeholders in a mapping are
 * decided by `FieldtypeMappingTrait::isAttributeNotSet()`,
 * `isAttributeNotEmpty()` and `isAttributeEmpty()` over the parsed field, or by
 * their `isFieldOption*()` counterparts over the Formidable `field_options`
 * JSON when the original field data is given. The file also covers the key and
 * value normalizers those checks rest on, the conditional-config resolvers
 * that read the same placeholders, and `isAssociativeArray()`, on which an
 * empty `nested_attribute` pattern once matched every array. No standalone
 * test named any of these methods before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsAttributePresenceTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsPresenceMethod(
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
 * Wrap attributes in the original field data shape that carries `field_options`.
 *
 * @param  array<int|string, mixed>  $options  The field options
 * @return array<string, string> The original field data
 */
function presenceFieldOptions(
    array $options
): array {
    return ['field_options' => (string) \json_encode($options)];
}

it('decides the presence placeholders over the parsed field', function (
    int|string|null $attribute,
    array $data,
    bool $notSet,
    bool $notEmpty,
    bool $empty
): void {
    expect(invokeFormsPresenceMethod('isAttributeNotSetWithFallback', $attribute, $data))->toBe($notSet)
        ->and(invokeFormsPresenceMethod('isAttributeNotEmptyWithFallback', $attribute, $data))->toBe($notEmpty)
        ->and(invokeFormsPresenceMethod('isAttributeEmptyWithFallback', $attribute, $data))->toBe($empty);
})->with([
    'a value' => ['x', ['x' => 'v'], false, true, false],
    'zero' => ['x', ['x' => 0], false, true, false],
    'false' => ['x', ['x' => false], false, true, false],
    'an empty string' => ['x', ['x' => ''], false, false, true],
    'null' => ['x', ['x' => null], false, false, false],
    'an empty array' => ['x', ['x' => []], false, false, false],
    'an array of empty values' => ['x', ['x' => ['a' => '', 'b' => null, 'c' => []]], false, false, false],
    'an array with one value' => ['x', ['x' => ['a' => '', 'b' => 'v']], false, true, false],
    'a missing attribute' => ['x', ['y' => 'v'], true, false, false],
    'an integer attribute name' => [3, [3 => 'v'], false, true, false],
    'no attribute name, beside an empty-string key' => [null, ['' => 'v'], true, false, false],
]);

it('decides the presence placeholders over field_options when the original field data is given', function (
    int|string|null $attribute,
    array $original,
    bool $notSet,
    bool $notEmpty,
    bool $empty
): void {
    // The parsed field says the opposite each time, so a result that read it
    // instead of field_options would fail.
    $data = ['x' => 'parsed', 'y' => ''];

    expect(invokeFormsPresenceMethod('isAttributeNotSetWithFallback', $attribute, $data, $original))->toBe($notSet)
        ->and(invokeFormsPresenceMethod('isAttributeNotEmptyWithFallback', $attribute, $data, $original))->toBe($notEmpty)
        ->and(invokeFormsPresenceMethod('isAttributeEmptyWithFallback', $attribute, $data, $original))->toBe($empty);
})->with([
    'a value' => ['y', presenceFieldOptions(['y' => 'v']), false, true, false],
    'an empty string' => ['x', presenceFieldOptions(['x' => '']), false, false, true],
    'null' => ['x', presenceFieldOptions(['x' => null]), false, false, false],
    'an empty array' => ['x', presenceFieldOptions(['x' => []]), false, false, false],
    'an array of empty values' => ['x', presenceFieldOptions(['x' => ['a' => '', 'b' => null]]), false, false, false],
    'an array with one value' => ['x', presenceFieldOptions(['x' => ['a' => '', 'b' => 'v']]), false, true, false],
    'a missing attribute' => ['x', presenceFieldOptions(['z' => 'v']), true, false, false],
    'no field_options' => ['x', [], true, false, false],
    'field_options that is not a string' => ['x', ['field_options' => ['x' => '']], true, false, false],
    'field_options that is not JSON' => ['x', ['field_options' => '{nope'], true, false, false],
    'field_options that is a JSON string' => ['x', ['field_options' => '"x"'], true, false, false],
    'no attribute name' => [null, presenceFieldOptions(['x' => 'v']), true, false, false],
]);

it('normalizes a candidate array key', function (
    mixed $key,
    int|string|null $lookup,
    int|string $assignment
): void {
    expect(invokeFormsPresenceMethod('normalizeArrayKey', $key))->toBe($lookup)
        ->and(invokeFormsPresenceMethod('normalizeArrayKeyForAssignment', $key))->toBe($assignment);
})->with([
    'a string' => ['k', 'k', 'k'],
    'an integer' => [7, 7, 7],
    'true' => [true, '1', '1'],
    'false' => [false, '', ''],
    'a float' => [1.5, '1.5', '1.5'],
    'null' => [null, null, ''],
    'an array' => [['k'], null, ''],
]);

it('looks a key up only in a real array', function (): void {
    expect(invokeFormsPresenceMethod('arrayKeyExists', 'k', ['k' => null]))->toBeTrue()
        ->and(invokeFormsPresenceMethod('arrayKeyExists', true, ['1' => 'v']))->toBeTrue()
        ->and(invokeFormsPresenceMethod('arrayKeyExists', 'k', ['j' => 'v']))->toBeFalse()
        ->and(invokeFormsPresenceMethod('arrayKeyExists', null, ['' => 'v']))->toBeFalse()
        ->and(invokeFormsPresenceMethod('arrayKeyExists', 'k', 'k'))->toBeFalse()
        ->and(invokeFormsPresenceMethod('mappingValue', ['k' => 'v'], 'k'))->toBe('v')
        ->and(invokeFormsPresenceMethod('mappingValue', ['k' => 'v'], 'j'))->toBeNull()
        ->and(invokeFormsPresenceMethod('mappingValue', ['k' => 'v'], ['k']))->toBeNull()
        ->and(invokeFormsPresenceMethod('mappingValue', 'k', 'k'))->toBeNull()
        ->and(invokeFormsPresenceMethod('tryMappingValue', ['k' => 'v'], 'k'))->toBe('v')
        ->and(invokeFormsPresenceMethod('tryMappingValue', ['k' => 'v'], 'j'))->toBeNull();
});

it('narrows a mixed value to the shape a caller needs', function (): void {
    expect(invokeFormsPresenceMethod('asArray', ['a' => 1]))->toBe(['a' => 1])
        ->and(invokeFormsPresenceMethod('asArray', 'a'))->toBe([])
        ->and(invokeFormsPresenceMethod('normalizeMixedToArray', [1]))->toBe([1])
        ->and(invokeFormsPresenceMethod('normalizeMixedToArray', null))->toBe([])
        ->and(invokeFormsPresenceMethod('stringValue', 'a'))->toBe('a')
        ->and(invokeFormsPresenceMethod('stringValue', 1))->toBeNull()
        ->and(invokeFormsPresenceMethod('normalizeMixedToString', 'a'))->toBe('a')
        ->and(invokeFormsPresenceMethod('normalizeMixedToString', 3))->toBe('3')
        ->and(invokeFormsPresenceMethod('normalizeMixedToString', 2.5))->toBe('2.5')
        ->and(invokeFormsPresenceMethod('normalizeMixedToString', true))->toBe('1')
        ->and(invokeFormsPresenceMethod('normalizeMixedToString', null))->toBe('')
        ->and(invokeFormsPresenceMethod('normalizeMixedToString', ['a']))->toBe('')
        ->and(invokeFormsPresenceMethod('resolveFieldtypeMappingValue', false))->toBeFalse()
        ->and(invokeFormsPresenceMethod('resolveFieldtypeMappingValue', null))->toBeNull()
        ->and(invokeFormsPresenceMethod('resolveFieldtypeMappingValue', 'text'))->toBe('text')
        ->and(invokeFormsPresenceMethod('resolveFieldtypeMappingValue', 1))->toBeNull()
        ->and(invokeFormsPresenceMethod('normalizeTransformationKey', 4))->toBe('4')
        ->and(invokeFormsPresenceMethod('normalizeTransformationConfig', ['a' => 1], 'k'))->toBe(['a' => 1])
        ->and(invokeFormsPresenceMethod('normalizeTransformationConfig', null, 'k'))->toBeNull()
        ->and(invokeFormsPresenceMethod('normalizeTransformationConfig', 'v', 'k'))->toBe(['k' => 'v']);
});

it('tells an associative array from a list', function (
    array $array,
    bool $expected
): void {
    expect(invokeFormsPresenceMethod('isAssociativeArray', $array))->toBe($expected);
})->with([
    'a list' => [['a', 'b'], false],
    'an empty array' => [[], false],
    'string keys' => [['a' => 1], true],
    'integer keys out of order' => [[1 => 'a', 0 => 'b'], true],
    'integer keys with a gap' => [[0 => 'a', 2 => 'b'], true],
]);

it('matches no value against an empty or missing nested attribute pattern', function (
    mixed $value,
    array $config
): void {
    expect(invokeFormsPresenceMethod('matchesNestedAttributePattern', $value, $config))->toBeFalse();
})->with([
    'an empty pattern and a key/value array' => [['enabled' => true], ['nested_attribute' => []]],
    'an empty pattern and a list of arrays' => [[['enabled' => true]], ['nested_attribute' => []]],
    'no pattern and a key/value array' => [['enabled' => true], []],
    'a key/value pattern and an empty value' => [[], ['nested_attribute' => ['enabled' => true]]],
    'a list pattern and an empty value' => [[], ['nested_attribute' => [['enabled' => true]]]],
]);

it('still matches a non-empty nested attribute pattern', function (): void {
    expect(invokeFormsPresenceMethod('matchesNestedAttributePattern', ['enabled' => true], ['nested_attribute' => ['enabled' => true]]))->toBeTrue()
        ->and(invokeFormsPresenceMethod('matchesNestedAttributePattern', [['enabled' => true]], ['nested_attribute' => [['enabled' => true]]]))->toBeTrue();
});

it('takes the fieldtype-specific conditional block before the default one', function (): void {
    $config = [
        'default' => ['d' => 1],
        'specific' => ['radio' => ['r' => 1]],
    ];

    expect(invokeFormsPresenceMethod('resolveStructuredConditionalConfig', $config, 'radio'))->toBe(['r' => 1])
        ->and(invokeFormsPresenceMethod('resolveStructuredConditionalConfig', $config, 'select'))->toBe(['d' => 1])
        ->and(invokeFormsPresenceMethod('resolveStructuredConditionalConfig', [], 'select'))->toBe([]);
});

it('resolves a conditional string mapping from the field attributes', function (
    array $field,
    ?string $expected
): void {
    $config = [
        'mode' => [
            'a' => 'mapped_a',
            '#not_set' => 'mapped_not_set',
            '#not_empty' => 'mapped_not_empty',
            '' => 'mapped_empty',
        ],
    ];

    expect(invokeFormsPresenceMethod('resolveStringMappingFromConditionalConfig', $config, $field))
        ->toBe($expected);
})->with([
    'an exact value' => [['mode' => 'a'], 'mapped_a'],
    'a missing attribute' => [[], 'mapped_not_set'],
    'another value' => [['mode' => 'b'], 'mapped_not_empty'],
    'an empty string' => [['mode' => ''], 'mapped_empty'],
    'null' => [['mode' => null], null],
]);

it('skips a conditional rule set that has no matching placeholder', function (): void {
    $config = [
        'first' => ['a' => 'from_first'],
        'second' => ['#not_empty' => 7, 'b' => 'from_second'],
    ];

    expect(invokeFormsPresenceMethod('resolveStringMappingFromConditionalConfig', $config, ['first' => 'z', 'second' => 'b']))
        ->toBe('from_second')
        ->and(invokeFormsPresenceMethod('resolveStringMappingFromConditionalConfig', $config, ['first' => 'z', 'second' => 'c']))
        ->toBeNull()
        ->and(invokeFormsPresenceMethod('resolveStringMappingFromConditionalConfig', ['first' => 'not rules'], ['first' => 'a']))
        ->toBeNull();
});
