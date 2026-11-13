<?php

declare(strict_types=1);

/**
 * Unit tests for the edges of the shared Forms option, translation and modifier
 * helpers (#537).
 *
 * A Linux mutation run of `FieldtypeMappingTrait` found mutants that the
 * existing tests let through in the lower part of the trait: the options JSON
 * transform, value translation, the based-on-attribute lookups, the modifier
 * pattern hierarchy and the array replacement check. Each case here names the
 * input that tells the original from the mutant. The methods are called on the
 * Gravity Forms mapping class, which uses the trait.
 *
 * Mutants no input can kill are annotated with `@pest-mutate-ignore` in the
 * source, each with its reason beside it.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldtypeMappingEdgeCasesLowerTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Call a protected or private static method of the trait through the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function fmlCall(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(FieldtypeMapping::class, $method)->invoke(null, ...$arguments);
}

/**
 * Read the expected option keys for a mapping class whose `choices` mapping is given.
 *
 * The keys are read through a subclass, so the Gravity Forms mapping itself is
 * left untouched.
 *
 * @param  mixed  $choicesMapping  The `choices` attribute mapping
 * @return array<string> The expected keys
 */
function fmlExpectedKeys(mixed $choicesMapping): array
{
    $mapping = new class extends FieldtypeMapping
    {
        /**
         * The attribute mapping the expected keys are read from.
         *
         * @var array<string, array<int|string, mixed>>
         */
        protected static array $fieldAttributeMapping = [];
    };
    new ReflectionProperty($mapping, 'fieldAttributeMapping')->setValue(null, ['choices' => $choicesMapping]);

    return $mapping::getExpectedKeys();
}

/**
 * Pass a value through unchanged, as a modifier result processor.
 *
 * @param  mixed  $value  The matched value
 * @return mixed The same value
 */
function fmlSame(mixed $value): mixed
{
    return $value;
}

// transformOptionsJsonToArray()

it('transforms options JSON that is not a list to no options', function (): void {
    expect(fmlCall('transformOptionsJsonToArray', '"one option"', [], []))->toBe([]);
});

it('transforms the options after an other label', function (): void {
    // The other_* entry carries the other label and is not an option itself.
    $options = fmlCall('transformOptionsJsonToArray', '{"other_1":"Other","0":{"label":"Red","value":"r"}}', [], []);

    expect($options)->toBe([['key' => 'Red', 'value' => null]]);
});

it('keeps the options plain when the field options do not separate values', function (): void {
    // The field options decode to a table with no separate_value entry.
    $options = fmlCall(
        'transformOptionsJsonToArray',
        '[{"label":"Red","value":"r"}]',
        ['field_options' => '{"size":"large"}'],
        []
    );

    expect($options)->toBe([['key' => 'Red', 'value' => null]]);
});

it('translates option keys after a translation entry that names no target', function (): void {
    // A null target excludes the value entry; the label entry after it still maps.
    $options = fmlCall(
        'transformOptionsJsonToArray',
        '[{"label":"Red","value":"r"}]',
        [],
        ['key_translation' => ['value' => null, 'label' => 'key']]
    );

    expect($options)->toBe([['key' => 'Red', 'value' => null]]);
});

it('adds no value to a separated option that translates to a key alone', function (): void {
    $options = fmlCall(
        'transformOptionsJsonToArray',
        '[{"label":"Red","value":"r"}]',
        ['separate' => true],
        ['separate_value_field_option' => 'separate', 'key_translation' => ['label' => 'key']]
    );

    expect($options)->toBe([['key' => 'Red']]);
});

it('adds the expected keys to the options only when the configuration asks for them', function (): void {
    // The translation writes a `text` key, so neither `key` nor `value` is set.
    $translation = ['key_translation' => ['label' => 'text']];

    expect(fmlCall('transformOptionsJsonToArray', '[{"label":"Red","value":"r"}]', [], $translation + ['expected_keys' => true]))
        ->toBe([['text' => 'Red', 'key' => null, 'value' => null]])
        ->and(fmlCall('transformOptionsJsonToArray', '[{"label":"Red","value":"r"}]', [], $translation))
        ->toBe([['text' => 'Red']]);
});

