<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms nested-attribute matchers (#98).
 *
 * A `statamic_other_field_value_based_on_nested_attribute_value` rule sets
 * other attributes when a field's nested attribute matches a pattern: a
 * key/value pattern against a key/value value (`matchesAssociativeArrays()`) or
 * against each item of a list (`matchesArrayOfArrays()`), and a list of
 * patterns against a list position by position
 * (`matchesArrayOfArraysPositional()`), which is how the Gravity Forms survey
 * scales are recognized. Values compare through `valuesMatch()`. No standalone
 * test named any of these methods before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsNestedAttributeMatchingTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsMatcherMethod(
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
 * Gravity Forms survey choices on the five-point agreement scale.
 *
 * @return list<array<string, string>> The choices, in scale order
 */
function agreementScaleChoices(): array
{
    $choices = [];
    foreach (['Strongly disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly agree'] as $index => $label) {
        $choices[] = [
            'text' => \strtoupper($label),
            'value' => 'v'.$index,
            'score' => (string) ($index + 1),
        ];
    }

    return $choices;
}

/**
 * Map a Gravity Forms survey's scale attributes from its choices.
 *
 * @param  list<mixed>  $choices  The survey choices
 * @return array<int|string, mixed> The scale attributes the mapping set
 */
function mapSurveyScale(
    array $choices
): array {
    $field = [
        'id' => 3,
        'type' => 'survey',
        'inputType' => 'likert',
        'label' => 'Rate',
        'choices' => $choices,
    ];

    return \array_intersect_key(
        FieldtypeMapping::mapFieldAttributes($field, [$field], 'survey'),
        \array_flip(['scale_type', 'number_of_points', 'enable_not_applicable', 'display_order', 'reverse_scale'])
    );
}

it('recognizes a survey scale from its choices, in order', function (): void {
    expect(mapSurveyScale(agreementScaleChoices()))->toBe([
        'scale_type' => 'agreement',
        'number_of_points' => 5,
        'enable_not_applicable' => false,
        'display_order' => 'asc',
        'reverse_scale' => false,
    ])->and(mapSurveyScale(\array_reverse(agreementScaleChoices())))->toBe([]);
});

it('does not recognize a scale when a choice is a plain list, rather than failing', function (): void {
    $choices = agreementScaleChoices();
    $choices[4] = ['Strongly agree', '5'];

    expect(mapSurveyScale($choices))->toBe([]);
});

it('matches a list of patterns against a list, position by position', function (
    array $value,
    array $pattern,
    bool $caseSensitiveKey,
    bool $expected
): void {
    expect(invokeFormsMatcherMethod('matchesArrayOfArraysPositional', $value, $pattern, true, $caseSensitiveKey, false))
        ->toBe($expected);
})->with([
    'every position matches' => [[['Text' => 'A', 'n' => '1'], ['text' => 'b']], [['text' => 'a', 'n' => 1], ['text' => 'B']], false, true],
    'a key in another case, case-sensitive' => [[['Text' => 'A']], [['text' => 'a']], true, false],
    'a different length' => [[['text' => 'a']], [['text' => 'a'], ['text' => 'b']], false, false],
    'a value that differs' => [[['text' => 'a']], [['text' => 'b']], false, false],
    'a missing key' => [[['other' => 'a']], [['text' => 'a']], false, false],
    'a pattern that is not an array' => [[['text' => 'a']], ['text'], false, false],
    'an item that is not an array' => [['a'], [['text' => 'a']], false, false],
    'an item that is a plain list' => [[['a', 'b']], [['text' => 'a']], false, false],
    'a plain-list pattern against a plain list' => [[['a', 'b']], [['a']], false, true],
    'a plain-list pattern, case-sensitive' => [[['a', 'b']], [['a']], true, true],
]);

it('matches a key/value pattern against a key/value value', function (
    array $value,
    array $pattern,
    bool $caseSensitiveKey,
    bool $expected
): void {
    expect(invokeFormsMatcherMethod('matchesAssociativeArrays', $value, $pattern, true, $caseSensitiveKey, false))
        ->toBe($expected);
})->with([
    'every pair present' => [['Enabled' => 'TRUE', 'n' => 2], ['enabled' => 'true', 'n' => '2'], false, true],
    'a key in another case, case-sensitive' => [['Enabled' => 'true'], ['enabled' => 'true'], true, false],
    'a value that differs' => [['enabled' => 'false'], ['enabled' => 'true'], false, false],
    'a missing key' => [['other' => 'true'], ['enabled' => 'true'], false, false],
]);

it('matches a key/value pattern against any item of a list', function (
    array $value,
    array $pattern,
    bool $caseSensitiveKey,
    bool $expected
): void {
    expect(invokeFormsMatcherMethod('matchesArrayOfArrays', $value, $pattern, true, $caseSensitiveKey, false))
        ->toBe($expected);
})->with([
    'pairs spread over items' => [[['Text' => 'A'], ['score' => '3']], ['text' => 'a', 'score' => 3], false, true],
    'a key in another case, case-sensitive' => [[['Text' => 'a']], ['text' => 'a'], true, false],
    'a value no item has' => [[['text' => 'a'], ['text' => 'b']], ['text' => 'c'], false, false],
    'items that are not arrays' => [['text', 'a'], ['text' => 'a'], false, false],
]);

it('compares two values, by number and case when asked', function (
    mixed $value,
    mixed $pattern,
    bool $numeric,
    bool $caseSensitive,
    bool $expected
): void {
    expect(invokeFormsMatcherMethod('valuesMatch', $value, $pattern, $numeric, $caseSensitive))->toBe($expected);
})->with([
    'a numeric string and a number' => ['1', 1, true, false, true],
    'two spellings of one number' => ['1.0', '1', true, true, true],
    'a numeric string and a number, numbers off' => ['1', 1, false, false, false],
    'two numbers that differ' => ['2', 1, true, false, false],
    'strings in another case' => ['Ab', 'ab', true, false, true],
    'strings in another case, case-sensitive' => ['Ab', 'ab', true, true, false],
    'two booleans' => [true, true, true, false, true],
    'null and an empty string' => [null, '', true, false, false],
]);
