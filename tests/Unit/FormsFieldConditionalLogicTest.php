<?php

declare(strict_types=1);

/**
 * Unit tests for Gravity Forms field conditional logic and the subfield
 * placeholder skip (#98).
 *
 * A field's `conditionalLogic` becomes one of Statamic's `if`, `if_any`,
 * `unless` or `unless_any` attributes: the action type picks show or hide, the
 * logic type picks all or any (`getStatamicConditionalAttribute()`), and each
 * rule becomes a condition on the handle of the field it names
 * (`convertOperatorToStatamicCondition()`). Separately,
 * `shouldSkipArrayFieldOption()` drops an all-empty `placeholder` object on a
 * name or address field, which configures subfields rather than the field. No
 * standalone test named any of these methods before this file.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldConditionalLogicTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * Invoke a protected static method on the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function invokeFormsConditionalMethod(
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
 * The form fields a conditional rule can refer to.
 *
 * @return list<array<string, int|string>> The fields
 */
function conditionalSourceFields(): array
{
    return [
        ['id' => 1, 'type' => 'text', 'label' => 'First Name'],
        ['id' => 2, 'type' => 'select', 'label' => 'Visit Type'],
        ['id' => 3, 'type' => 'text', 'label' => ''],
    ];
}

/**
 * Map a text field carrying the given conditional logic, keeping only the
 * conditional attributes.
 *
 * @param  mixed  $conditionalLogic  The field's `conditionalLogic` value
 * @return array<int|string, mixed> The conditional attributes the mapping set
 */
function mapConditionalField(
    mixed $conditionalLogic
): array {
    $field = [
        'id' => 9,
        'type' => 'text',
        'label' => 'Notes',
        'conditionalLogic' => $conditionalLogic,
    ];
    $allFields = [...conditionalSourceFields(), $field];

    return \array_intersect_key(
        FieldtypeMapping::mapFieldAttributes($field, $allFields, 'text'),
        \array_flip(['if', 'if_any', 'unless', 'unless_any', 'conditionalLogic'])
    );
}

it('maps a field conditional to the attribute for its action and logic type', function (
    mixed $actionType,
    mixed $logicType,
    string $expected
): void {
    $logic = ['enabled' => true, 'rules' => [['fieldId' => '1', 'operator' => 'is', 'value' => 'Ann']]];
    if ($actionType !== null) {
        $logic['actionType'] = $actionType;
    }

    if ($logicType !== null) {
        $logic['logicType'] = $logicType;
    }

    expect(mapConditionalField($logic))->toBe([$expected => ['first_name_1' => 'equals Ann']]);
})->with([
    'show all' => ['show', 'all', 'if'],
    'show any' => ['show', 'any', 'if_any'],
    'hide all' => ['hide', 'all', 'unless'],
    'hide any' => ['hide', 'any', 'unless_any'],
    'no action or logic type' => [null, null, 'if'],
    'types that are not strings' => [['show'], 5, 'if'],
]);

it('reads conditional logic given as JSON', function (): void {
    $json = \json_encode(['actionType' => 'hide', 'logicType' => 'any', 'rules' => [['fieldId' => 2, 'operator' => 'contains', 'value' => 'tele']]]);

    expect(mapConditionalField($json))->toBe(['unless_any' => ['visit_type_2' => 'contains tele']]);
});

it('converts each rule to a condition on the field it names', function (): void {
    $logic = [
        'actionType' => 'show',
        'logicType' => 'all',
        // Each skipped rule comes before a kept one, so a skip that ended the loop
        // would lose the rules after it
        'rules' => [
            'not a rule',
            ['fieldId' => '404', 'operator' => 'is', 'value' => 'x'],
            ['fieldId' => '3', 'operator' => 'is', 'value' => 'x'],
            ['fieldId' => '1', 'operator' => 'starts_with', 'value' => 'x'],
            ['fieldId' => '1', 'operator' => 'is', 'value' => ''],
            ['fieldId' => '2', 'operator' => 'isnot', 'value' => 'Clinic'],
        ],
    ];

    expect(mapConditionalField($logic))->toBe(['if' => [
        'first_name_1' => 'equals empty',
        'visit_type_2' => 'not Clinic',
    ]]);
});

