<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms search-and-replace translation rules (#98).
 *
 * A `value_translation` written as a list of `search`/`replace` pairs goes
 * through `FieldtypeMappingTrait::applyIndexArrayTranslations()`, which takes
 * the first rule whose `search` matches (`valueMatches()`, with its `#empty`,
 * `#not_set` and `#not_empty` placeholders and `*` wildcards) and returns its
 * `replace` (`applyReplacement()`). The file also covers `convertToBoolean()`
 * (`as_boolean`) and `translateCalculationFieldReferences()`, which rewrites
 * Gravity Forms calculation formulas. No standalone test named any of these
 * methods before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsValueTranslationRulesTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsTranslationMethod(
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
 * A list of search-and-replace rules covering each kind of match.
 *
 * @return list<array<string, mixed>> The rules
 */
function searchReplaceRules(): array
{
    return [
        ['search' => 'a', 'replace' => 'A'],
        ['search' => 'img_*', 'replace' => 'image_*'],
        ['search' => 'drop', 'replace' => null],
        ['search' => 'arr', 'replace' => ['x' => 1]],
        ['search' => ['p', 'q'], 'replace' => 'PQ'],
        ['search' => '["j"]', 'replace' => 'J'],
        ['search' => 5, 'replace' => 'five'],
        ['search' => 'a', 'replace' => 'second'],
    ];
}

it('recognizes a list of search-and-replace pairs', function (
    array $rules,
    bool $expected
): void {
    expect(invokeFormsTranslationMethod('isIndexArrayWithSearchReplace', $rules))->toBe($expected);
})->with([
    'pairs, including null ones' => [[['search' => null, 'replace' => null], ['search' => 1, 'replace' => 2]], true],
    'no rules' => [[], false],
    'a rule without replace' => [[['search' => 1]], false],
    'a rule without search' => [[['replace' => 1]], false],
    'a rule that is not an array' => [['x'], false],
]);

it('applies the first rule whose search matches', function (
    mixed $value,
    mixed $expected
): void {
    expect(invokeFormsTranslationMethod('applyIndexArrayTranslations', $value, searchReplaceRules()))
        ->toBe($expected);
})->with([
    'an exact string, first rule wins' => ['a', 'A'],
    'a wildcard, carried into the replacement' => ['img_logo', 'image_logo'],
    'a null replacement' => ['drop', null],
    'an array replacement' => ['arr', ['x' => 1]],
    'an array matched exactly' => [['p', 'q'], 'PQ'],
    'an array matched by its JSON' => [['j'], 'J'],
    'an integer matched strictly' => [5, 'five'],
    'a numeric string is not the integer' => ['5', '5'],
    'no matching rule' => ['zzz', 'zzz'],
    'an array in another order' => [['q', 'p'], ['q', 'p']],
    'null' => [null, null],
    'a boolean' => [true, true],
]);

it('matches the attribute placeholders against the field data', function (
    string $placeholder,
    array $data,
    bool $expected
): void {
    expect(invokeFormsTranslationMethod('valueMatches', 'ignored', $placeholder, 'x', $data))
        ->toBe($expected);
})->with([
    '#not_empty on a value' => ['#not_empty', ['x' => 'v'], true],
    '#not_empty on an empty string' => ['#not_empty', ['x' => ''], false],
    '#not_set on a missing attribute' => ['#not_set', [], true],
    '#not_set on an empty string' => ['#not_set', ['x' => ''], false],
    '#empty on an empty string' => ['#empty', ['x' => ''], true],
    '#empty on a missing attribute' => ['#empty', [], false],
    '#empty on a value' => ['#empty', ['x' => 'v'], false],
]);

it('reads the placeholders from field_options when the original field data is given', function (): void {
    expect(invokeFormsTranslationMethod('valueMatches', 'v', '#not_empty', 'x', [], ['field_options' => \json_encode(['x' => 'v'])]))
        ->toBeTrue()
        ->and(invokeFormsTranslationMethod('valueMatches', 'v', '#not_empty', 'x', ['x' => 'v'], ['field_options' => \json_encode(['x' => ''])]))
        ->toBeFalse();
});

it('returns a replacement unchanged when its search had no wildcard', function (): void {
    expect(invokeFormsTranslationMethod('applyReplacement', 'R_*', 'a', 'a'))->toBe('R_*')
        ->and(invokeFormsTranslationMethod('applyReplacement', 7, 'a', 'a'))->toBe(7)
        ->and(invokeFormsTranslationMethod('applyReplacement', 'R_*', 'other', 'img_*'))->toBe('R_*');
});

it('converts a value to a boolean', function (
    mixed $value,
    bool $expected
): void {
    expect(invokeFormsTranslationMethod('convertToBoolean', $value))->toBe($expected);
})->with([
    'yes' => ['yes', true],
    'no' => ['no', false],
    'the string 1' => ['1', true],
    'the string 0' => ['0', false],
    'true' => ['true', true],
    'off' => ['off', false],
    'other text' => ['abc', false],
    'an empty string' => ['', false],
    'one' => [1, true],
    'zero' => [0, false],
    'a fraction' => [2.5, true],
    'zero as a float' => [0.0, false],
    'an empty array' => [[], false],
    'a non-empty array' => [[1], true],
    'an object' => [new stdClass, true],
    'null' => [null, false],
    'true itself' => [true, true],
    'false itself' => [false, false],
]);

it('refuses to convert a resource to a boolean', function (): void {
    $resource = \fopen('php://memory', 'r');

    expect(fn (): mixed => invokeFormsTranslationMethod('convertToBoolean', $resource))
        ->toThrow(Exception::class, 'Unsupported value type for boolean conversion: resource');
});

it('rewrites calculation references to the fields they name', function (
    string $formula,
    string $expected
): void {
    $fields = [
        ['id' => 1016, 'label' => 'Math Target 1', 'type' => 'number'],
        ['id' => '7', 'label' => 'Qty', 'type' => 'number'],
    ];

    expect(invokeFormsTranslationMethod('translateCalculationFieldReferences', $formula, $fields))
        ->toBe($expected);
})->with([
    'a label and an integer id' => ['{Math Target 1:1016} * 2', '[math_target_1_1016] * 2'],
    'a field that is not in the form' => ['{Qty:7}+{Missing:99}', '[qty_7]+{Missing:99}'],
    'no colon' => ['{plain}', '{plain}'],
    'no numeric part' => ['{a:b}', '{a:b}'],
    'an empty label' => ['x {:7}', 'x [qty_7]'],
    'a trailing modifier' => ['{Qty:7:extra}', '[qty_7]'],
]);