// translateFieldReferences()

it('writes the generic placeholder for a current field with an empty name', function (): void {
    expect(fmlCall('translateFieldReferences', 'Copy of [field_name]', [], ['label' => '', 'id' => 7]))
        ->toBe('Copy of [current_field_name]');
});

// applyValueCalculation()

it('keeps a calculated result of exactly two to the 63rd as a float', function (): void {
    // The result equals PHP_INT_MAX as a float, which is past the largest int.
    $result = fmlCall('applyValueCalculation', '4611686018427387904', '[value] * 2');

    expect($result)->toBe(9223372036854775808.0);
});

// applyValueTranslation()

it('reads the default other-attribute translation for a missing fieldtype', function (): void {
    // An entry keyed by the empty string is not the specific table of a missing fieldtype.
    $translated = fmlCall(
        'applyValueTranslation',
        'a',
        ['value_translation' => ['based_on_other_attribute_value' => [
            'default' => ['kind' => ['big' => ['a' => 'default']]],
            'specific' => ['' => ['kind' => ['big' => ['a' => 'specific']]]],
        ]]],
        'label',
        ['label' => 'a', 'kind' => 'big'],
        null,
        null,
        'display'
    );

    expect($translated)->toBe('default');
});

it('reads the default translation for a missing fieldtype', function (): void {
    $translated = fmlCall(
        'applyValueTranslation',
        'a',
        ['value_translation' => ['default' => ['a' => 'default'], 'specific' => ['' => ['a' => 'specific']]]],
        'label',
        ['label' => 'a'],
        null,
        null,
        'display'
    );

    expect($translated)->toBe('default');
});

it('matches a wildcard translation after a special-case key', function (): void {
    // The label is set, so `#not_set` does not apply, and the wildcard after it does.
    $translated = fmlCall(
        'applyValueTranslation',
        'ax',
        ['value_translation' => ['#not_set' => 'unset', 'a*' => 'b*']],
        'label',
        ['label' => 'ax'],
        null,
        null,
        'display'
    );

    expect($translated)->toBe('bx');
});

it('applies only the first matching wildcard translation', function (): void {
    // The first result would match the second pattern too.
    $translated = fmlCall(
        'applyValueTranslation',
        'ax',
        ['value_translation' => ['a*' => 'b*', 'b*' => 'c*']],
        'label',
        ['label' => 'ax'],
        null,
        null,
        'display'
    );

    expect($translated)->toBe('bx');
});

// translateCalculationFieldReferences()

it('leaves a calculation reference without a descriptor unchanged', function (): void {
    // `{123}` has no `descriptor:` part, so it is not a field reference even
    // though a field with that id exists.
    expect(fmlCall('translateCalculationFieldReferences', '{123} + 1', [['id' => 123, 'label' => 'Amount']]))
        ->toBe('{123} + 1');
});

// convertChoicesToFields()

it('names choice fields after a field with no id, and a choice with no text', function (): void {
    $fields = fmlCall('convertChoicesToFields', [], ['label' => 'Pick', 'choices' => [['text' => 'Red'], []]]);

    expect($fields)->toBe([
        ['handle' => 'pick__red_1', 'field' => ['type' => 'text', 'display' => 'Red']],
        ['handle' => 'pick___2', 'field' => ['type' => 'text', 'display' => '']],
    ]);
});

it('names choice fields after a field with no label', function (): void {
    $fields = fmlCall('convertChoicesToFields', [], ['id' => 5, 'choices' => [['text' => 'Red']]]);

    expect($fields)->toBe([
        ['handle' => '_5_red_1', 'field' => ['type' => 'text', 'display' => 'Red']],
    ]);
});

// getExpectedKeys()

