<?php

declare(strict_types=1);

/**
 * Unit tests for the edges of the passes over missing attributes and the helpers after them (#537).
 *
 * A Linux mutation run of `FieldtypeMappingTrait` found mutants that the
 * existing tests let through in the middle of the file: the passes of
 * `mapAttributesFromMapping()` that write `#not_set`, `#not_empty` and
 * `#empty` translations for attributes missing from the data, the
 * per-fieldtype configuration resolution, the value combination, the file
 * size conversion, the key translation of choice lists and the resolution of
 * value transformations. Each case here names the input that tells the
 * original from the mutant. The methods are called on the Gravity Forms
 * mapping class, which uses the trait.
 *
 * Mutants no input can kill are annotated with `@pest-mutate-ignore` in the
 * source, each with its reason beside it.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldtypeMappingEdgeCasesMiddleTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Call a protected static method of the trait through the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function fmmCall(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(FieldtypeMapping::class, $method)->invoke(null, ...$arguments);
}

/**
 * Map field data through `mapAttributesFromMapping()`.
 *
 * @param  array<int|string, mixed>  $data  The field or subfield data
 * @param  array<int|string, mixed>  $mapping  The attribute mapping
 * @param  ?string  $fieldtype  The source fieldtype, for fieldtype-specific configuration
 * @param  array<int|string, mixed>|null  $original  The original field data, for field option checks
 * @return mixed The mapped attributes
 */
function fmmMap(array $data, array $mapping, ?string $fieldtype = null, ?array $original = null): mixed
{
    return fmmCall('mapAttributesFromMapping', $data, $mapping, [], null, $fieldtype, $original, null);
}

/**
 * Build a value transformation that generates a value for one key whenever an attribute in the data is not empty.
 *
 * @param  string  $key  The key the value is generated for
 * @param  string  $value  The generated value, or `#count` for the item's count
 * @return array<string, mixed> The `value_transformations` configuration
 */
function fmmGenerateWhenNotEmpty(string $key, string $value): array
{
    return ['based_on_attribute_value' => ['#not_empty' => [$key => ['generate_value' => [['value' => $value]]]]]];
}

it('keeps the alert, other field value and form config properties for a fieldtype', function (string $property, mixed $value, mixed $expected): void {
    expect(fmmCall('getFieldtypeSpecificConfig', [$property => $value], 'text'))->toBe([$property => $expected]);
})->with([
    'alert_unsupported' => ['alert_unsupported', true, true],
    'statamic_other_field_value' => ['statamic_other_field_value', ['default' => ['shown' => true]], ['shown' => true]],
    'statamic_form_config' => ['statamic_form_config', 'reply_to', 'reply_to'],
]);

it('reads the fieldtype entry of a value translation kept whole by a value table, for a missing attribute', function (): void {
    // The value table keeps the translation whole, so the fieldtype resolution finds no
    // property in it and the fieldtype entry is read directly.
    $mapped = fmmMap([], ['gone' => ['statamic_attribute' => 'out', 'value_translation' => [
        'based_on_attribute_value' => [],
        'specific' => ['radio' => [['search' => '#not_set', 'replace' => 'Z']]],
    ]]], 'radio');

    expect($mapped)->toBe(['out' => 'Z']);
});

it('keeps a resolved value translation over the fieldtype entry and the list, for a missing attribute', function (array $translation): void {
    // `trim` is a property name, so the fieldtype resolution returns it and the other
    // translations in the table are not read.
    expect(fmmMap([], ['gone' => ['statamic_attribute' => 'out', 'value_translation' => $translation]], 'radio'))
        ->toBe(['out' => 'Y']);
})->with([
    'a fieldtype entry' => [[
        'based_on_attribute_value' => [],
        'trim' => ['search' => '#not_set', 'replace' => 'Y'],
        'specific' => ['radio' => [['search' => '#not_set', 'replace' => 'Z']]],
    ]],
    'a list of translations' => [[
        ['search' => '#not_set', 'replace' => 'Z'],
        'trim' => ['search' => '#not_set', 'replace' => 'Y'],
    ]],
]);

