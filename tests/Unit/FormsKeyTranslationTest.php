<?php

declare(strict_types=1);

/**
 * Unit tests for how the Forms attribute mapping translates the keys of a list (#98).
 *
 * An attribute whose value is a list of arrays (Gravity Forms choices) can
 * carry a `key_translation` that renames each item's keys. The translation
 * table is either the whole `key_translation` value, or is picked from it in a
 * fixed order: `specific_based_on_attribute_value` for the source fieldtype,
 * `based_on_other_attribute_value` (split into `specific`/`default`, or not),
 * a `specific` table for the source fieldtype, and `default`. The two
 * attribute-value forms also accept the special keys `#not_set`, `#not_empty`
 * and `#empty`. A line-coverage run left most of this section unexecuted.
 *
 * The mappings here are written for each case rather than taken from the
 * shipped tables, so each case shows one rule.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsKeyTranslationTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * The translation table the cases treat as the fallback.
 */
const FORMS_KT_FALLBACK = ['text' => 'label', 'value' => 'key'];

/**
 * The translation table a case expects to be picked.
 */
const FORMS_KT_PICKED = ['text' => 'name'];

/**
 * The options produced by the fallback table.
 */
const FORMS_KT_FALLBACK_OPTIONS = ['options' => [['label' => 'A', 'key' => 'a']]];

/**
 * The options produced by the picked table.
 */
const FORMS_KT_PICKED_OPTIONS = ['options' => [['name' => 'A', 'value' => 'a']]];

/**
 * The options produced when no table is picked.
 */
const FORMS_KT_UNTRANSLATED_OPTIONS = ['options' => [['text' => 'A', 'value' => 'a']]];

/**
 * Map one choice through a `key_translation` with `mapAttributesFromMapping()`.
 *
 * @param  array<int|string, mixed>  $keyTranslation  The `key_translation` configuration
 * @param  array<string, mixed>  $data  The other source attributes
 * @param  ?string  $fieldtype  The source fieldtype
 * @param  ?array<string, mixed>  $original  The original field data
 * @return mixed The mapped attributes
 */
function translateFormsChoiceKeys(
    array $keyTranslation,
    array $data = [],
    ?string $fieldtype = null,
    ?array $original = null
): mixed {
    return new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')
        ->invoke(
            null,
            ['choices' => [['text' => 'A', 'value' => 'a']]] + $data,
            ['choices' => ['statamic_attribute' => 'options', 'key_translation' => $keyTranslation]],
            [],
            null,
            $fieldtype,
            $original
        );
}

