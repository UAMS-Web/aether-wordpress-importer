<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms string and option helpers (#98).
 *
 * `FieldtypeMappingTrait` splits a delimited attribute (`explode_string`),
 * trims it (`trim`, `trim_characters`), and drops the empty pieces a stray
 * separator leaves, before translating each piece; the Gravity Forms
 * `allowedExtensions` and `rcwdupload` filter mappings turn an extension list
 * into MIME types that way. The file also covers the choice-to-field
 * conversion (`choices_as_fields`), snake-case handles, the expected option
 * keys, and the `exclude_fieldtypes` check. No standalone test named any of
 * these methods before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsStringAndOptionHelpersTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping as FormidableFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsHelperMethod(
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
 * Map a Gravity Forms field's attributes through the real mapping class.
 *
 * @param  array<string, mixed>  $field  The field data
 * @return array<int|string, mixed> The mapped attributes
 */
function mapGravityFormsHelperField(
    array $field
): array {
    return FieldtypeMapping::mapFieldAttributes(
        $field,
        [$field],
        \is_string($field['type']) ? $field['type'] : null
    );
}

it('turns a file upload extension list into MIME types, ignoring empty pieces', function (
    string $extensions,
    mixed $expected
): void {
    $mapped = mapGravityFormsHelperField([
        'id' => 2,
        'type' => 'fileupload',
        'label' => 'Upload',
        'allowedExtensions' => $extensions,
    ]);

    expect($mapped['mime_types'] ?? 'absent')->toBe($expected);
})->with([
    'one extension' => ['pdf', ['application/pdf']],
    'a dot and spaces' => [' pdf , .doc', ['application/pdf', 'application/msword']],
    'a trailing comma' => ['pdf, .doc,', ['application/pdf', 'application/msword']],
    'two commas in a row' => ['pdf,,doc', ['application/pdf', 'application/msword']],
    'only separators' => [' , ', 'absent'],
]);

it('drops an empty piece from an rcwdupload extension filter', function (): void {
    $mapped = mapGravityFormsHelperField([
        'id' => 9,
        'type' => 'rcwdupload',
        'label' => 'Files',
        'rcwdupload_filter' => 'Y',
        'rcwdupload_filter_compr' => ' .zip , rar ,',
    ]);

    expect($mapped['mime_types'] ?? 'absent')
        ->toBe(['application/zip', 'application/x-rar-compressed']);
});

it('turns list columns into text fields', function (): void {
    $field = [
        'id' => 4,
        'type' => 'list',
        'label' => 'Pick Some',
        'enableColumns' => true,
        'choices' => [
            ['text' => 'First One', 'value' => '1'],
            ['text' => 'Second', 'value' => '2'],
        ],
    ];

    expect(mapGravityFormsHelperField($field)['fields'] ?? 'absent')->toBe([
        ['handle' => 'pick_some_4_first_one_1', 'field' => ['type' => 'text', 'display' => 'First One']],
        ['handle' => 'pick_some_4_second_2', 'field' => ['type' => 'text', 'display' => 'Second']],
    ]);
});

it('splits a string on the configured separator', function (): void {
    expect(invokeFormsHelperMethod('explodeString', 'a,b', ['explode_string' => ',']))->toBe(['a', 'b'])
        ->and(invokeFormsHelperMethod('explodeString', ['x'], ['explode_string' => ',']))->toBe(['x'])
        ->and(invokeFormsHelperMethod('explodeString', 5, ['explode_string' => ',']))->toBe('')
        ->and(invokeFormsHelperMethod('explodeString', 'a,b', []))->toBe('a,b')
        ->and(invokeFormsHelperMethod('explodeString', 'a,b', ['explode_string' => '']))->toBe('a,b')
        ->and(invokeFormsHelperMethod('explodeString', 'a,b', ['explode_string' => 1]))->toBe('a,b');
});

it('trims a value or each piece of one when trim is on', function (): void {
    expect(invokeFormsHelperMethod('trimValue', ' a ', ['trim' => true]))->toBe('a')
        ->and(invokeFormsHelperMethod('trimValue', [' a ', 5, null, true], ['trim' => true]))->toBe(['a', '5', '', '1'])
        ->and(invokeFormsHelperMethod('trimValue', ' a ', ['trim' => '1']))->toBe(' a ')
        ->and(invokeFormsHelperMethod('trimValue', ' a ', []))->toBe(' a ')
        ->and(invokeFormsHelperMethod('trimValue', 5, ['trim' => true]))->toBe(5);
});

it('trims the configured characters from a value or each piece of one', function (): void {
    $config = ['trim_characters' => ['.', '/', 5]];

    expect(invokeFormsHelperMethod('trimCharacters', ' ..pdf/ ', $config))->toBe('pdf')
        ->and(invokeFormsHelperMethod('trimCharacters', ['.zip', ' .rar ', ['..x'], null, 7], $config))->toBe(['zip', 'rar', ['x'], null, '7'])
        ->and(invokeFormsHelperMethod('trimCharacters', '.a.', []))->toBe('.a.')
        ->and(invokeFormsHelperMethod('trimCharacters', null, $config))->toBeNull()
        ->and(invokeFormsHelperMethod('trimCharacters', [], $config))->toBe([])
        ->and(invokeFormsHelperMethod('trimCharacters', '.a.', ['trim_characters' => [1]]))->toBe('.a.');
});

it('converts text to a snake-case handle', function (
    string $text,
    string $expected
): void {
    expect(invokeFormsHelperMethod('convertToSnakeCase', $text))->toBe($expected);
})->with([
    'spaces and capitals' => ['  Foo Bar ', 'foo_bar'],
    'runs of separators' => ['a--b__c', 'a_b_c'],
    'leading and trailing underscores' => ['__X__', 'x'],
    'an accented letter' => ['Café au lait', 'caf_au_lait'],
    'nothing' => ['', ''],
]);

it('names a field for each choice, from its text or else its value', function (): void {
    $fieldData = [
        'id' => 4,
        'label' => 'Pick Some',
        'choices' => [
            ['text' => 'First One', 'value' => '1'],
            ['value' => 'Only Value'],
        ],
    ];

    expect(invokeFormsHelperMethod('convertChoicesToFields', [['text' => 'Ignored']], $fieldData))->toBe([
        ['handle' => 'pick_some_4_first_one_1', 'field' => ['type' => 'text', 'display' => 'First One']],
        ['handle' => 'pick_some_4_only_value_2', 'field' => ['type' => 'text', 'display' => 'Only Value']],
    ])->and(invokeFormsHelperMethod('convertChoicesToFields', [['text' => 'P']], ['id' => 4, 'label' => 'L']))->toBe([
        ['handle' => 'l_4_p_1', 'field' => ['type' => 'text', 'display' => 'P']],
    ]);
});

it('reads the expected option keys for a fieldtype', function (): void {
    expect(FieldtypeMapping::getExpectedKeys([], null, 'product'))->toBe(['id', 'key', 'label', 'price'])
        ->and(FieldtypeMapping::getExpectedKeys([], null, 'checkbox'))->toBe(['key', 'value'])
        ->and(FieldtypeMapping::getExpectedKeys())->toBe(['key', 'value'])
        ->and(FormidableFieldtypeMapping::getExpectedKeys([], null, 'checkbox'))->toBe(['key', 'value']);
});

it('adds the missing expected keys to each option', function (): void {
    $options = [['key' => 'a'], 'not an option', ['value' => 1]];
    $empty = [];
    $method = new ReflectionMethod(FieldtypeMapping::class, 'normalizeOptionsStructure');

    $arguments = [&$options, [], null, 'product'];
    $method->invokeArgs(null, $arguments);
    $emptyArguments = [&$empty];
    $method->invokeArgs(null, $emptyArguments);

    expect($options)->toBe([
        ['key' => 'a', 'id' => null, 'label' => null, 'price' => null],
        'not an option',
        ['value' => 1, 'id' => null, 'key' => null, 'label' => null, 'price' => null],
    ])->and($empty)->toBe([]);
});

it('excludes a fieldtype only when the exclusion list names it', function (
    mixed $config,
    string $fieldtype,
    bool $expected
): void {
    expect(invokeFormsHelperMethod('shouldExcludeFieldtype', $config, $fieldtype))->toBe($expected);
})->with([
    'listed' => [['exclude_fieldtypes' => ['consent']], 'consent', true],
    'not listed' => [['exclude_fieldtypes' => ['consent']], 'text', false],
    'no list' => [[], 'consent', false],
    'a list that is a string' => [['exclude_fieldtypes' => 'consent'], 'consent', false],
]);