it('maps a not-set field option after an attribute it skips', function (array $data, array $first, ?string $fieldtype, array $expected): void {
    $mapping = ['a' => $first, 'b' => ['statamic_attribute' => 'bee', 'value_translation' => ['#not_set' => 'N']]];

    expect(fmmMap($data, $mapping, $fieldtype, ['field_options' => '{}']))->toBe($expected);
})->with([
    'an attribute in the data' => [['a' => 'x'], ['statamic_attribute' => 'display'], null, ['display' => 'x', 'bee' => 'N']],
    'an excluded attribute' => [[], ['statamic_attribute' => 'aa', 'value_translation' => ['#not_set' => 'A'], 'exclude_fieldtypes' => ['text']], 'text', ['bee' => 'N']],
    'an attribute with no target' => [[], ['value_translation' => ['#not_set' => 'A']], null, ['bee' => 'N']],
    'an attribute with an empty target' => [[], ['statamic_attribute' => '', 'value_translation' => ['#not_set' => 'A']], null, ['bee' => 'N']],
]);

it('counts a not-set field option as the first item when generating its value', function (): void {
    $mapped = fmmMap(['label' => 'x'], ['gone' => [
        'statamic_attribute' => 'handle',
        'value_translation' => ['#not_set' => 'seed'],
        'value_transformations' => ['based_on_attribute_value' => ['#not_empty' => ['generate_value' => [['value' => '#count']]]]],
    ]], null, ['field_options' => '{}']);

    expect($mapped)->toBe(['handle' => '1']);
});

it('maps a missing attribute after an attribute the last pass skips', function (array $data, array $first, ?string $fieldtype, array $expected): void {
    $mapping = ['a' => $first, 'b' => ['statamic_attribute' => 'bb', 'value_translation' => ['#not_set' => 'N']]];

    expect(fmmMap($data, $mapping, $fieldtype))->toBe($expected);
})->with([
    'an attribute in the data' => [['a' => 'x'], ['statamic_attribute' => 'display'], null, ['display' => 'x', 'bb' => 'N']],
    'an excluded attribute' => [[], ['statamic_attribute' => 'aa', 'value_translation' => ['#not_set' => 'A'], 'exclude_fieldtypes' => ['text']], 'text', ['bb' => 'N']],
    'an attribute with no target' => [[], ['value_translation' => ['#not_set' => 'A']], null, ['bb' => 'N']],
    'an attribute with no not-set translation' => [[], ['statamic_attribute' => 'aa'], null, ['bb' => 'N']],
]);

it('maps a missing attribute from the default of a value translation kept whole', function (?array $original): void {
    // The value table keeps the translation whole, so its `default` is read as the
    // default translations of the missing attribute.
    $mapped = fmmMap([], ['gone' => ['statamic_attribute' => 'out', 'value_translation' => [
        'default' => ['#not_set' => 'N'],
        'based_on_attribute_value' => [],
    ]]], null, $original);

    expect($mapped)->toBe(['out' => 'N']);
})->with([
    'with no original field' => [null],
    'not set in the original field' => [['field_options' => '{}']],
]);

it('maps a missing attribute when the fieldtype entry of its translation has a not-set case', function (): void {
    // The fieldtype entry marks the attribute as one with a not-set case; the value
    // then comes from the other attribute's table, which is read first.
    $mapped = fmmMap(['kind' => 'x'], ['gone' => ['statamic_attribute' => 'out', 'value_translation' => [
        'specific' => ['radio' => ['#not_set' => 'N']],
        'based_on_other_attribute_value' => ['kind' => ['x' => ['#not_set' => 'M']]],
    ]]], 'radio');

    expect($mapped)->toBe(['out' => 'M']);
});

it('reads the default translations of a missing attribute for an empty fieldtype', function (): void {
    // An entry keyed by the empty string is not the fieldtype's entry; it has no
    // not-set case, so reading it would map nothing.
    $mapped = fmmMap([], ['gone' => ['statamic_attribute' => 'out', 'value_translation' => [
        'default' => ['#not_set' => 'N'],
        'specific' => ['' => ['other' => 'x']],
        'based_on_attribute_value' => [],
    ]]], '');

    expect($mapped)->toBe(['out' => 'N']);
});

