<?php

declare(strict_types=1);

/**
 * Unit tests for the Formidable Forms `options` transform (#98).
 *
 * Formidable exports a choice field's options as a JSON string, and the
 * `options` mapping in the Formidable `FieldtypeMapping` sets
 * `transform_json_to_array`, which routes that string through
 * `FieldtypeMappingTrait::transformOptionsJsonToArray()`. These tests drive the
 * transform through the public `mapFieldAttributes()` entry point for the
 * configuration the importer really uses, and call the protected method
 * directly for the two configuration keys (`key_translation` absent, and
 * `expected_keys`) that no shipped mapping sets but the trait still honors.
 *
 * @command  php vendor/bin/pest tests/Unit/FormidableFormsOptionsTransformTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping;

/**
 * Build a Formidable choice field whose options are a JSON string.
 *
 * @param  array<int|string, mixed>  $options  The options to encode
 * @param  array<string, mixed>  $fieldOptions  The `field_options` to encode
 * @param  string  $type  The Formidable field type
 * @return array<string, mixed> The field data
 */
function formidableChoiceField(
    array $options,
    array $fieldOptions = [],
    string $type = 'radio'
): array {
    return [
        'id' => '7',
        'type' => $type,
        'name' => 'Pick one',
        'options' => (string) \json_encode($options),
        'field_options' => (string) \json_encode($fieldOptions),
    ];
}

/**
 * Map a Formidable field's attributes through the real mapping class.
 *
 * @param  array<string, mixed>  $field  The field data
 * @return array<int|string, mixed> The mapped attributes
 */
function mapFormidableChoiceField(
    array $field
): array {
    return FieldtypeMapping::mapFieldAttributes(
        $field,
        [$field],
        \is_string($field['type']) ? $field['type'] : null
    );
}

/**
 * Invoke the protected options transform with a configuration of the test's own.
 *
 * @param  string  $optionsJson  The options JSON string
 * @param  array<string, mixed>  $fieldData  The field data
 * @param  array<string, mixed>  $config  The mapping configuration
 * @param  ?string  $fieldType  The source field type
 * @return mixed The transformed options
 */
function transformFormidableOptions(
    string $optionsJson,
    array $fieldData,
    array $config,
    ?string $fieldType = null
): mixed {
    $method = new ReflectionMethod(
        FieldtypeMapping::class,
        'transformOptionsJsonToArray'
    );

    return $method->invoke(
        null,
        $optionsJson,
        $fieldData,
        $config,
        $fieldType
    );
}

it('keys each option by its label when separate values are off', function (): void {
    $mapped = mapFormidableChoiceField(formidableChoiceField([
        ['label' => 'Red', 'value' => 'r'],
        ['label' => 'Blue', 'value' => 'b'],
    ]));

    expect($mapped['options'] ?? 'absent')->toBe([
        ['key' => 'Red', 'value' => null],
        ['key' => 'Blue', 'value' => null],
    ]);
});

it('keys each option by its saved value when field_options turns separate values on', function (): void {
    $mapped = mapFormidableChoiceField(formidableChoiceField(
        [
            ['label' => 'Red', 'value' => 'r'],
            ['label' => 'Blue', 'value' => 'b'],
        ],
        ['separate_value' => '1']
    ));

    expect($mapped['options'] ?? 'absent')->toBe([
        ['key' => 'r', 'value' => 'Red'],
        ['key' => 'b', 'value' => 'Blue'],
    ]);
});

it('reads separate values from the field itself before field_options', function (
    mixed $topLevel,
    string $fieldOptionsFlag,
    array $expectedOption
): void {
    $field = formidableChoiceField(
        [['label' => 'Red', 'value' => 'r']],
        ['separate_value' => $fieldOptionsFlag]
    );
    $field['separate_value'] = $topLevel;

    $mapped = mapFormidableChoiceField($field);

    expect($mapped['options'] ?? 'absent')->toBe([$expectedOption]);
})->with([
    'on at the field, off in field_options' => ['1', '0', ['key' => 'r', 'value' => 'Red']],
    'off at the field, on in field_options' => ['0', '1', ['key' => 'r', 'value' => 'Red']],
    'off at both' => ['0', '0', ['key' => 'Red', 'value' => null]],
]);

it('treats field_options that are not a JSON object as separate values off', function (): void {
    $field = formidableChoiceField([['label' => 'Red', 'value' => 'r']]);
    $field['field_options'] = 'not json';

    expect(mapFormidableChoiceField($field)['options'] ?? 'absent')->toBe([
        ['key' => 'Red', 'value' => null],
    ]);
});

it('treats field_options without a separate_value entry as separate values off', function (): void {
    $mapped = mapFormidableChoiceField(formidableChoiceField(
        [['label' => 'Red', 'value' => 'r']],
        ['other' => '1']
    ));

    expect($mapped['options'] ?? 'absent')->toBe([
        ['key' => 'Red', 'value' => null],
    ]);
});