it('reads the configured default expected keys as a list of strings', function (): void {
    expect(fmlExpectedKeys(['expected_keys' => ['default' => ['first' => 1, 'second' => 'label']]]))
        ->toBe(['1', 'label']);
});

it('expects a key and a value when the expected keys have no default', function (): void {
    expect(fmlExpectedKeys(['expected_keys' => ['specific' => ['product' => ['id']]]]))
        ->toBe(['key', 'value']);
});

// processBasedOnOtherAttributeValue()

it('reads the default other-attribute table for a missing fieldtype', function (): void {
    $result = fmlCall(
        'processBasedOnOtherAttributeValue',
        ['specific' => ['' => ['kind' => ['big' => 'specific']]], 'default' => ['kind' => ['big' => 'default']]],
        null,
        ['kind' => 'big']
    );

    expect($result)->toBe(['default', true]);
});

it('takes the first matching other attribute of a fieldtype-specific table', function (): void {
    $result = fmlCall(
        'processBasedOnOtherAttributeValue',
        ['specific' => ['text' => ['kind' => ['big' => 'first'], 'size' => ['large' => 'second']]]],
        'text',
        ['kind' => 'big', 'size' => 'large']
    );

    expect($result)->toBe(['first', true]);
});

it('takes the first matching other attribute of the default table', function (): void {
    $result = fmlCall(
        'processBasedOnOtherAttributeValue',
        ['default' => ['kind' => ['big' => 'first'], 'size' => ['large' => 'second']]],
        null,
        ['kind' => 'big', 'size' => 'large']
    );

    expect($result)->toBe(['first', true]);
});

it('keeps a fieldtype-specific other-attribute match when there is no default table', function (): void {
    $result = fmlCall(
        'processBasedOnOtherAttributeValue',
        ['specific' => ['text' => ['kind' => ['big' => 'specific']]]],
        'text',
        ['kind' => 'big']
    );

    expect($result)->toBe(['specific', true]);
});

// processBasedOnAttributeValue()

it('keeps a fieldtype-specific attribute-value match when there is no default table', function (): void {
    $result = fmlCall(
        'processBasedOnAttributeValue',
        ['specific' => ['text' => ['Hi' => 'specific']]],
        'text',
        'label',
        'Hi',
        ['label' => 'Hi']
    );

    expect($result)->toBe(['specific', true]);
});

// shouldExcludeFieldtype()

it('does not exclude a missing fieldtype for an empty excluded fieldtype', function (): void {
    expect(fmlCall('shouldExcludeFieldtype', ['exclude_fieldtypes' => ['']], null))->toBeFalse();
});

// trimCharacters()

it('trims nothing when no trim character is a string', function (): void {
    // An integer is not a character to trim, and with none left even the
    // surrounding whitespace stays.
    expect(fmlCall('trimCharacters', ' 5x5 ', ['trim_characters' => [5]]))->toBe(' 5x5 ');
});

// processAttributeMapping()

it('keeps an attribute-value target over a fieldtype-specific other-attribute one', function (): void {
    $mapped = fmlCall(
        'processAttributeMapping',
        'label',
        'Hi',
        ['statamic_attribute' => [
            'based_on_attribute_value' => ['default' => ['Hi' => 'first']],
            'based_on_other_attribute_value' => ['specific' => ['kind' => ['big' => 'second']]],
        ]],
        ['label' => 'Hi', 'kind' => 'big'],
        null,
        null
    );

    expect($mapped)->toBe(['first' => 'Hi']);
});

it('keeps a fieldtype-specific other-attribute target over the default one', function (): void {
    $mapped = fmlCall(
        'processAttributeMapping',
        'label',
        'Hi',
        ['statamic_attribute' => [
            'based_on_other_attribute_value' => [
                'specific' => ['kind' => ['big' => 'first']],
                'default' => ['kind' => ['big' => 'second']],
            ],
        ]],
        ['label' => 'Hi', 'kind' => 'big'],
        null,
        null
    );

    expect($mapped)->toBe(['first' => 'Hi']);
});

