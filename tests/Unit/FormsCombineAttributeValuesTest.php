<?php

declare(strict_types=1);

/**
 * Unit tests for combining a Forms attribute with other attributes (#98).
 *
 * `FieldtypeMappingTrait::processCombineValueWithOtherAttributeValue()` joins
 * an attribute's value with the values of named sibling attributes when its
 * mapping sets `combine_value_with_other_attribute_value`. The Gravity Forms
 * `rcwdupload` field uses it twice: to append the size unit to the size limit,
 * and to gather the four extension filters into one `mime_types` list. No
 * standalone test reached it before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsCombineAttributeValuesTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Combine a value with other attributes through the protected method.
 *
 * @param  mixed  $value  The current mapped value
 * @param  array<int|string, mixed>  $config  The attribute configuration
 * @param  array<int|string, mixed>  $data  The field data
 * @return mixed The combined value
 */
function combineFormsAttributeValue(
    mixed $value,
    array $config,
    array $data
): mixed {
    return new ReflectionMethod(
        FieldtypeMapping::class,
        'processCombineValueWithOtherAttributeValue'
    )->invoke(
        null,
        $value,
        $config,
        $data
    );
}

/**
 * A string combination over the attributes `b`, `c` and a non-key entry.
 *
 * @return array<string, mixed> The attribute configuration
 */
function combineWithBAndC(): array
{
    return [
        'combine_value_with_other_attribute_value' => [
            'combination_type' => 'string',
            'string_separator' => '|',
            'attribute_names' => ['b', 'c', 5.5],
        ],
    ];
}

/**
 * Map a Gravity Forms `rcwdupload` field's attributes.
 *
 * @param  array<string, mixed>  $attributes  The attributes beyond id, type and label
 * @return array<int|string, mixed> The mapped attributes
 */
function mapRcwduploadField(
    array $attributes
): array {
    $field = [
        'id' => 9,
        'type' => 'rcwdupload',
        'label' => 'Files',
        ...$attributes,
    ];

    return FieldtypeMapping::mapFieldAttributes($field, [$field], 'rcwdupload');
}

it('appends the size unit to an rcwdupload size limit before converting it', function (
    string $limit,
    string $unit,
    string $expected
): void {
    $mapped = mapRcwduploadField([
        'rcwdupload_sizelimit' => $limit,
        'rcwdupload_sizelimit_type' => $unit,
    ]);

    expect($mapped['max_file_size'] ?? 'absent')->toBe($expected);
})->with([
    'megabytes' => ['2', 'mb', '2'],
    'kilobytes' => ['500', 'kb', '0.5'],
]);

it('gathers the rcwdupload extension filters into one MIME type list', function (): void {
    $filters = [
        'rcwdupload_filter' => 'Y',
        'rcwdupload_filter_compr' => 'zip, .rar',
        'rcwdupload_filter_docs' => 'pdf',
        'rcwdupload_filter_images' => '',
        'rcwdupload_filter_others' => 'csv',
    ];

    expect(mapRcwduploadField($filters)['mime_types'] ?? 'absent')
        ->toBe(['application/zip', 'application/x-rar-compressed', 'application/pdf', 'text/csv'])
        ->and(mapRcwduploadField(['rcwdupload_filter_compr' => '', ...\array_diff_key($filters, ['rcwdupload_filter_compr' => true])])['mime_types'] ?? 'absent')
        ->toBe(['application/pdf', 'text/csv'])
        ->and(mapRcwduploadField(['rcwdupload_filter' => 'N', ...\array_diff_key($filters, ['rcwdupload_filter' => true])]))
        ->not->toHaveKey('mime_types');
});

it('joins the value and the named attributes with the separator', function (
    mixed $value,
    array $data,
    mixed $expected
): void {
    expect(combineFormsAttributeValue($value, combineWithBAndC(), $data))->toBe($expected);
})->with([
    'a value and two attributes' => ['a', ['b' => 'B', 'c' => 'C'], 'a|B|C'],
    'an array value, joined with commas first' => [['x', 'y'], ['b' => 'B'], 'x,y|B'],
    'no value of its own' => [null, ['b' => 'B'], 'B'],
    'an empty value and empty attributes' => ['', ['b' => '', 'c' => null], null],
    'nothing at all' => [null, [], null],
    'scalars cast to strings' => [7, ['c' => true], '7|1'],
]);

it('leaves the value alone when the combination is not fully configured', function (
    array $config
): void {
    expect(combineFormsAttributeValue('a', $config, ['b' => 'B']))->toBe('a');
})->with([
    'no combination key' => [[]],
    'a combination that is not an array' => [['combine_value_with_other_attribute_value' => 'string']],
    'no combination type' => [['combine_value_with_other_attribute_value' => ['string_separator' => '|', 'attribute_names' => ['b']]]],
    'no attribute names' => [['combine_value_with_other_attribute_value' => ['combination_type' => 'string', 'string_separator' => '|', 'attribute_names' => []]]],
    'a type other than string' => [['combine_value_with_other_attribute_value' => ['combination_type' => 'array', 'string_separator' => '|', 'attribute_names' => ['b']]]],
    'no separator' => [['combine_value_with_other_attribute_value' => ['combination_type' => 'string', 'attribute_names' => ['b']]]],
]);

it('returns null for a type other than string when there is nothing to combine', function (): void {
    $config = [
        'combine_value_with_other_attribute_value' => [
            'combination_type' => 'array',
            'attribute_names' => ['z'],
        ],
    ];

    expect(combineFormsAttributeValue('', $config, []))->toBeNull();
});
