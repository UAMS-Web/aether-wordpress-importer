<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms value translation (#98).
 *
 * `applyValueTranslation()` rewrites one attribute's value through its
 * `value_translation`: a table chosen by `based_on_other_attribute_value`
 * (with or without its own `default`/`specific` split), else by the fieldtype's
 * `specific` entry, else `default`, else the configuration itself; then the
 * table's `#not_set`, `#not_empty` and `#empty` entries, then a search/replace
 * list, an exact value or the first matching wildcard. A list value is
 * translated item by item unless a search/replace entry matches it whole.
 * Standalone tests reached part of it through the attribute mapping; this file
 * names it.
 *
 * Two behaviors are characterized rather than changed: a
 * `based_on_other_attribute_value` table that matches nothing leaves the value
 * untranslated rather than falling back to `default`, and the special entries
 * apply in order, so a value set by `#not_set` is then looked up as an exact
 * value.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsValueTranslationTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Translate a value through a value_translation configuration.
 *
 * @param  mixed  $value  The value to translate
 * @param  mixed  $translation  The value_translation configuration
 * @param  array<int|string, mixed>  $data  The field data
 * @param  ?string  $fieldtype  The source fieldtype
 * @param  array<int|string, mixed>|null  $originalFieldData  The original field data
 * @return mixed The translated value
 */
function translateFormsValue(
    mixed $value,
    mixed $translation,
    array $data = [],
    ?string $fieldtype = null,
    ?array $originalFieldData = null
): mixed {
    return new ReflectionMethod(
        FieldtypeMapping::class,
        'applyValueTranslation'
    )->invoke(
        null,
        $value,
        ['statamic_attribute' => 'S', 'value_translation' => $translation],
        'a',
        $data,
        $fieldtype,
        $originalFieldData,
        'S'
    );
}

it('leaves a value alone without a translation or a Statamic attribute', function (): void {
    $method = new ReflectionMethod(FieldtypeMapping::class, 'applyValueTranslation');

    expect($method->invoke(null, 'x', ['statamic_attribute' => 'S'], 'a', [], null, null, 'S'))->toBe('x')
        ->and($method->invoke(null, 'x', ['value_translation' => ['x' => 'X']], 'a', [], null, null, null))->toBe('x')
        ->and($method->invoke(null, 'x', 'value_translation', 'a', [], null, null, 'S'))->toBe('x')
        ->and($method->invoke(null, 'x', ['value_translation' => ['x' => 'X']], 'a', [], null, null, 'S'))->toBe('X');
});

it('translates a value through a table', function (
    mixed $value,
    mixed $translation,
    array $data,
    mixed $expected
): void {
    expect(translateFormsValue($value, $translation, $data))->toBe($expected);
})->with([
    'an exact string' => ['x', ['x' => 'X'], [], 'X'],
    'an exact integer' => [5, [5 => 'five'], [], 'five'],
    'true, as 1' => [true, ['1' => 'T'], [], 'T'],
    'no match' => ['q', ['x' => 'X'], [], 'q'],
    'an empty string, to null' => ['', ['x' => 'X'], [], null],
    'an empty string, through #empty' => ['', ['#empty' => 'E'], [], 'E'],
    'null, through #empty' => [null, ['#empty' => 'E'], [], 'E'],
    'zero, not empty' => [0, ['#empty' => 'E'], [], 0],
    'a float, unmatched' => [1.5, ['1.5' => 'F'], [], 1.5],
    'an unset attribute, through #not_set' => ['q', ['#not_set' => 'NS'], [], 'NS'],
    'a set attribute, past #not_set' => ['q', ['#not_set' => 'NS'], ['a' => 'q'], 'q'],
    'a filled attribute, through #not_empty' => ['q', ['#not_empty' => 'NE'], ['a' => 'q'], 'NE'],
    'an empty attribute, past #not_empty' => ['q', ['#not_empty' => 'NE'], ['a' => ''], 'q'],
    'a #not_set value, then looked up' => ['q', ['#not_set' => 'x', 'x' => 'X'], [], 'X'],
    'a wildcard' => ['pre_abc', ['pre_*' => 'new_*'], [], 'new_abc'],
    'the first wildcard' => ['pre_abc', ['pre_*' => 'one', 'p*' => 'two'], [], 'one'],
    'a wildcard with a null replacement, skipped' => ['pre_abc', ['pre_*' => null, 'p*' => 'two'], [], 'two'],
    'an unmatched wildcard' => ['other', ['pre_*' => 'new_*'], [], 'other'],
    'an exact value before a wildcard' => ['pre_abc', ['pre_*' => 'W', 'pre_abc' => 'E'], [], 'E'],
    'a wildcard, not for an integer' => [5, ['*' => 'W'], [], 5],
    'a search/replace list' => ['x', [['search' => 'x', 'replace' => 'X']], [], 'X'],
    'a list, item by item' => [['x', 'y', ''], ['x' => 'X'], [], ['X', 'y', null]],
    'a list replaced whole' => [['a', 'b'], [['search' => ['a', 'b'], 'replace' => 'AB']], [], 'AB'],
]);

it('reads #not_set and #not_empty from the original field options', function (): void {
    expect(translateFormsValue('q', ['#not_set' => 'NS'], ['a' => 'q'], null, ['field_options' => '{}']))->toBe('NS')
        ->and(translateFormsValue('q', ['#not_set' => 'NS'], [], null, ['field_options' => '{"a":"q"}']))->toBe('q')
        ->and(translateFormsValue('q', ['#not_empty' => 'NE'], [], null, ['field_options' => '{"a":"q"}']))->toBe('NE')
        ->and(translateFormsValue('q', ['#not_empty' => 'NE'], ['a' => 'q'], null, ['field_options' => '{"a":""}']))->toBe('q');
});

it('chooses the fieldtype table, then the default table', function (
    ?string $fieldtype,
    mixed $value,
    mixed $expected
): void {
    expect(translateFormsValue($value, ['default' => ['x' => 'D'], 'specific' => ['radio' => ['x' => 'R']]], [], $fieldtype))->toBe($expected);
})->with([
    'the fieldtype table' => ['radio', 'x', 'R'],
    'another fieldtype' => ['select', 'x', 'D'],
    'no fieldtype' => [null, 'x', 'D'],
    'an empty fieldtype' => ['', 'x', 'D'],
    'empty, to null by default' => [null, '', null],
    'empty, to null for the fieldtype' => ['radio', '', null],
]);

it('chooses a table by another attribute', function (
    array $byOther,
    array $data,
    ?string $fieldtype,
    mixed $value,
    mixed $expected
): void {
    expect(translateFormsValue($value, ['based_on_other_attribute_value' => $byOther, 'default' => ['x' => 'D']], $data, $fieldtype))->toBe($expected);
})->with([
    'a default value' => [['default' => ['t' => ['y' => ['x' => 'X']]]], ['t' => 'y'], null, 'x', 'X'],
    'a fieldtype value' => [['default' => ['t' => ['y' => ['x' => 'X']]], 'specific' => ['radio' => ['t' => ['y' => ['x' => 'R']]]]], ['t' => 'y'], 'radio', 'x', 'R'],
    'another fieldtype' => [['default' => ['t' => ['y' => ['x' => 'X']]], 'specific' => ['radio' => ['t' => ['y' => ['x' => 'R']]]]], ['t' => 'y'], 'select', 'x', 'X'],
    'only a fieldtype table, another fieldtype' => [['specific' => ['radio' => ['t' => ['y' => ['x' => 'R']]]]], ['t' => 'y'], 'select', 'x', 'x'],
    'a not-set value' => [['default' => ['t' => ['#not_set' => ['x' => 'NS']]]], [], null, 'x', 'NS'],
    'a not-set value, attribute set' => [['default' => ['t' => ['#not_set' => ['x' => 'NS'], 'y' => ['x' => 'Y']]]], ['t' => 'y'], null, 'x', 'x'],
    'a not-empty value' => [['default' => ['t' => ['#not_empty' => ['x' => 'NE']]]], ['t' => 'y'], null, 'x', 'NE'],
    'a not-empty value, attribute empty' => [['default' => ['t' => ['#not_empty' => ['x' => 'NE'], '' => ['x' => 'E']]]], ['t' => ''], null, 'x', 'x'],
    'an empty value' => [['default' => ['t' => ['#empty' => ['x' => 'E']]]], ['t' => ''], null, 'x', 'E'],
    'an empty value, attribute set' => [['default' => ['t' => ['#empty' => ['x' => 'E'], 'y' => ['x' => 'Y']]]], ['t' => 'y'], null, 'x', 'x'],
    'true, as 1' => [['default' => ['t' => ['1' => ['x' => 'ONE'], 'true' => ['x' => 'TRUE']]]], ['t' => true], null, 'x', 'ONE'],
    'true, as the word' => [['default' => ['t' => ['true' => ['x' => 'TRUE']]]], ['t' => true], null, 'x', 'TRUE'],
    'false, as the word' => [['default' => ['t' => ['false' => ['x' => 'FALSE']]]], ['t' => false], null, 'x', 'FALSE'],
    'true, with no table' => [['default' => ['t' => ['false' => ['x' => 'FALSE']]]], ['t' => true], null, 'x', 'x'],
    'the first matching attribute' => [['default' => ['t' => ['y' => ['x' => 'T']], 'u' => ['z' => ['x' => 'U']]]], ['t' => 'y', 'u' => 'z'], null, 'x', 'T'],
    'the second attribute' => [['default' => ['t' => ['y' => ['x' => 'T']], 'u' => ['z' => ['x' => 'U']]]], ['u' => 'z'], null, 'x', 'U'],
    'an integer attribute name' => [['default' => [0 => ['y' => ['x' => 'I']]]], [0 => 'y'], null, 'x', 'I'],
    'no match, and no default fallback' => [['default' => ['t' => ['y' => ['x' => 'X']]]], ['t' => 'q'], null, 'x', 'x'],
    'no match, empty kept' => [['default' => ['t' => ['y' => ['x' => 'X']]]], ['t' => 'q'], null, '', ''],
    'a legacy value' => [['t' => ['y' => ['x' => 'L']]], ['t' => 'y'], null, 'x', 'L'],
    'a legacy not-set value' => [['t' => ['#not_set' => ['x' => 'NS']]], [], null, 'x', 'NS'],
    'a legacy not-set value, attribute set' => [['t' => ['#not_set' => ['x' => 'NS'], 'y' => ['x' => 'Y']]], ['t' => 'y'], null, 'x', 'x'],
    'a legacy not-empty value' => [['t' => ['#not_empty' => ['x' => 'NE']]], ['t' => 'y'], null, 'x', 'NE'],
    'a legacy not-empty value, attribute empty' => [['t' => ['#not_empty' => ['x' => 'NE']]], ['t' => ''], null, 'x', 'x'],
    'a legacy empty value' => [['t' => ['#empty' => ['x' => 'E']]], ['t' => ''], null, 'x', 'E'],
    'a legacy empty value, attribute set' => [['t' => ['#empty' => ['x' => 'E']]], ['t' => 'y'], null, 'x', 'x'],
    'a legacy boolean, not as the word' => [['t' => ['true' => ['x' => 'TRUE']]], ['t' => true], null, 'x', 'x'],
    'a legacy first matching attribute' => [['t' => ['y' => ['x' => 'T']], 'u' => ['z' => ['x' => 'U']]], ['t' => 'y', 'u' => 'z'], null, 'x', 'T'],
    'a legacy second attribute' => [['t' => ['y' => ['x' => 'T']], 'u' => ['z' => ['x' => 'U']]], ['u' => 'z'], null, 'x', 'U'],
    'a legacy integer attribute name' => [[0 => ['y' => ['x' => 'I']]], [0 => 'y'], null, 'x', 'I'],
    'a legacy miss' => [['t' => ['y' => ['x' => 'L']]], ['t' => 'q'], null, 'x', 'x'],
]);