it('picks the key translation table', function (
    array $keyTranslation,
    array $data,
    ?string $fieldtype,
    ?array $original,
    array $expected
): void {
    expect(translateFormsChoiceKeys($keyTranslation, $data, $fieldtype, $original))->toBe($expected);
})->with([
    // A plain table, and the default and specific tables
    'a plain table' => [FORMS_KT_FALLBACK, [], null, null, FORMS_KT_FALLBACK_OPTIONS],
    'default' => [['default' => FORMS_KT_FALLBACK], [], null, null, FORMS_KT_FALLBACK_OPTIONS],
    'specific for the fieldtype' => [['default' => FORMS_KT_FALLBACK, 'specific' => ['radio' => FORMS_KT_PICKED]], [], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'specific, then default for another fieldtype' => [['default' => FORMS_KT_FALLBACK, 'specific' => ['radio' => FORMS_KT_PICKED]], [], 'select', null, FORMS_KT_FALLBACK_OPTIONS],
    'specific, then default with no fieldtype' => [['default' => FORMS_KT_FALLBACK, 'specific' => ['radio' => FORMS_KT_PICKED]], [], null, null, FORMS_KT_FALLBACK_OPTIONS],
    'specific, then default with an empty fieldtype' => [['default' => FORMS_KT_FALLBACK, 'specific' => ['' => FORMS_KT_PICKED]], [], '', null, FORMS_KT_FALLBACK_OPTIONS],

    // specific_based_on_attribute_value
    'by attribute value' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['m1' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by attribute value, no match' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['m1' => FORMS_KT_PICKED]]]], ['mode' => 'm2'], 'radio', null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by attribute value, another fieldtype' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['m1' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], 'select', null, FORMS_KT_FALLBACK_OPTIONS],
    'by attribute value, an empty fieldtype' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['' => ['mode' => ['m1' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], '', null, FORMS_KT_FALLBACK_OPTIONS],
    'by attribute value, the second attribute matches' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['m9' => FORMS_KT_FALLBACK], 'kind' => ['k1' => FORMS_KT_PICKED]]]], ['mode' => 'm1', 'kind' => 'k1'], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by attribute value, a numeric attribute name' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => [0 => ['x' => FORMS_KT_FALLBACK], 'mode' => ['m1' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by attribute value, before based_on_other_attribute_value' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['m1' => FORMS_KT_PICKED]]], 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_FALLBACK]]]], ['mode' => 'm1'], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by attribute value, not set' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['#not_set' => FORMS_KT_PICKED]]]], [], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by attribute value, not set but set' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['#not_set' => FORMS_KT_PICKED]]]], ['mode' => 'x'], 'radio', null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by attribute value, not set in the original field' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['#not_set' => FORMS_KT_PICKED]]]], ['mode' => 'x'], 'radio', ['field_options' => '{}'], FORMS_KT_PICKED_OPTIONS],
    'by attribute value, set in the original field' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['#not_set' => FORMS_KT_PICKED]]]], [], 'radio', ['field_options' => '{"mode":"x"}'], FORMS_KT_UNTRANSLATED_OPTIONS],
    'by attribute value, not empty' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'x'], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by attribute value, not empty but empty' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => ''], 'radio', null, FORMS_KT_UNTRANSLATED_OPTIONS],

    // based_on_other_attribute_value, without the specific/default split
    'by other attribute' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, before specific' => [['default' => FORMS_KT_FALLBACK, 'specific' => ['radio' => FORMS_KT_FALLBACK], 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, no match shadows default' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'm2'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute, the second attribute matches' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m9' => ['#not_empty' => FORMS_KT_FALLBACK]], 'kind' => ['k1' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'm1', 'kind' => 'k1'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, a numeric attribute name' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => [0 => ['x' => ['#not_empty' => FORMS_KT_FALLBACK]], 'mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, not set' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['#not_set' => ['#not_empty' => FORMS_KT_PICKED]]]], [], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, not set but set' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['#not_set' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'x'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute, not empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['#not_empty' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'x'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, not empty but empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['#not_empty' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => ''], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute, empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['#empty' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => ''], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, empty but not empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['#empty' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'x'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute, choices not set in the original field' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#not_set' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, ['field_options' => '{}'], FORMS_KT_PICKED_OPTIONS],
    'by other attribute, choices set in the original field' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#not_set' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, ['field_options' => '{"choices":[1]}'], FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute, choices set' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#not_set' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute, choices empty in the original field' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#empty' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, ['field_options' => '{"choices":""}'], FORMS_KT_PICKED_OPTIONS],
    'by other attribute, choices not empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#empty' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute, the choices themselves' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => [(string) json_encode([['text' => 'A', 'value' => 'a']]) => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, other choices' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['other' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],

    // based_on_other_attribute_value, split into specific and default
    'by other attribute, specific for the fieldtype' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_FALLBACK]]], 'specific' => ['radio' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]]]], ['mode' => 'm1'], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, default for another fieldtype' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]], 'specific' => ['radio' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_FALLBACK]]]]]], ['mode' => 'm1'], 'select', null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, default with an empty fieldtype' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]], 'specific' => ['' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_FALLBACK]]]]]], ['mode' => 'm1'], '', null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute, specific only' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['specific' => ['radio' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]]]], ['mode' => 'm1'], 'radio', null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, no match' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => 'm2'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute split, the second attribute matches' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m9' => ['#not_empty' => FORMS_KT_FALLBACK]], 'kind' => ['k1' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => 'm1', 'kind' => 'k1'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, a numeric attribute name' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => [0 => ['x' => ['#not_empty' => FORMS_KT_FALLBACK]], 'mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, not set' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['#not_set' => ['#not_empty' => FORMS_KT_PICKED]]]]], [], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, not set but set' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['#not_set' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => 'x'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute split, not empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['#not_empty' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => 'x'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, not empty but empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['#not_empty' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => ''], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute split, empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['#empty' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => ''], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, empty but not empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['#empty' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => 'x'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute split, choices not set in the original field' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#not_set' => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, ['field_options' => '{}'], FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, choices set in the original field' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#not_set' => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, ['field_options' => '{"choices":[1]}'], FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute split, choices set' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#not_set' => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute split, choices empty in the original field' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#empty' => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, ['field_options' => '{"choices":""}'], FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, choices not empty' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#empty' => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute split, not empty but empty in the original field' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, ['field_options' => '{"choices":""}'], FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute, not empty but empty in the original field' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['mode' => ['m1' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'm1'], null, ['field_options' => '{"choices":""}'], FORMS_KT_UNTRANSLATED_OPTIONS],
    'by attribute value, not empty but empty in the original field' => [['default' => FORMS_KT_FALLBACK, 'specific_based_on_attribute_value' => ['radio' => ['mode' => ['#not_empty' => FORMS_KT_PICKED]]]], ['mode' => 'x'], 'radio', ['field_options' => '{"mode":""}'], FORMS_KT_UNTRANSLATED_OPTIONS],
    'by other attribute split, the choices themselves' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => [(string) json_encode([['text' => 'A', 'value' => 'a']]) => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, null, FORMS_KT_PICKED_OPTIONS],
    'by other attribute split, other choices' => [['default' => FORMS_KT_FALLBACK, 'based_on_other_attribute_value' => ['default' => ['mode' => ['m1' => ['other' => FORMS_KT_PICKED]]]]], ['mode' => 'm1'], null, null, FORMS_KT_UNTRANSLATED_OPTIONS],
]);

it('translates nothing that is not a list with a table', function (
    array $mapping,
    array $expected
): void {
    expect(new ReflectionMethod(FieldtypeMapping::class, 'mapAttributesFromMapping')->invoke(null, ['choices' => $mapping['value']], ['choices' => ['statamic_attribute' => 'options', 'key_translation' => $mapping['table']]]))
        ->toBe($expected);
})->with([
    'a table that is not an array' => [['value' => [['text' => 'A']], 'table' => 'label'], ['options' => [['text' => 'A']]]],
    'a value that is not a list' => [['value' => 'A', 'table' => FORMS_KT_FALLBACK], ['options' => 'A']],
]);
