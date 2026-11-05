<?php

declare(strict_types=1);

/**
 * Unit tests for the Formidable Forms conditional logic mapping (#98).
 *
 * `mapConditionalLogic()` turns a field's `show_hide`, `any_all`, `hide_field`,
 * `hide_field_cond` and `hide_opt` lists into one Statamic `if`, `if_any`,
 * `unless` or `unless_any` attribute. `mapActionConditionalLogic()` does the
 * same for an action's numbered conditions, reading `send_stop` when there is
 * no `show_hide`. `convertOperatorToStatamicCondition()` maps each operator, and
 * returns `null` for the three Statamic cannot express. A line-coverage run left
 * parts of all three unexecuted.
 *
 * When `any_all` is absent both methods use `any`, which is Formidable Pro's
 * own field default (`FrmProField`, `FrmProFieldsHelper`).
 *
 * @command  php vendor/bin/pest tests/Unit/FormidableConditionalLogicTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping;

/**
 * The form fields the conditions in these tests refer to.
 *
 * @return list<array<string, mixed>> The fields
 */
function formidableConditionFields(): array
{
    return [
        ['id' => '915', 'type' => 'radio', 'name' => 'Color'],
        ['id' => 916, 'type' => 'text', 'name' => 'Size'],
        ['id' => '917', 'type' => 'text', 'name' => ''],
        ['id' => '918', 'type' => 'text'],
    ];
}

it('maps field conditional logic', function (
    array $fieldOptions,
    array $expected
): void {
    expect(FieldtypeMapping::mapConditionalLogic($fieldOptions, formidableConditionFields()))->toBe($expected);
})->with([
    'show when all' => [['show_hide' => 'show', 'any_all' => 'all', 'hide_field' => ['915'], 'hide_opt' => ['red']], ['if' => ['color_915' => 'equals red']]],
    'show when any' => [['show_hide' => 'show', 'any_all' => 'any', 'hide_field' => ['915'], 'hide_opt' => ['red']], ['if_any' => ['color_915' => 'equals red']]],
    'hide when all' => [['show_hide' => 'hide', 'any_all' => 'all', 'hide_field' => ['915'], 'hide_opt' => ['red']], ['unless' => ['color_915' => 'equals red']]],
    'hide when any' => [['show_hide' => 'hide', 'any_all' => 'any', 'hide_field' => ['915'], 'hide_opt' => ['red']], ['unless_any' => ['color_915' => 'equals red']]],
    'no action or logic type' => [['hide_field' => ['915'], 'hide_opt' => ['red']], ['if_any' => ['color_915' => 'equals red']]],
    'an action and logic type that are not strings' => [['show_hide' => 1, 'any_all' => 1, 'hide_field' => ['915'], 'hide_opt' => ['red']], ['if_any' => ['color_915' => 'equals red']]],
    'an unknown action' => [['show_hide' => 'maybe', 'hide_field' => ['915'], 'hide_opt' => ['red']], []],
    'an unknown logic type, as all' => [['show_hide' => 'show', 'any_all' => 'most', 'hide_field' => ['915'], 'hide_opt' => ['red']], ['if' => ['color_915' => 'equals red']]],
    'an operator per condition' => [['show_hide' => 'show', 'any_all' => 'all', 'hide_field' => ['915', '916'], 'hide_field_cond' => ['!=', '>'], 'hide_opt' => ['red', '3']], ['if' => ['color_915' => 'not red', 'size_916' => '> 3']]],
    'an integer field id' => [['any_all' => 'all', 'hide_field' => [916], 'hide_opt' => ['3']], ['if' => ['size_916' => 'equals 3']]],
    'no value' => [['any_all' => 'all', 'hide_field' => ['915']], ['if' => ['color_915' => 'equals empty']]],
    'a value that is not scalar' => [['any_all' => 'all', 'hide_field' => ['915'], 'hide_opt' => [['red']]], ['if' => ['color_915' => 'equals empty']]],
    'an operator that is not scalar' => [['any_all' => 'all', 'hide_field' => ['915'], 'hide_field_cond' => [['!=']], 'hide_opt' => ['red']], ['if' => ['color_915' => 'equals red']]],
    'a numeric value' => [['any_all' => 'all', 'hide_field' => ['915'], 'hide_opt' => [5]], ['if' => ['color_915' => 'equals 5']]],
    'the last condition on a field' => [['any_all' => 'all', 'hide_field' => ['915', '915'], 'hide_opt' => ['red', 'blue']], ['if' => ['color_915' => 'equals blue']]],
    'skipped conditions beside a kept one' => [['any_all' => 'all', 'hide_field' => [['915'], '', '999', '917', '918', '915'], 'hide_field_cond' => [5 => 'LIKE'], 'hide_opt' => [5 => 're']], ['if' => ['color_915' => 'contains re']]],
    'an unsupported operator' => [['any_all' => 'all', 'hide_field' => ['915'], 'hide_field_cond' => ['not LIKE'], 'hide_opt' => ['red']], []],
    'an unknown field' => [['hide_field' => ['999'], 'hide_opt' => ['red']], []],
    'no field ids' => [['show_hide' => 'show', 'hide_field' => []], []],
    'field ids that are not a list' => [['show_hide' => 'show', 'hide_field' => '915'], []],
    'operators and values that are not lists' => [['any_all' => 'all', 'hide_field' => ['915'], 'hide_field_cond' => '!=', 'hide_opt' => 'red'], ['if' => ['color_915' => 'equals empty']]],
]);