it('drops options that are blank, unlabeled or not arrays', function (): void {
    $mapped = mapFormidableChoiceField(formidableChoiceField([
        ['label' => '', 'value' => ''],
        ['value' => 'no label'],
        'a bare string',
        ['label' => 'Kept', 'value' => 'k'],
        ['label' => '', 'value' => 'blank label, real value'],
    ]));

    expect($mapped['options'] ?? 'absent')->toBe([
        ['key' => 'Kept', 'value' => null],
        ['key' => '', 'value' => null],
    ]);
});

it('drops the image and src keys a Formidable image option carries', function (): void {
    $mapped = mapFormidableChoiceField(formidableChoiceField(
        [['label' => 'Red', 'value' => 'r', 'image' => '12', 'src' => 'https://example.test/r.png']],
        ['separate_value' => '1']
    ));

    expect($mapped['options'] ?? 'absent')->toBe([
        ['key' => 'r', 'value' => 'Red'],
    ]);
});

it('maps no options when the options JSON is not an array', function (string $json): void {
    $field = formidableChoiceField([]);
    $field['options'] = $json;

    expect(mapFormidableChoiceField($field))->not->toHaveKey('options')
        ->and(mapFormidableChoiceField($field))->toHaveKey('display');
})->with([
    'not JSON' => ['not json'],
    'a JSON scalar' => ['5'],
]);

it('carries an other_ option as the trimmed other label on a radio or checkbox field', function (string $type): void {
    $mapped = mapFormidableChoiceField(formidableChoiceField(
        [['label' => 'Red', 'value' => 'r'], 'other_3' => '  Something else  '],
        [],
        $type
    ));

    expect($mapped['other_label'] ?? 'absent')->toBe('Something else')
        ->and($mapped['options'] ?? 'absent')->toBe([
            ['key' => 'Red', 'value' => null],
        ]);
})->with(['radio', 'checkbox']);

it('drops the other label on a field type that has none', function (): void {
    $mapped = mapFormidableChoiceField(formidableChoiceField(
        [['label' => 'Red', 'value' => 'r'], 'other_3' => 'Something else'],
        [],
        'select'
    ));

    expect($mapped)->not->toHaveKey('other_label')
        ->and($mapped['options'] ?? 'absent')->toBe([
            ['key' => 'Red', 'value' => null],
        ]);
});

it('ignores an other_ option whose value is not a string', function (): void {
    $mapped = mapFormidableChoiceField(formidableChoiceField(
        [['label' => 'Red', 'value' => 'r'], 'other_3' => ['label' => 'Something else']]
    ));

    expect($mapped)->not->toHaveKey('other_label');
});

it("does not carry one field's other label onto the next field", function (): void {
    mapFormidableChoiceField(formidableChoiceField(
        [['label' => 'Red', 'value' => 'r'], 'other_3' => 'Something else']
    ));

    $next = mapFormidableChoiceField(formidableChoiceField(
        [['label' => 'Blue', 'value' => 'b']]
    ));

    expect($next)->not->toHaveKey('other_label');
});

it('does not decode options that already arrive as an array', function (): void {
    $field = formidableChoiceField([]);
    $field['options'] = [['label' => 'Red', 'value' => 'r'], 'other_3' => 'Something else'];

    expect(mapFormidableChoiceField($field))->not->toHaveKey('other_label');
});

it('falls back to label keys without a key translation', function (): void {
    $options = (string) \json_encode([
        ['label' => 'Red', 'value' => 'r'],
        ['label' => 'Blue'],
    ]);

    expect(transformFormidableOptions($options, [], []))->toBe([
        ['key' => 'Red', 'value' => null],
        ['key' => 'Blue', 'value' => null],
    ]);
});

it('falls back to value keys without a key translation when separate values are on', function (): void {
    $options = (string) \json_encode([
        ['label' => 'Red', 'value' => 'r'],
        ['label' => 'Blue'],
    ]);
    $fieldData = ['field_options' => (string) \json_encode(['separate_value' => '1'])];

    expect(transformFormidableOptions($options, $fieldData, []))->toBe([
        ['key' => 'r', 'value' => 'Red'],
        ['key' => 'Blue', 'value' => null],
    ]);
});

it('skips a key translation entry whose source or target is excluded', function (): void {
    $options = (string) \json_encode([['label' => 'Red', 'value' => 'r', 'note' => 'n']]);
    $config = [
        'key_translation' => [
            'label' => 'key',
            'note' => null,
        ],
    ];

    expect(transformFormidableOptions($options, [], $config))->toBe([
        ['key' => 'Red', 'value' => null],
    ]);
});

it('fills the expected keys a configuration asks for', function (): void {
    $options = (string) \json_encode([['label' => 'Red', 'value' => 'r']]);
    $config = [
        'expected_keys' => ['key', 'value'],
        'key_translation' => ['label' => 'key'],
    ];

    expect(transformFormidableOptions($options, [], $config, 'radio'))->toBe([
        ['key' => 'Red', 'value' => null],
    ]);
});
