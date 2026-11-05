<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms fieldtype resolution (#98).
 *
 * `resolveFieldtypeFromMapping()` turns a source field, or its type alone, into
 * a Statamic fieldtype: a scalar mapping directly, then the
 * other-attribute table (`default` with an optional per-type `specific`
 * replacement, or the legacy form), then the `attributes` table, then a
 * `linked_field`, then `default`. `false` suppresses the field and `null`
 * leaves it unmapped. Each plugin's `getStatamicFieldtype()` calls it with the
 * shipped mapping. `resolveStatamicFieldtypeClass()` turns the result into the
 * host app's `App\Fieldtypes` class when one exists, and
 * `addUnmappedFieldtypeWarning()` records a field it could not map. No
 * standalone test named the first two or the last before this file.
 *
 * In the `attributes` table, `#not_empty` treats an attribute present as `null`
 * or absent as not empty, where the other-attribute table does not. No shipped
 * fieldtype mapping uses `#not_empty`, so this is characterized rather than
 * changed.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldtypeResolutionTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping as FormidableFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FieldtypeMapping as NinjaFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Resolve a field against the synthetic mapping used by these tests.
 *
 * @param  string|array<int|string, mixed>  $fieldOrType  The field, or its type
 * @return string|null|false The resolved fieldtype
 */
function resolveFormsFieldtype(
    string|array $fieldOrType
): string|null|false {
    $mapping = [
        'plain' => 'text',
        'off' => false,
        'unmapped' => null,
        'number' => 5,
        'other' => ['default' => 'D', 'based_on_other_attribute_value' => ['default' => ['t' => ['x' => 'X', '#not_set' => 'NS', '#not_empty' => 'NE', '' => 'E']]]],
        'typed' => ['default' => 'D', 'based_on_other_attribute_value' => ['default' => ['t' => ['x' => 'X']], 'specific' => ['typed' => ['u' => ['y' => 'SY']]]]],
        'legacy' => ['default' => 'D', 'based_on_other_attribute_value' => ['t' => ['x' => 'X', '#not_set' => 'NS', '#not_empty' => 'NE', '' => 'E']]],
        'attrs' => ['default' => 'D', 'attributes' => ['a' => ['x' => 'AX', '#not_set' => 'ANS', '#empty' => 'AE', '#not_empty' => 'ANE']]],
        'filled' => ['default' => 'D', 'attributes' => ['a' => ['#not_empty' => 'ANE']]],
        'linked' => ['default' => 'D', 'linked_field' => ['default' => 'common.thing']],
        'suppressed' => ['default' => false],
    ];

    $resolved = new ReflectionMethod(
        FieldtypeMapping::class,
        'resolveFieldtypeFromMapping'
    )->invoke(
        null,
        $mapping,
        $fieldOrType
    );

    return \is_string($resolved) || $resolved === false ? $resolved : null;
}

it('resolves a fieldtype from the mapping in order', function (
    string|array $fieldOrType,
    string|null|false $expected
): void {
    expect(resolveFormsFieldtype($fieldOrType))->toBe($expected);
})->with([
    'a string mapping' => ['plain', 'text'],
    'a string mapping, by field' => [['type' => 'plain'], 'text'],
    'a suppressed mapping' => ['off', false],
    'an unmapped type' => ['unmapped', null],
    'a mapping that is not a string' => ['number', null],
    'a type the mapping lacks' => ['missing', null],
    'a field with no type' => [['label' => 'x'], null],
    'a field whose type is not a string' => [['type' => 5], null],
    'an empty type' => ['', null],
    'an other-attribute value' => [['type' => 'other', 't' => 'x'], 'X'],
    'an other-attribute value from field_options' => [['type' => 'other', 'field_options' => '{"t":"x"}'], 'X'],
    'an unset other attribute, against #not_set' => [['type' => 'other'], 'NS'],
    'an unmatched other attribute, against #not_empty' => [['type' => 'other', 't' => 'q'], 'NE'],
    'an empty other attribute' => [['type' => 'other', 't' => ''], 'E'],
    'a null other attribute, then the default' => [['type' => 'other', 't' => null], 'D'],
    'a per-type table, replacing the default table' => [['type' => 'typed', 'u' => 'y', 't' => 'x'], 'SY'],
    'a per-type table with no match, then the default' => [['type' => 'typed', 't' => 'x'], 'D'],
    'a legacy other-attribute value' => [['type' => 'legacy', 't' => 'x'], 'X'],
    'a legacy unset attribute, against #not_set' => [['type' => 'legacy'], 'NS'],
    'a legacy unmatched attribute, against #not_empty' => [['type' => 'legacy', 't' => 'q'], 'NE'],
    'a legacy empty attribute' => [['type' => 'legacy', 't' => ''], 'E'],
    'a legacy null attribute, then the default' => [['type' => 'legacy', 't' => null], 'D'],
    'an attribute value' => [['type' => 'attrs', 'a' => 'x'], 'AX'],
    'an unset attribute, against #not_set' => [['type' => 'attrs'], 'ANS'],
    'an empty attribute, against #empty' => [['type' => 'attrs', 'a' => ''], 'AE'],
    'an unmatched attribute, against #not_empty' => [['type' => 'attrs', 'a' => 'q'], 'ANE'],
    'a null attribute, against #not_empty' => [['type' => 'attrs', 'a' => null], 'ANE'],
    'an unset attribute, against #not_empty alone' => [['type' => 'filled'], 'ANE'],
    'an empty attribute, against #not_empty alone' => [['type' => 'filled', 'a' => ''], 'D'],
    'an empty list, against #not_empty alone' => [['type' => 'filled', 'a' => []], 'D'],
    'a linked field' => [['type' => 'linked'], 'common.thing'],
    'a linked field, by type' => ['linked', 'common.thing'],
    'a suppressed default' => ['suppressed', false],
]);

it('resolves the shipped fieldtype mappings for each plugin', function (
    string $class,
    string|array $field,
    string|null|false $expected
): void {
    $resolved = match ($class) {
        FormidableFieldtypeMapping::class => FormidableFieldtypeMapping::getStatamicFieldtype($field),
        NinjaFieldtypeMapping::class => NinjaFieldtypeMapping::getStatamicFieldtype($field),
        default => FieldtypeMapping::getStatamicFieldtype($field),
    };

    expect($resolved)->toBe($expected);
})->with([
    'Gravity Forms text' => [FieldtypeMapping::class, 'text', 'text'],
    'Gravity Forms captcha, suppressed quietly' => [FieldtypeMapping::class, 'captcha', null],
    'a Gravity Forms currency number' => [FieldtypeMapping::class, ['type' => 'number', 'numberFormat' => 'currency'], 'currency'],
    'a Gravity Forms decimal number' => [FieldtypeMapping::class, ['type' => 'number', 'numberFormat' => 'decimal_dot'], 'float'],
    'a Gravity Forms list with columns' => [FieldtypeMapping::class, ['type' => 'list', 'enableColumns' => true], 'grid'],
    'a Gravity Forms list without columns' => [FieldtypeMapping::class, ['type' => 'list', 'enableColumns' => false], 'list'],
    'a Gravity Forms list, columns unset' => [FieldtypeMapping::class, ['type' => 'list'], 'list'],
    'a Gravity Forms radio multi-choice' => [FieldtypeMapping::class, ['type' => 'multi_choice', 'inputType' => 'radio'], 'radio'],
    'a Gravity Forms multi-choice with no input type' => [FieldtypeMapping::class, ['type' => 'multi_choice'], null],
    'Gravity Forms post tags, input type unset' => [FieldtypeMapping::class, ['type' => 'post_tags'], 'list'],
    'Gravity Forms post tags, input type empty' => [FieldtypeMapping::class, ['type' => 'post_tags', 'inputType' => ''], 'list'],
    'Gravity Forms post tags as a select' => [FieldtypeMapping::class, ['type' => 'post_tags', 'inputType' => 'select'], 'select'],
    'Gravity Forms post tags with the rich text editor' => [FieldtypeMapping::class, ['type' => 'post_tags', 'useRichTextEditor' => true], 'bard'],
    'a Gravity Forms rich textarea, linked' => [FieldtypeMapping::class, ['type' => 'textarea', 'useRichTextEditor' => true], 'uamsweb_common.bard_simple'],
    'a Gravity Forms plain textarea' => [FieldtypeMapping::class, ['type' => 'textarea'], 'textarea'],
    'a Formidable section' => [FormidableFieldtypeMapping::class, ['type' => 'divider', 'field_options' => '{"repeat":0}'], 'subsection'],
    'a Formidable repeater' => [FormidableFieldtypeMapping::class, ['type' => 'divider', 'field_options' => '{"repeat":"1"}'], 'replicator'],
    'a Formidable divider with no repeat option' => [FormidableFieldtypeMapping::class, ['type' => 'divider', 'field_options' => '{}'], 'replicator'],
    'a Ninja Forms textbox' => [NinjaFieldtypeMapping::class, 'textbox', 'text'],
]);

it('resolves no Statamic fieldtype class that the package does not have', function (
    string $fieldType,
    string $pluginName
): void {
    expect(FieldtypeMapping::resolveStatamicFieldtypeClass($fieldType, $pluginName))->toBeNull();
})->with([
    'a Gravity Forms address' => ['address', 'gravity-forms'],
    'a Formidable address' => ['address', 'formidable-forms'],
    'a Gravity Forms field with no fieldtype' => ['captcha', 'gravity-forms'],
    'a Gravity Forms field type it does not know' => ['nope', 'gravity-forms'],
    'a plugin it does not know' => ['text', 'ninja-forms'],
]);

it('resolves the host fieldtype class when one exists', function (): void {
    $class = 'App\\Fieldtypes\\Username';
    if (! \class_exists($class)) {
        \class_alias((new class {})::class, $class);
    }

    expect(FieldtypeMapping::resolveStatamicFieldtypeClass('username', 'gravity-forms'))->toBe($class)
        ->and(FieldtypeMapping::resolveStatamicFieldtypeClass(['type' => 'username'], 'gravity-forms'))->toBe($class);
});

it('records an unmapped fieldtype with its form and field', function (): void {
    ImportWarningManager::clearWarnings();

    FieldtypeMapping::addUnmappedFieldtypeWarning('mystery', 'Intake', 'Choice', '7');
    FieldtypeMapping::addUnmappedFieldtypeWarning('other');

    expect(ImportWarningManager::getWarnings('forms', 'unmapped_fieldtypes'))->toBe([
        ['form_title' => 'Intake', 'field_label' => 'Choice', 'form_id' => '7', 'field_type' => 'mystery'],
        ['form_title' => null, 'field_label' => null, 'form_id' => null, 'field_type' => 'other'],
    ]);

    ImportWarningManager::clearWarnings();
});