it('maps action conditional logic', function (
    array $conditions,
    array $expected
): void {
    expect(FieldtypeMapping::mapActionConditionalLogic($conditions, formidableConditionFields()))->toBe($expected);
})->with([
    'send when all' => [['send_stop' => 'send', 'any_all' => 'all', 0 => ['hide_field' => '915', 'hide_field_cond' => '==', 'hide_opt' => 'red']], ['if' => ['color_915' => 'equals red']]],
    'stop when all' => [['send_stop' => 'stop', 'any_all' => 'all', 0 => ['hide_field' => '915', 'hide_opt' => 'red']], ['unless' => ['color_915' => 'equals red']]],
    'stop when any' => [['send_stop' => 'stop', 'any_all' => 'any', 0 => ['hide_field' => '915', 'hide_opt' => 'red']], ['unless_any' => ['color_915' => 'equals red']]],
    'send when any, by default' => [['send_stop' => 'send', 0 => ['hide_field' => '915', 'hide_opt' => 'red']], ['if_any' => ['color_915' => 'equals red']]],
    'no directive' => [[0 => ['hide_field' => '915', 'hide_opt' => 'red']], ['if_any' => ['color_915' => 'equals red']]],
    'a directive that is not a string' => [['send_stop' => 1, 'any_all' => 1, 0 => ['hide_field' => '915', 'hide_opt' => 'red']], ['if_any' => ['color_915' => 'equals red']]],
    'show_hide before send_stop' => [['show_hide' => 'show', 'send_stop' => 'stop', 'any_all' => 'all', 0 => ['hide_field' => '915', 'hide_opt' => 'red']], ['if' => ['color_915' => 'equals red']]],
    'an unknown show_hide' => [['show_hide' => 'maybe', 0 => ['hide_field' => '915', 'hide_opt' => 'red']], []],
    'string-numbered conditions' => [['any_all' => 'all', '1' => ['hide_field' => '915', 'hide_field_cond' => '<=', 'hide_opt' => '9'], '2' => ['hide_field' => 916, 'hide_field_cond' => '>=', 'hide_opt' => 1]], ['if' => ['color_915' => '<= 9', 'size_916' => '>= 1']]],
    'skipped conditions beside a kept one' => [['any_all' => 'all', 'rule' => ['hide_field' => '916', 'hide_opt' => 'x'], 0 => 'junk', 1 => ['hide_opt' => 'x'], 2 => ['hide_field' => ''], 3 => ['hide_field' => ['915']], 4 => ['hide_field' => '999'], 5 => ['hide_field' => '917'], 6 => ['hide_field' => '915', 'hide_field_cond' => 'LIKE%'], 7 => ['hide_field' => '915', 'hide_field_cond' => '<', 'hide_opt' => '4']], ['if' => ['color_915' => '< 4']]],
    'an operator and value that are not scalar' => [['any_all' => 'all', 0 => ['hide_field' => '915', 'hide_field_cond' => ['!='], 'hide_opt' => ['red']]], ['if' => ['color_915' => 'equals empty']]],
    'no conditions' => [['send_stop' => 'send', 'any_all' => 'all'], []],
    'no conditions, and an unknown show_hide' => [['show_hide' => 'maybe'], []],
]);

it('converts each operator to a Statamic condition', function (
    string $operator,
    string $value,
    ?string $expected
): void {
    expect(new ReflectionMethod(FieldtypeMapping::class, 'convertOperatorToStatamicCondition')->invoke(null, $operator, $value))->toBe($expected);
})->with([
    'equals' => ['==', 'x', 'equals x'],
    'equals empty' => ['==', '', 'equals empty'],
    'not' => ['!=', 'x', 'not x'],
    'not empty' => ['!=', '', 'not empty'],
    'greater than' => ['>', '1', '> 1'],
    'at least' => ['>=', '1', '>= 1'],
    'less than' => ['<', '1', '< 1'],
    'at most' => ['<=', '1', '<= 1'],
    'contains' => ['LIKE', 'x', 'contains x'],
    'does not contain' => ['not LIKE', 'x', null],
    'starts with' => ['LIKE%', 'x', null],
    'ends with' => ['%LIKE', 'x', null],
    'an unknown operator' => ['~', 'x', null],
]);

it('exposes its attribute and options mappings', function (): void {
    $class = new ReflectionClass(FieldtypeMapping::class);

    expect(FieldtypeMapping::getFieldAttributeMapping())->toBe($class->getStaticPropertyValue('fieldAttributeMapping'))
        ->and(FieldtypeMapping::getFieldOptionsMapping())->toBe($class->getStaticPropertyValue('fieldAttributeOptionsMapping'))
        ->and(FieldtypeMapping::getFieldOptionsMapping())->not->toBe(FieldtypeMapping::getFieldAttributeMapping())
        ->and(FieldtypeMapping::getFieldAttributeMapping())->not->toBeEmpty();
});
