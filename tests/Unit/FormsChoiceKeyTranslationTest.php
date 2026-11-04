<?php

declare(strict_types=1);

/**
 * Unit tests for translating the keys of a Forms choice array (#98).
 *
 * `FieldtypeMappingTrait::mapArrayWithKeyTranslation()` renames the keys of a
 * choice array (Gravity Forms `choices`) to the Statamic option keys, drops
 * the keys a translation maps to `null`, applies each key's value
 * transformations, and generates the keys an option needs but the choice does
 * not carry. The Gravity Forms `product` field generates its option `id` and
 * `key` that way, numbered by the choice's position. No standalone test
 * reached the method before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsChoiceKeyTranslationTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Translate an array's keys through the protected method.
 *
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The translated array
 */
function translateFormsChoiceKeys(
    mixed ...$arguments
): mixed {
    return new ReflectionMethod(
        FieldtypeMapping::class,
        'mapArrayWithKeyTranslation'
    )->invoke(
        null,
        ...$arguments
    );
}

/**
 * Map a Gravity Forms radio `product` field with the given choices.
 *
 * @param  array<int, array<string, mixed>>  $choices  The field's choices
 * @return mixed The mapped options
 */
function mapGravityFormsProductOptions(
    array $choices
): mixed {
    $field = [
        'id' => 11,
        'type' => 'product',
        'label' => 'Pick',
        'inputType' => 'radio',
        'choices' => $choices,
    ];

    return FieldtypeMapping::mapFieldAttributes($field, [$field], 'product')['options'] ?? 'absent';
}

it('numbers generated product option keys by position when the choices carry a price', function (): void {
    expect(mapGravityFormsProductOptions([
        ['text' => 'Red Ball', 'value' => 'r', 'isSelected' => false, 'price' => '$1,234.50'],
        ['text' => 'Blue', 'value' => 'b', 'isSelected' => true, 'price' => '$5'],
    ]))->toBe([
        ['label' => 'Red Ball', 'price' => 1234.5, 'id' => 'pick_11_r_1', 'key' => 'pick_11_red_ball_1'],
        ['label' => 'Blue', 'price' => 5.0, 'id' => 'pick_11_b_2', 'key' => 'pick_11_blue_2'],
    ]);
});

it('numbers generated product option keys by position when the choices carry no price', function (): void {
    expect(mapGravityFormsProductOptions([
        ['text' => 'Red', 'value' => 'r', 'isSelected' => false],
        ['text' => 'Blue', 'value' => 'b', 'isSelected' => false],
    ]))->toBe([
        ['label' => 'Red', 'id' => 'pick_11_r_1', 'key' => 'pick_11_red_1', 'price' => null],
        ['label' => 'Blue', 'id' => 'pick_11_b_2', 'key' => 'pick_11_blue_2', 'price' => null],
    ]);
});

it('renames, drops and keeps the keys of each choice', function (): void {
    expect(translateFormsChoiceKeys(
        [
            ['text' => 'Red', 'value' => 'r', 'price' => '$2', 'extra' => 'x'],
            ['text' => 'Blue', 'price' => '$1,000'],
        ],
        ['text' => 'label', 'value' => null]
    ))->toBe([
        ['label' => 'Red', 'price' => '$2', 'extra' => 'x'],
        ['label' => 'Blue', 'price' => '$1,000'],
    ]);
});

it('transforms the values of translated and untranslated keys alike', function (): void {
    expect(translateFormsChoiceKeys(
        [
            ['text' => 'Red', 'value' => 'r', 'price' => '$2', 'extra' => 'x'],
            ['text' => 'Blue', 'price' => '$1,000'],
        ],
        ['text' => 'label', 'value' => null],
        ['default' => [
            'price' => ['convert_dollar_amount' => true],
            'extra' => ['set_to_null' => true],
            'text' => ['set_to_null' => false],
        ]],
        'checkbox',
        ['id' => 1, 'label' => 'Colors']
    ))->toBe([
        ['label' => 'Red', 'price' => 2.0, 'extra' => null],
        ['label' => 'Blue', 'price' => 1000.0],
    ]);
});

it('generates nothing when there are no value transformations', function (): void {
    expect(translateFormsChoiceKeys([['text' => 'Red']], ['text' => 'label'], [], 'checkbox'))
        ->toBe([['label' => 'Red']]);
});

it('renames the keys of a flat array and keeps the untranslated ones', function (): void {
    expect(translateFormsChoiceKeys(['text' => 'Red', 'other' => 1], ['text' => 'label']))
        ->toBe(['label' => 'Red', 'other' => 1])
        ->and(translateFormsChoiceKeys([['a', 'b']], [0 => 'first']))
        ->toBe(['first' => ['a', 'b']])
        ->and(translateFormsChoiceKeys([], ['text' => 'label']))
        ->toBe([]);
});
