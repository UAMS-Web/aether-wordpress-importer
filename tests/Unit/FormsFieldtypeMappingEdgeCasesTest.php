<?php

declare(strict_types=1);

/**
 * Unit tests for the edges of the shared Forms attribute mapping (#537).
 *
 * A Linux mutation run of `FieldtypeMappingTrait` found mutants that the
 * existing tests let through: a skipped attribute that ends the loop instead of
 * moving to the next one, a guard whose removal changes the result only for an
 * input no test gave, and the like. Each case here names the input that tells
 * the original from the mutant. The methods are called on the Gravity Forms
 * mapping class, which uses the trait.
 *
 * Mutants no input can kill are annotated with `@pest-mutate-ignore` in the
 * source, each with its reason beside it.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldtypeMappingEdgeCasesTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Call a protected static method of the trait through the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function fmeCall(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(FieldtypeMapping::class, $method)->invoke(null, ...$arguments);
}

/**
 * Map field data through `mapAttributesFromMapping()`.
 *
 * @param  array<int|string, mixed>  $data  The field or subfield data
 * @param  array<int|string, mixed>  $mapping  The attribute mapping
 * @param  ?string  $fieldtype  The source fieldtype, for fieldtype-specific configuration
 * @param  ?string  $handle  The subfield handle a `*` in a target is replaced with
 * @param  array<int|string, mixed>|null  $original  The original field data, for `#not_set` checks
 * @param  array<int|string, mixed>  $allFields  The form's fields
 * @return mixed The mapped attributes
 */
function fmeMap(
    array $data,
    array $mapping,
    ?string $fieldtype = null,
    ?string $handle = null,
    ?array $original = null,
    array $allFields = []
): mixed {
    return fmeCall('mapAttributesFromMapping', $data, $mapping, $allFields, $handle, $fieldtype, $original, null);
}

it('maps the attributes after a skipped subfield placeholder', function (): void {
    // An all-empty placeholder object on a name field configures subfields and is
    // skipped; the attribute after it is still mapped.
    $mapped = fmeMap(
        ['placeholder' => ['first' => '', 'last' => ''], 'label' => 'Your name'],
        ['placeholder' => ['statamic_attribute' => 'placeholder'], 'label' => ['statamic_attribute' => 'display']],
        'name'
    );

    expect($mapped)->toBe(['display' => 'Your name']);
});

it('maps nothing for an empty target, not even its other field values', function (): void {
    // An empty target names no attribute, so the value is never read and the other
    // field values it would set when non-empty are not set either.
    $mapped = fmeMap(
        ['label' => 'Your name'],
        ['label' => ['statamic_attribute' => '', 'statamic_other_field_value' => ['default' => ['shown' => true]]]]
    );

    expect($mapped)->toBe([]);
});

it('reads no fieldtype-specific target for an empty fieldtype', function (): void {
    // An empty fieldtype names no fieldtype, so even an entry keyed by the empty
    // string is not its specific target and the default applies. The value table
    // that matches nothing keeps the target table whole, so it reaches the mapping.
    $mapped = fmeMap(
        ['label' => 'Your name'],
        ['label' => ['statamic_attribute' => [
            'based_on_attribute_value' => ['default' => ['no match' => 'never']],
            'specific' => ['' => 'wrong'],
            'default' => 'display',
        ]]],
        ''
    );

    expect($mapped)->toBe(['display' => 'Your name']);
});

it('reads a legacy value table only when no other target matched', function (): void {
    // A legacy `based_on_attribute_value` table, one with no `default` or `specific`
    // key, is the last resort: the default target wins over it.
    $mapped = fmeMap(
        ['label' => 'Your name'],
        ['label' => ['statamic_attribute' => [
            'based_on_attribute_value' => ['Your name' => 'legacy'],
            'default' => 'display',
        ]]]
    );

    expect($mapped)->toBe(['display' => 'Your name']);
});