it('reads a rule value that is missing, not scalar or a number', function (
    array $rule,
    string $expected
): void {
    $logic = ['actionType' => 'show', 'logicType' => 'all', 'rules' => [['fieldId' => '1', 'operator' => 'is', ...$rule]]];

    expect(mapConditionalField($logic))->toBe(['if' => ['first_name_1' => $expected]]);
})->with([
    'missing' => [[], 'equals empty'],
    'not scalar' => [['value' => ['Ann']], 'equals empty'],
    'a number' => [['value' => 5], 'equals 5'],
]);

it('maps no conditional for an unknown action type, called directly', function (): void {
    // Through a field the attribute filter hides what this returns, so call the
    // mapping itself: an unknown action has no attribute, whatever its rules.
    expect(FieldtypeMapping::mapConditionalLogic(['actionType' => 'toggle', 'rules' => [['fieldId' => '1', 'operator' => 'is', 'value' => 'a']]], conditionalSourceFields()))->toBe([]);
});

it('raises no PHP warning for rules that are not a list', function (): void {
    $warnings = [];
    \set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $mapped = FieldtypeMapping::mapConditionalLogic(['actionType' => 'show', 'rules' => 'x'], conditionalSourceFields());
    } finally {
        \restore_error_handler();
    }

    expect($mapped)->toBe([])
        ->and($warnings)->toBe([]);
});

it('maps no conditional when there is nothing to apply', function (mixed $conditionalLogic): void {
    expect(mapConditionalField($conditionalLogic))->toBe([]);
})->with([
    'disabled' => [['enabled' => false, 'actionType' => 'show', 'rules' => [['fieldId' => '1', 'operator' => 'is', 'value' => 'a']]]],
    'no rules' => [['actionType' => 'show', 'rules' => []]],
    'rules that are not a list' => [['actionType' => 'show', 'rules' => 'x']],
    'an unknown action type' => [['actionType' => 'toggle', 'rules' => [['fieldId' => '1', 'operator' => 'is', 'value' => 'a']]]],
    'only unresolvable rules' => [['actionType' => 'show', 'rules' => [['fieldId' => '404', 'operator' => 'is', 'value' => 'a']]]],
    'invalid JSON' => ['{not json'],
    'an empty object' => [[]],
    'null' => [null],
]);

it('converts each Gravity Forms operator to a Statamic condition', function (
    string $operator,
    string $value,
    ?string $expected
): void {
    expect(invokeFormsConditionalMethod('convertOperatorToStatamicCondition', $operator, $value))->toBe($expected);
})->with([
    'is' => ['is', 'a', 'equals a'],
    'is empty' => ['is', '', 'equals empty'],
    'is not' => ['isnot', 'a', 'not a'],
    'is not empty' => ['isnot', '', 'not empty'],
    'greater than' => ['>', '3', '> 3'],
    'less than' => ['<', '3', '< 3'],
    'contains' => ['contains', 'a', 'contains a'],
    'an unsupported operator' => ['starts_with', 'a', null],
]);

it('skips an all-empty placeholder object on a name or address field only', function (
    int|string|null $attrName,
    mixed $attrValue,
    ?string $fieldtype,
    bool $expected
): void {
    expect(invokeFormsConditionalMethod('shouldSkipArrayFieldOption', $attrName, $attrValue, $fieldtype))->toBe($expected);
})->with([
    'name placeholders, all empty' => ['placeholder', ['first' => '', 'last' => ''], 'name', true],
    'address placeholders, all empty' => ['placeholder', ['street' => ''], 'address', true],
    'an empty placeholder list' => ['placeholder', [], 'name', true],
    'a placeholder with text' => ['placeholder', ['first' => 'Given', 'last' => ''], 'name', false],
    'a placeholder holding null' => ['placeholder', ['first' => null], 'name', false],
    'another attribute' => ['description', ['first' => ''], 'name', false],
    'another fieldtype' => ['placeholder', ['first' => ''], 'text', false],
    'no fieldtype' => ['placeholder', ['first' => ''], null, false],
    'a string placeholder' => ['placeholder', '', 'name', false],
]);