it('keeps an attribute-value target over a legacy other-attribute one', function (): void {
    $mapped = fmlCall(
        'processAttributeMapping',
        'label',
        'Hi',
        ['statamic_attribute' => [
            'based_on_attribute_value' => ['default' => ['Hi' => 'first']],
            'based_on_other_attribute_value' => ['kind' => ['big' => 'second']],
        ]],
        ['label' => 'Hi', 'kind' => 'big'],
        null,
        null
    );

    expect($mapped)->toBe(['first' => 'Hi']);
});

it('maps a target from the default or the legacy other-attribute table', function (array $basedOnOtherAttributeValue): void {
    $mapped = fmlCall(
        'processAttributeMapping',
        'label',
        'Hi',
        ['statamic_attribute' => ['based_on_other_attribute_value' => $basedOnOtherAttributeValue]],
        ['label' => 'Hi', 'kind' => 'big'],
        null,
        null
    );

    expect($mapped)->toBe(['display' => 'Hi']);
})->with([
    'default' => [['default' => ['kind' => ['big' => 'display']]]],
    'legacy' => [['kind' => ['big' => 'display']]],
]);

it('maps an unresolved target through the full attribute mapping', function (): void {
    // The lookup here reads exact values only; the full mapping also reads `#not_set`.
    $mapped = fmlCall(
        'processAttributeMapping',
        'label',
        'Hi',
        ['statamic_attribute' => ['based_on_other_attribute_value' => ['kind' => ['#not_set' => 'display']]]],
        ['label' => 'Hi'],
        null,
        null
    );

    expect($mapped)->toBe(['display' => 'Hi']);
});

// processModifierPatterns()

it('matches no fieldtype-specific modifier for an empty fieldtype', function (): void {
    $empty = ['value' => false, 'matchFound' => false];

    expect(fmlCall(
        'processModifierPatterns',
        ['based_on_attribute_value' => ['specific' => ['specific' => ['' => ['Hi' => true]]]]],
        [],
        null,
        '',
        'label',
        'Hi'
    ))->toBe($empty)
        ->and(fmlCall(
            'processModifierPatterns',
            ['based_on_other_attribute_value' => ['specific' => ['specific' => ['' => ['kind' => ['big' => true]]]]]],
            ['kind' => 'big'],
            null,
            ''
        ))->toBe($empty);
});

it('reads a fieldtype-specific attribute-value modifier as given, or as a boolean', function (string $table): void {
    $config = static fn (mixed $target): array => ['based_on_attribute_value' => ['specific' => [
        $table => $table === 'specific' ? ['text' => ['Hi' => $target]] : ['Hi' => $target],
    ]]];
    $read = static fn (mixed $target, ?callable $processor): mixed => fmlCall(
        'processModifierPatterns',
        $config($target),
        [],
        $processor,
        'text',
        'label',
        'Hi'
    );

    expect($read('yes', fmlSame(...)))->toBe(['value' => 'yes', 'matchFound' => true])
        ->and($read('yes', null))->toBe(['value' => true, 'matchFound' => true])
        ->and($read(0, null))->toBe(['value' => false, 'matchFound' => true]);
})->with(['specific', 'default']);

it('keeps a fieldtype-specific attribute-value modifier over the default one', function (): void {
    $result = fmlCall(
        'processModifierPatterns',
        ['based_on_attribute_value' => [
            'specific' => ['default' => ['Hi' => 'first']],
            'default' => ['Hi' => 'second'],
        ]],
        [],
        fmlSame(...),
        null,
        'label',
        'Hi'
    );

    expect($result)->toBe(['value' => 'first', 'matchFound' => true]);
});