it('reads a legacy table of other attribute values only when no other target matched', function (): void {
    // The legacy `based_on_other_attribute_value` table is the last resort too.
    $mapped = fmeMap(
        ['label' => 'Your name', 'kind' => 'big'],
        ['label' => ['statamic_attribute' => [
            'based_on_other_attribute_value' => ['kind' => ['big' => 'legacy']],
            'default' => 'display',
        ]]]
    );

    expect($mapped)->toBe(['display' => 'Your name']);
});

it('collects an object value list once per value, as a list, then maps the next attribute', function (): void {
    // The duplicate is dropped and the list is reindexed; the attribute after the
    // object one is still mapped.
    $mapped = fmeMap(
        ['ftypes' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'], 'label' => 'Upload'],
        ['ftypes' => ['statamic_attribute' => 'mime_types', 'extract_object_values' => true], 'label' => ['statamic_attribute' => 'display']]
    );

    expect($mapped)->toBe(['mime_types' => ['image/jpeg', 'image/png'], 'display' => 'Upload']);
});

it('collects no object values for a target that resolves to empty', function (): void {
    $mapped = fmeMap(
        ['ftypes' => ['jpg' => 'image/jpeg']],
        ['ftypes' => [
            'statamic_attribute' => ['based_on_attribute_value' => ['default' => ['no match' => 'never']], 'default' => ''],
            'extract_object_values' => true,
        ]]
    );

    expect($mapped)->toBe([]);
});

it('matches a whole-list translation against the object values as a list', function (): void {
    // The values are read as a list before translation, so a translation whose search
    // is that list matches although the object's own keys are extensions.
    $mapped = fmeMap(
        ['ftypes' => ['jpg' => 'image/jpeg', 'png' => 'image/png']],
        ['ftypes' => [
            'statamic_attribute' => 'mime_types',
            'extract_object_values' => true,
            'value_translation' => [['search' => ['image/jpeg', 'image/png'], 'replace' => ['image/*']]],
        ]]
    );

    expect($mapped)->toBe(['mime_types' => ['image/*']]);
});

/**
 * Map one choice list through a key translation, as a radio field.
 *
 * @param  array<int|string, mixed>  $keyTranslation  The `key_translation` configuration
 * @param  array<int|string, mixed>|null  $original  The original field data, for `#not_set` checks
 * @return mixed The mapped attributes
 */
function fmeTranslateChoiceKeys(array $keyTranslation, ?array $original = null): mixed
{
    return fmeMap(
        ['choices' => [['text' => 'A', 'value' => 'a']], 'kind' => 'x', 'mode' => 'y'],
        ['choices' => ['statamic_attribute' => 'options', 'key_translation' => $keyTranslation]],
        'radio',
        null,
        $original
    );
}

it('takes the first matching key translation for the fieldtype', function (array $byAttribute, ?array $original): void {
    // Both entries match; the first one is the translation, so `text` becomes `label`
    // rather than `other`.
    expect(fmeTranslateChoiceKeys(['specific_based_on_attribute_value' => ['radio' => $byAttribute]], $original))
        ->toBe(['options' => [['label' => 'A', 'value' => 'a']]]);
})->with([
    'not set, against the original field' => [['gone' => ['#not_set' => ['text' => 'label']], 'gone2' => ['#not_set' => ['text' => 'other']]], ['field_options' => '{}']],
    'not set, against the field data' => [['gone' => ['#not_set' => ['text' => 'label']], 'gone2' => ['#not_set' => ['text' => 'other']]], null],
    'not empty' => [['kind' => ['#not_empty' => ['text' => 'label']], 'mode' => ['#not_empty' => ['text' => 'other']]], null],
    'a value' => [['kind' => ['x' => ['text' => 'label']], 'mode' => ['y' => ['text' => 'other']]], null],
]);

it('takes the first key translation another attribute matches', function (string $form, string $condition, ?array $original): void {
    // `kind` and `mode` both match, and each gives a translation for this attribute
    // under the same condition; the first is the one used.
    $byOtherAttribute = [
        'kind' => ['x' => [$condition => ['text' => 'label']]],
        'mode' => ['y' => [$condition => ['text' => 'other']]],
    ];
    $table = $form === 'default' ? ['default' => $byOtherAttribute] : $byOtherAttribute;

    expect(fmeTranslateChoiceKeys(['based_on_other_attribute_value' => $table], $original))
        ->toBe(['options' => [['label' => 'A', 'value' => 'a']]]);
})->with(['default', 'legacy'])->with([
    'not set, against the original field' => ['#not_set', ['field_options' => '{}']],
    'not empty' => ['#not_empty', null],
    'empty in the original field' => ['#empty', ['field_options' => '{"choices":""}']],
    'this value' => ['[{"text":"A","value":"a"}]', null],
]);

it('reads a key translation table kept whole by a value table as default and specific translations', function (): void {
    // A `based_on_attribute_value` key keeps the key translation whole, so its
    // `default` is still read as the default translations rather than as a key.
    expect(fmeTranslateChoiceKeys(['based_on_attribute_value' => ['no match' => []], 'default' => ['text' => 'label']]))
        ->toBe(['options' => [['label' => 'A', 'value' => 'a']]]);
});

it('reads no fieldtype-specific key translation for an empty fieldtype', function (): void {
    $mapped = fmeMap(
        ['choices' => [['text' => 'A', 'value' => 'a']]],
        ['choices' => ['statamic_attribute' => 'options', 'key_translation' => [
            'specific_based_on_attribute_value' => ['radio' => []],
            'specific' => ['' => ['text' => 'wrong']],
            'default' => ['text' => 'label'],
        ]]],
        ''
    );

    expect($mapped)->toBe(['options' => [['label' => 'A', 'value' => 'a']]]);
});

it('trims the configured characters from a text value and from each value in a list', function (mixed $value, mixed $expected): void {
    expect(fmeMap(['label' => $value], ['label' => ['statamic_attribute' => 'display', 'trim_characters' => ['.']]]))
        ->toBe(['display' => $expected]);
})->with([
    'text' => ['.Your name.', 'Your name'],
    'a list' => [['.a.', 'b.'], ['a', 'b']],
]);

it('drops the empty pieces of an exploded value and reindexes the list', function (): void {
    expect(fmeMap(['ext' => 'jpg,,png,'], ['ext' => ['statamic_attribute' => 'extensions', 'explode_string' => ',']]))
        ->toBe(['extensions' => ['jpg', 'png']]);
});

it('calculates only with a numeric value', function (mixed $value, mixed $expected): void {
    expect(fmeMap(['size' => $value], ['size' => ['statamic_attribute' => 'width', 'value_calculation' => '[value] * 2']]))
        ->toBe(['width' => $expected]);
})->with([
    'a number' => ['3', 6],
    'text' => ['wide', 'wide'],
]);

it('takes the first calculation that matches', function (array $calculation, ?array $original): void {
    // Two entries match each time; the first doubles the value, the second would
    // multiply it by ten.
    $mapped = fmeMap(
        ['size' => '3', 'kind' => 'x', 'mode' => 'y'],
        ['size' => ['statamic_attribute' => 'width', 'value_calculation' => $calculation]],
        'number',
        null,
        $original
    );

    expect($mapped)->toBe(['width' => 6]);
})->with([
    'not set in the original field, then any value' => [['specific_based_on_attribute_value' => ['number' => ['#not_set' => '[value] * 2', '*' => '[value] * 10']]], ['field_options' => '{}']],
    'a value pattern, then any value' => [['specific_based_on_attribute_value' => ['number' => ['3' => '[value] * 2', '*' => '[value] * 10']]], null],
    'other attribute values, by default' => [['based_on_other_attribute_value' => ['default' => ['kind' => ['x' => '[value] * 2'], 'mode' => ['y' => '[value] * 10']]]], null],
    'other attributes not set, by default' => [['based_on_other_attribute_value' => ['default' => ['gone' => ['#not_set' => '[value] * 2'], 'gone2' => ['#not_set' => '[value] * 10']]]], null],
    'other attribute values, for the fieldtype' => [['based_on_other_attribute_value' => ['specific' => ['number' => ['kind' => ['x' => '[value] * 2'], 'mode' => ['y' => '[value] * 10']]]]], null],
    'other attributes not set, for the fieldtype' => [['based_on_other_attribute_value' => ['specific' => ['number' => ['gone' => ['#not_set' => '[value] * 2'], 'gone2' => ['#not_set' => '[value] * 10']]]]], null],
    'other attribute values, legacy' => [['based_on_other_attribute_value' => ['kind' => ['x' => '[value] * 2'], 'mode' => ['y' => '[value] * 10']]], null],
    'other attributes not set, legacy' => [['based_on_other_attribute_value' => ['gone' => ['#not_set' => '[value] * 2'], 'gone2' => ['#not_set' => '[value] * 10']]], null],
]);

it('keeps escaped newlines unless the newline setting says to preserve them', function (mixed $setting, string $expected): void {
    expect(fmeMap(['body' => 'a\\nb'], ['body' => ['statamic_attribute' => 'text', 'preserve_newlines' => $setting]], 'textarea'))
        ->toBe(['text' => $expected]);
})->with([
    'true' => [true, "a\nb"],
    'false' => [false, 'a\\nb'],
    'a value table that matches' => [['based_on_attribute_value' => ['default' => ['a\\nb' => true]]], "a\nb"],
    'a value table that matches nothing' => [['based_on_attribute_value' => ['default' => ['other' => true]]], 'a\\nb'],
]);

it('leaves the value as it was when no calculation applies', function (array $calculation): void {
    expect(fmeMap(['size' => '3'], ['size' => ['statamic_attribute' => 'width', 'value_calculation' => $calculation]], 'number'))
        ->toBe(['width' => '3']);
})->with([
    'no entry matches' => [['specific_based_on_attribute_value' => ['number' => ['9' => '[value] * 2']]]],
    // The empty other-attribute table keeps the calculation table whole, so its
    // `default` is read as the expression
    'the expression is not text' => [['based_on_other_attribute_value' => [], 'default' => ['[value] * 2']]],
]);

it('translates references, unescapes slashes and builds fields only when asked', function (mixed $value, array $setting, mixed $expected): void {
    $fields = [['id' => 2, 'label' => 'Size']];

    expect(fmeMap(['body' => $value], ['body' => ['statamic_attribute' => 'text', ...$setting]], null, null, null, $fields))
        ->toBe(['text' => $expected]);
})->with([
    'a calculation reference, translated' => ['{Size:2} + 1', ['translate_field_references_calculation' => true], '[size_2] + 1'],
    'a calculation reference, not asked' => ['{Size:2} + 1', [], '{Size:2} + 1'],
    'an escaped slash, unescaped' => ['a\\/b', ['unescape_slashes' => true], 'a/b'],
    'an escaped slash, not asked' => ['a\\/b', [], 'a\\/b'],
    'choices as fields, for text' => ['plain', ['choices_as_fields' => true], 'plain'],
]);

/**
 * Map one `mode` attribute through a fieldtype-specific target table, as a radio field.
 *
 * @param  mixed  $value  The attribute value
 * @param  array<int|string, mixed>  $targets  The radio entry of `specific_based_on_attribute_value`
 * @param  array<int|string, mixed>  $extra  More attribute configuration
 * @param  array<int|string, mixed>|null  $original  The original field data, for `#not_set` checks
 * @return mixed The mapped attributes
 */
function fmeTargetByValue(mixed $value, array $targets, array $extra = [], ?array $original = null): mixed
{
    return fmeMap(
        ['mode' => $value],
        ['mode' => ['statamic_attribute' => ['specific_based_on_attribute_value' => ['radio' => $targets]], ...$extra]],
        'radio',
        null,
        $original
    );
}

it('writes to the first fieldtype-specific target that matches', function (array $targets, ?array $original, array $expected): void {
    expect(fmeTargetByValue('v', $targets, [], $original))->toBe($expected);
})->with([
    'not set in the original field, before any value' => [['#not_set' => 'first', '*' => 'second'], ['field_options' => '{}'], ['first' => '']],
    'not empty, before any value' => [['#not_empty' => 'first', '*' => 'second'], null, ['first' => 'v']],
]);

it('writes nothing to a fieldtype-specific target that is not text', function (mixed $value, string $pattern, ?array $original): void {
    expect(fmeTargetByValue($value, [$pattern => 5], [], $original))->toBe([]);
})->with([
    'not set in the original field' => ['v', '#not_set', ['field_options' => '{}']],
    'not empty' => ['v', '#not_empty', null],
    'a value' => ['v', 'v', null],
]);

it('substitutes the value written to a fieldtype-specific target only when asked', function (mixed $value, string $pattern, ?array $original, array $substitution, array $expected): void {
    $extra = $substitution === [] ? [] : ['value_substitution_regex' => $substitution];

    expect(fmeTargetByValue($value, [$pattern => 'to'], $extra, $original))->toBe($expected);
})->with([
    'not set in the original field' => ['v', '#not_set', ['field_options' => '{}'], [], ['to' => '']],
    'not set in the original field, substituted' => ['v', '#not_set', ['field_options' => '{}'], ['/^$/' => 'filled'], ['to' => 'filled']],
    'not empty' => ['v', '#not_empty', null, [], ['to' => 'v']],
    'not empty, substituted' => ['v', '#not_empty', null, ['/v/' => 'w'], ['to' => 'w']],
    // A list is not text, so the substitution reads it as empty text
    'not empty, a list substituted' => [['a'], '#not_empty', null, ['/^$/' => 'empty'], ['to' => 'empty']],
    'a value' => ['v', 'v', null, [], ['to' => 'v']],
    'a value, substituted' => ['v', 'v', null, ['/v/' => 'w'], ['to' => 'w']],
    'any value, a list substituted' => [['a'], '*', null, ['/^$/' => 'empty'], ['to' => 'empty']],
]);

it('fills a wildcard target from a list value as empty text', function (): void {
    // `*` matches a list, which is not text, so the part the wildcard captured is empty.
    expect(fmeTargetByValue(['a'], ['*' => 'to_*']))->toBe(['to_' => ['a']]);
});

it('matches a numeric fieldtype-specific pattern', function (): void {
    // A numeric pattern is an integer array key; it still matches the text value.
    expect(fmeTargetByValue('5', [5 => 'five']))->toBe(['five' => '5']);
});

it('maps an attribute with a numeric name', function (): void {
    expect(fmeMap([0 => 'x', 'label' => 'y'], [0 => ['statamic_attribute' => 'zero', 'value_translation' => ['x' => 'X']], 'label' => ['statamic_attribute' => 'display']]))
        ->toBe(['zero' => 'X', 'display' => 'y']);
});

it('sets no other field value for an attribute with no target', function (): void {
    expect(fmeMap(['label' => 'Your name'], ['label' => ['statamic_other_field_value' => ['default' => ['shown' => true]]]]))
        ->toBe([]);
});

it('sets the other field values of the first pattern that matches', function (string $form, string $condition, ?array $original): void {
    // The condition and `*` both match; only the first sets its values.
    $patterns = [$condition => ['first' => true], '*' => ['second' => true]];
    $table = match ($form) {
        'default' => ['default' => $patterns],
        'specific' => ['default' => [], 'specific' => ['radio' => $patterns]],
        'legacy' => $patterns,
    };

    $mapped = fmeMap(
        ['mode' => 'v'],
        ['mode' => ['statamic_other_field_value_based_on_attribute_value' => $table]],
        'radio',
        null,
        $original
    );

    expect($mapped)->toBe(['first' => true]);
})->with([
    'default, not set in the original field' => ['default', '#not_set', ['field_options' => '{}']],
    'default, not empty' => ['default', '#not_empty', null],
    'for the fieldtype, not set in the original field' => ['specific', '#not_set', ['field_options' => '{}']],
    'for the fieldtype, not empty' => ['specific', '#not_empty', null],
    // A legacy table has no `#not_empty` case
    'legacy, not set in the original field' => ['legacy', '#not_set', ['field_options' => '{}']],
]);
