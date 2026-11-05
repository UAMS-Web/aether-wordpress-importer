<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms trait paths the earlier passes left unexecuted (#98).
 *
 * A line-coverage run of the `Unit` and `Integration` suites on `main` left
 * a scatter of reachable lines in `FieldtypeMappingTrait` that no earlier
 * slice reached: the defaults of the overridable helpers, attribute tables
 * that are not lists of tables, field references translated against every
 * field, an integer key among the value-translation patterns, boolean and
 * unencodable patterns, the false paths of the nested-attribute pattern, a
 * `based_on_attribute_value` table that maps a value to `null`, a fieldtype
 * table beside an empty `based_on_other_attribute_value` in the last pass, the
 * placeholder skip for name and address fields, and the warnings for
 * unsupported attributes, which only a mapped fieldtype records.
 *
 * The mappings here are written for each case rather than taken from the
 * shipped tables, so each case shows one rule.
 *
 * The guards a mapping class without an attribute or subfield table returns
 * through are not covered: every shipped class defines both tables, and
 * PHPStan rejects a class that leaves one out.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsTraitLeftoversTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FieldtypeMapping as NinjaFormsFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Call a protected static method of the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments
 * @return mixed The method's result
 */
function callFormsLeftover(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(FieldtypeMapping::class, $method)->invoke(null, ...$arguments);
}

/**
 * Map field data through an attribute mapping written for the case.
 *
 * @param  array<int|string, mixed>  $data  The field data
 * @param  array<int|string, mixed>  $mapping  The attribute mapping
 * @param  ?string  $fieldtype  The source fieldtype
 * @param  array<int|string, mixed>  $allFields  Every field of the form
 * @return mixed The mapped attributes
 */
function mapFormsLeftover(array $data, array $mapping, ?string $fieldtype = null, array $allFields = []): mixed
{
    return callFormsLeftover('mapAttributesFromMapping', $data, $mapping, $allFields, null, $fieldtype, null);
}

/**
 * Map field data with context and return the unsupported attributes it warned about.
 *
 * @param  array<int|string, mixed>  $fieldData  The field data
 * @return list<mixed> The warned attribute names
 */
function warnedFormsLeftoverAttributes(array $fieldData): array
{
    ImportWarningManager::clearWarnings();
    FieldtypeMapping::mapFieldAttributesWithContext($fieldData, 'Form', 'Label');
    $warnings = ImportWarningManager::getWarnings('forms', 'unsupported_attributes');
    ImportWarningManager::clearWarnings();

    return array_column($warnings, 'attribute_name');
}

describe('the overridable defaults', function (): void {
    it('maps no conditional logic and names no field for Ninja Forms', function (): void {
        $class = NinjaFormsFieldtypeMapping::class;

        expect(new ReflectionMethod($class, 'mapConditionalLogic')->invoke(null, ['x' => 1], []))->toBe([])
            ->and(new ReflectionMethod($class, 'getFieldNameFromField')->invoke(null, ['label' => 'L']))->toBeNull();
    });

    it('returns the class fieldtype mapping', function (): void {
        expect(FieldtypeMapping::getMapping())->toBe(new ReflectionProperty(FieldtypeMapping::class, 'mapping')->getValue());
    });
});

describe('isFieldtypeMapped()', function (): void {
    it('skips attribute entries that are not tables', function (array $attributes, bool $expected): void {
        expect(FieldtypeMapping::isFieldtypeMapped(['attributes' => $attributes]))->toBe($expected);
    })->with([
        'then only null' => [['x', ['a' => null]], false],
        'then a value' => [['x', ['a' => 1]], true],
    ]);
});

describe('mapAttributesFromMapping()', function (): void {
    it('translates field references against every field', function (mixed $value, mixed $flag, mixed $expected): void {
        $allFields = [['id' => 7, 'label' => 'First Name'], ['id' => 8, 'label' => '']];

        expect(mapFormsLeftover(['n' => $value], ['n' => ['statamic_attribute' => 'a', 'translate_field_references' => $flag]], null, $allFields))
            ->toBe(['a' => $expected]);
    })->with([
        'labelled, unlabelled, unknown and not numeric' => ['see [7] and [8] and [9] and [x]', true, 'see [first_name_7] and [8] and [9] and [x]'],
        'a flag that is not true' => ['see [7]', 1, 'see [7]'],
        'a value that is not a string' => [7, true, 7],
    ]);

    it('skips an integer key among the value-translation patterns', function (): void {
        expect(mapFormsLeftover(['n' => 'abc'], ['n' => ['statamic_attribute' => 'a', 'value_translation' => [5 => 'five', 'a*' => 'A']]]))
            ->toBe(['a' => 'A']);
    });

    it('skips the placeholder of a name or address field whose parts are all empty', function (string $fieldtype, array $placeholder, array $expected): void {
        $mapping = ['placeholder' => ['statamic_attribute' => 'placeholder', 'statamic_other_field_value' => ['default' => ['f' => 1]]]];

        expect(mapFormsLeftover(['placeholder' => $placeholder], $mapping, $fieldtype))->toBe($expected);
    })->with([
        'name' => ['name', ['', ''], []],
        'address' => ['address', ['', ''], []],
        'another fieldtype' => ['text', ['', ''], ['f' => 1]],
        'a name with a part set' => ['name', ['', 'x'], ['f' => 1, 'placeholder' => ['', 'x']]],
    ]);

    it('skips nothing for another attribute of a name field', function (): void {
        $mapping = ['label' => ['statamic_attribute' => 'display', 'statamic_other_field_value' => ['default' => ['f' => 1]]]];

        expect(mapFormsLeftover(['label' => ['', '']], $mapping, 'name'))->toBe(['f' => 1]);
    });

    it('reads a fieldtype table beside an empty table by another attribute', function (string $fieldtype, array $expected): void {
        $translation = ['based_on_other_attribute_value' => [], 'default' => ['#not_set' => 'D'], 'specific' => ['radio' => ['x' => 'P']]];

        expect(mapFormsLeftover([], ['n' => ['statamic_attribute' => 'a', 'value_translation' => $translation]], $fieldtype))->toBe($expected);
    })->with([
        'its fieldtype' => ['radio', []],
        'another fieldtype' => ['select', ['a' => 'D']],
    ]);
});

describe('matchesPattern()', function (): void {
    it('matches a boolean pattern against its string form', function (string $value, bool $pattern, bool $expected): void {
        expect(callFormsLeftover('matchesPattern', $value, $pattern))->toBe($expected);
    })->with([
        'true' => ['true', true, true],
        'false' => ['false', false, true],
        'a mismatch' => ['true', false, false],
    ]);

    it('matches no value that cannot be encoded', function (): void {
        expect(callFormsLeftover('matchesPattern', [INF], '*'))->toBeFalse()
            ->and(callFormsLeftover('matchesPattern', [1], '*'))->toBeTrue();
    });
});

describe('matchesNestedAttributePattern()', function (): void {
    it('matches nothing for a nested attribute it cannot read', function (mixed $value, mixed $nested): void {
        expect(callFormsLeftover('matchesNestedAttributePattern', $value, ['nested_attribute' => $nested]))->toBeFalse();
    })->with([
        'a string nested attribute' => [['k' => 'v'], 'k'],
        'an empty nested attribute' => [['k' => 'v'], ''],
        'a keyed nested attribute over a scalar' => ['v', ['k' => 'v']],
        'a listed nested attribute over a scalar' => ['v', ['v']],
        'a listed nested attribute over a table' => [['k' => 'v'], ['v']],
    ]);
});

describe('processAttributeMapping()', function (): void {
    it('falls back to the default table by attribute value without a fieldtype', function (mixed $default, mixed $expected): void {
        $config = ['statamic_attribute' => ['based_on_attribute_value' => ['specific' => ['radio' => ['v' => 'x']], 'default' => $default]]];

        expect(callFormsLeftover('processAttributeMapping', 'n', 'v', $config, [], null, null))->toBe($expected);
    })->with([
        'a value mapped to null' => [['v' => null], null],
        'a value mapped to a target' => [['v' => 'y'], ['y' => 'v']],
        'a value not in the table' => [['w' => 'y'], []],
    ]);
});

describe('unsupported attribute warnings', function (): void {
    it('warns only for a mapped fieldtype', function (array $fieldData, array $expected): void {
        expect(warnedFormsLeftoverAttributes($fieldData))->toBe($expected);
    })->with([
        'a mapped fieldtype' => [['type' => 'text', 'label' => 'L', 'zzzunknown' => 'q'], ['zzzunknown']],
        'an unmapped fieldtype' => [['type' => 'zzz', 'label' => 'L', 'zzzunknown' => 'q'], []],
        'an empty fieldtype' => [['type' => '', 'label' => 'L', 'zzzunknown' => 'q'], []],
        'no fieldtype' => [['label' => 'L', 'zzzunknown' => 'q'], []],
    ]);
});