it('keeps an attribute-value modifier over a fieldtype-specific other-attribute one', function (): void {
    $result = fmlCall(
        'processModifierPatterns',
        [
            'based_on_attribute_value' => ['default' => ['Hi' => 'first']],
            'based_on_other_attribute_value' => ['specific' => ['specific' => ['text' => ['kind' => ['big' => 'second']]]]],
        ],
        ['kind' => 'big'],
        fmlSame(...),
        'text',
        'label',
        'Hi'
    );

    expect($result)->toBe(['value' => 'first', 'matchFound' => true]);
});

it('reads a fieldtype-specific other-attribute modifier as a boolean', function (array $specificTable): void {
    $result = fmlCall(
        'processModifierPatterns',
        ['based_on_other_attribute_value' => ['specific' => $specificTable]],
        ['kind' => 'big'],
        null,
        'text'
    );

    expect($result)->toBe(['value' => true, 'matchFound' => true]);
})->with([
    'fieldtype-specific' => [['specific' => ['text' => ['kind' => ['big' => 'yes']]]]],
    'default' => [['default' => ['kind' => ['big' => 'yes']]]],
    'legacy' => [['kind' => ['big' => 'yes']]],
]);

it('keeps an attribute-value modifier over the fieldtype-specific one', function (): void {
    $result = fmlCall(
        'processModifierPatterns',
        ['based_on_attribute_value' => ['default' => ['Hi' => 'first']], 'specific' => ['text' => 'second']],
        [],
        fmlSame(...),
        'text',
        'label',
        'Hi'
    );

    expect($result)->toBe(['value' => 'first', 'matchFound' => true]);
});

// processModifierPatternsWithReturn()

it('returns the fallback for a fieldtype-specific modifier keyed by an empty fieldtype', function (): void {
    expect(fmlCall(
        'processModifierPatternsWithReturn',
        ['based_on_attribute_value' => ['specific' => ['specific' => ['' => ['Hi' => 'specific']]]]],
        'Hi',
        [],
        'fallback',
        null,
        '',
        'label'
    ))->toBe('fallback')
        ->and(fmlCall(
            'processModifierPatternsWithReturn',
            ['based_on_other_attribute_value' => ['specific' => ['specific' => ['' => ['kind' => ['big' => 'specific']]]]]],
            null,
            ['kind' => 'big'],
            'fallback',
            null,
            ''
        ))->toBe('fallback');
});

it('returns the matched attribute-value modifier target', function (string $table): void {
    $config = ['based_on_attribute_value' => ['specific' => [
        $table => $table === 'specific' ? ['text' => ['Hi' => 'target']] : ['Hi' => 'target'],
    ]]];

    expect(fmlCall('processModifierPatternsWithReturn', $config, 'Hi', [], 'fallback', null, 'text', 'label'))
        ->toBe('target')
        ->and(fmlCall('processModifierPatternsWithReturn', $config, 'Hi', [], 'fallback', fmlSame(...), 'text', 'label'))
        ->toBe('target');
})->with(['specific', 'default']);

// checkForArrayReplacement()

it('does not read a default table as a list of array replacements', function (): void {
    // The default is one search and replace pair, not a list of them.
    expect(fmlCall('checkForArrayReplacement', ['a'], ['default' => ['search' => ['a'], 'replace' => 'replaced']]))
        ->toBeFalse();
});

it('replaces no array value for a matching search without a replacement', function (array $translationConfig): void {
    expect(fmlCall('checkForArrayReplacement', ['a'], $translationConfig))->toBeFalse();
})->with([
    'list' => [[['search' => ['a']]]],
    'specific' => [['specific' => ['text' => [['search' => ['a']]]]]],
]);

// mappingLookupKey() and numericChoiceCount()

it('reads an array that cannot be encoded as an empty lookup key', function (): void {
    // The byte is not valid UTF-8, so the array has no JSON form.
    expect(fmlCall('mappingLookupKey', ["\xB1"]))->toBe('');
});

it('counts from a decimal string index as its whole part', function (): void {
    expect(fmlCall('numericChoiceCount', '3.5'))->toBe(4);
});