it('takes the first other attribute that gives a missing attribute its translation', function (string $case, ?array $original): void {
    // Neither `kind` nor `mode` is in the data, so both match `#not_set`; the first
    // one's translation is the one written.
    $translation = ['based_on_other_attribute_value' => [
        'kind' => ['#not_set' => [$case => 'first']],
        'mode' => ['#not_set' => [$case => 'second']],
    ]];

    expect(fmmMap([], ['gone' => ['statamic_attribute' => 'out', 'value_translation' => $translation]], null, $original))
        ->toBe(['out' => 'first']);
})->with([
    'not set in the original field' => ['#not_set', ['field_options' => '{}']],
    'not empty in the original field' => ['#not_empty', ['field_options' => '{"gone":"v"}']],
    'empty in the original field' => ['#empty', ['field_options' => '{"gone":""}']],
    'the empty value' => ['', null],
]);

it('maps nothing for a missing attribute whose target resolves to none', function (array $translation, ?array $original): void {
    // The value table sends a missing attribute to no target, so the attribute is skipped.
    $target = ['based_on_attribute_value' => ['default' => ['#not_set' => null]]];

    expect(fmmMap([], ['gone' => ['statamic_attribute' => $target, 'value_translation' => $translation]], null, $original))
        ->toBe([]);
})->with([
    'not set' => [['#not_set' => 'N'], null],
    'not empty in the original field' => [['#not_empty' => 'N'], ['field_options' => '{"gone":"v"}']],
    'empty in the original field' => [['#not_empty' => 'X', '#empty' => 'N'], ['field_options' => '{"gone":""}']],
    'a null translation' => [['#not_empty' => 'X', 'null' => 'N'], null],
]);

it('leaves a value as it was when the combination names no type or no attributes', function (mixed $value, array $combination): void {
    expect(fmmCall('processCombineValueWithOtherAttributeValue', $value, ['combine_value_with_other_attribute_value' => $combination], []))
        ->toBe($value);
})->with([
    // With nothing to combine, a combination would return null
    'no type, and an empty value' => ['', ['attribute_names' => ['other']]],
    // A string combination would join the list
    'no attributes, and a list' => [['a', 'b'], ['combination_type' => 'string', 'attribute_names' => [], 'string_separator' => ',']],
]);

it('returns a whole number of a large size as an integer', function (): void {
    // The division is not exact, but the float result is too large to hold a fraction.
    expect(fmmCall('convertFromBytes', 9100000000000000001, 'KB'))->toBe(9100000000000000);
});

it('matches a value against a validation list exactly', function (): void {
    $lists = new ReflectionProperty(FieldtypeMapping::class, 'validationLists');
    $saved = $lists->getValue();

    try {
        $lists->setValue(null, ['numbers' => ['10']]);

        // `1e1` equals `10` only when compared loosely
        expect(FieldtypeMapping::validateValueAgainstList('1e1', 'numbers'))->toBeFalse()
            ->and(FieldtypeMapping::validateValueAgainstList('10', 'numbers'))->toBeTrue();
    } finally {
        $lists->setValue(null, $saved);
    }
});

it('transforms an untranslated choice key with the field data', function (): void {
    expect(fmmCall('mapArrayWithKeyTranslation', [['text' => 'A']], [], fmmGenerateWhenNotEmpty('text', 'T'), null, ['kind' => 'x']))
        ->toBe([['text' => 'T']]);
});

it('leaves an untranslated choice key as it was when no transformation resolves', function (): void {
    expect(fmmCall('mapArrayWithKeyTranslation', [['text' => 'A']], [], ['based_on_attribute_value' => []], null, null))
        ->toBe([['text' => 'A']]);
});

it('generates the missing keys the field data expects', function (): void {
    // Columns make `handle` an expected key, which the default keys do not include.
    expect(fmmCall('mapArrayWithKeyTranslation', [['text' => 'A']], [], fmmGenerateWhenNotEmpty('handle', 'H'), null, ['enableColumns' => true]))
        ->toBe([['text' => 'A', 'handle' => 'H']]);
});

it('counts a choice with a name for an index as the first when generating a key', function (): void {
    expect(fmmCall('mapArrayWithKeyTranslation', ['first' => ['text' => 'A']], [], fmmGenerateWhenNotEmpty('key', '#count'), null, ['kind' => 'x']))
        ->toBe([['text' => 'A', 'key' => '1']]);
});

it('resolves no value transformation for a fieldtype with no fieldtype table', function (): void {
    expect(fmmCall('resolveValueTransformations', [], [], 'value', 'text'))->toBeNull();
});
