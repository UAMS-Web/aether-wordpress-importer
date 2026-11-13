<?php

declare(strict_types=1);

/**
 * Unit tests for the edges of the shared Forms mapping helpers (#537).
 *
 * A Linux mutation run of `FieldtypeMappingTrait` found mutants that the
 * existing tests let through in the value transformation, value generation,
 * fieldtype resolution, warning, placeholder, subfield and nested attribute
 * helpers: an empty value read as set, a guard whose removal changes the
 * result only for an input no test gave, and the like. Each case here names
 * the input that tells the original from the mutant. The methods are called on
 * the Gravity Forms mapping class, which uses the trait, or on a subclass of it
 * whose mapping carries an empty-string key.
 *
 * Mutants no input can kill are annotated with `@pest-mutate-ignore` in the
 * source, each with its reason beside it.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsFieldtypeMappingEdgeCasesHelpersTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * The Gravity Forms mapping with an empty-string fieldtype and subfield labels and handles.
 *
 * No shipped mapping has an empty key, so this subclass supplies one to reach the
 * guards that refuse an empty type, label or handle even when a table names it.
 */
final class FmhEmptyKeyMapping extends FieldtypeMapping
{
    /**
     * The Gravity Forms fieldtype mapping, plus an entry for the empty fieldtype.
     *
     * @return array<int|string, mixed> The mapping array for fieldtypes
     */
    public static function getMapping(): array
    {
        return ['' => ['default' => 'text']] + parent::getMapping();
    }

    /**
     * A small address subfield mapping with an empty label and an empty handle.
     *
     * @return array<int|string, mixed> The subfield mapping array
     */
    public static function getSubfieldMapping(): array
    {
        return ['fieldtypes' => ['address' => ['subfields' => [
            'City' => ['handle' => 'city', 'attributes' => [
                'customLabel' => ['statamic_attribute' => '[#subfield]_label'],
                'parentNote' => ['statamic_attribute' => '[#subfield]_note'],
            ]],
            '' => ['handle' => 'blank', 'attributes' => [
                'customLabel' => ['statamic_attribute' => '[#subfield]_label'],
            ]],
            'Nameless' => ['handle' => '', 'attributes' => [
                'customLabel' => ['statamic_attribute' => '[#subfield]_label'],
            ]],
        ]]]];
    }
}

/**
 * Call a protected static method of the trait through the Gravity Forms mapping class.
 *
 * @param  string  $method  The method name
 * @param  mixed  ...$arguments  The arguments to pass
 * @return mixed The method's return value
 */
function fmhCall(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(FieldtypeMapping::class, $method)->invoke(null, ...$arguments);
}

/**
 * Resolve the transformation for a key through `resolveValueTransformations()`.
 *
 * @param  array<int|string, mixed>  $valueTransformations  The value transformations configuration
 * @param  array<int|string, mixed>  $data  The field data
 * @param  string  $key  The key to get transformations for
 * @param  ?string  $fieldtype  The current fieldtype
 * @return mixed The resolved transformation configuration
 */
function fmhTransformation(array $valueTransformations, array $data, string $key, ?string $fieldtype = null): mixed
{
    return fmhCall('resolveValueTransformations', $valueTransformations, $data, $key, $fieldtype);
}

/**
 * Map a field with warning context and return the unsupported attribute warnings it adds.
 *
 * @param  class-string<FieldtypeMapping>  $class  The mapping class to call
 * @param  array<int|string, mixed>  $fieldData  The field data
 * @param  ?string  $formTitle  The form title for warning context
 * @param  ?string  $fieldLabel  The field label for warning context
 * @return array<int|string, mixed> The warnings added
 */
function fmhUnsupportedWarnings(string $class, array $fieldData, ?string $formTitle = 'Form', ?string $fieldLabel = 'Label'): array
{
    ImportWarningManager::clearWarnings();
    $class::mapFieldAttributesWithContext($fieldData, $formTitle, $fieldLabel, 3);
    $warnings = ImportWarningManager::getWarnings('forms', 'unsupported_attributes');
    ImportWarningManager::clearWarnings();

    return $warnings;
}

/**
 * Resolve a Statamic fieldtype class and record which `App\Fieldtypes` classes it looked up.
 *
 * @param  string  $fieldType  The source field type
 * @param  string  $pluginName  The plugin name
 * @return array{0: ?string, 1: list<string>} The resolved class and the classes looked up
 */
function fmhResolveFieldtypeClass(string $fieldType, string $pluginName): array
{
    $lookedUp = [];
    $recorder = function (string $class) use (&$lookedUp): void {
        if (str_starts_with($class, 'App\\Fieldtypes\\')) {
            $lookedUp[] = $class;
        }
    };

    // Record every autoload request, ahead of Composer's loader
    spl_autoload_register($recorder, true, true);
    try {
        $class = FieldtypeMapping::resolveStatamicFieldtypeClass($fieldType, $pluginName);
    } finally {
        spl_autoload_unregister($recorder);
    }

    return [$class, $lookedUp];
}

describe('resolveValueTransformations()', function (): void {
    it('ignores a flat not-empty transformation while the attribute is empty', function (mixed $empty): void {
        expect(fmhTransformation(['based_on_attribute_value' => ['#not_empty' => ['k' => 'X']]], ['a' => $empty], 'k'))
            ->toBeNull();
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);

    it('takes the whole table for the transformation type while the attribute is empty', function (mixed $empty): void {
        // The not-empty table does not apply, so the legacy key lookup returns the
        // `based_on_attribute_value` table as it stands.
        expect(fmhTransformation(['based_on_attribute_value' => ['#not_empty' => ['t' => 'X']]], ['a' => $empty], 'based_on_attribute_value'))
            ->toBe(['#not_empty' => ['t' => 'X']]);
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);

    it('takes the empty table for the transformation type while the attribute is empty', function (mixed $empty): void {
        expect(fmhTransformation(['based_on_attribute_value' => ['#empty' => ['t' => 'E']]], ['a' => $empty], 'based_on_attribute_value'))
            ->toBe(['t' => 'E']);
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);

    it('reads no specific table that is not there, for a named fieldtype', function (): void {
        // Reading the missing `specific` entry would raise a warning.
        expect(fmhTransformation(['based_on_attribute_value' => []], [], 'k', 'text'))->toBeNull()
            ->and(fmhTransformation([], [], 'k', 'text'))->toBeNull();
    });

    it('reads no specific table for an empty fieldtype, even one keyed by the empty string', function (): void {
        expect(fmhTransformation(
            ['based_on_attribute_value' => ['specific' => ['' => ['a' => ['#empty' => ['k' => 'S']]]]]],
            [],
            'k',
            ''
        ))->toBeNull();
    });

    it('ignores a specific not-empty transformation while the attribute is empty', function (mixed $empty): void {
        expect(fmhTransformation(
            ['based_on_attribute_value' => ['specific' => ['text' => ['a' => ['#not_empty' => ['k' => 'N']]]]]],
            ['a' => $empty],
            'k',
            'text'
        ))->toBeNull();
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);

    it('ignores a default not-empty transformation while the attribute is empty', function (mixed $empty): void {
        expect(fmhTransformation(['based_on_attribute_value' => ['default' => ['#not_empty' => ['k' => 'D']]]], ['a' => $empty], 'k'))
            ->toBeNull();
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);

    it('ignores a default not-empty transformation by attribute name while the attribute is empty', function (mixed $empty): void {
        expect(fmhTransformation(['based_on_attribute_value' => ['default' => ['a' => ['#not_empty' => ['k' => 'T']]]]], ['a' => $empty], 'k'))
            ->toBeNull();
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);

    it('takes a default empty transformation by attribute name while the attribute is empty', function (mixed $empty): void {
        expect(fmhTransformation(['based_on_attribute_value' => ['default' => ['a' => ['#empty' => ['k' => 'E']]]]], ['a' => $empty], 'k'))
            ->toBe(['k' => 'E']);
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);
});

describe('applyValueTransformation()', function (): void {
    it('numbers a complex array by the count given, or by one without a count', function (): void {
        $config = ['as_complex_array' => ['n' => '[#count]']];

        expect(fmhCall('applyValueTransformation', 'v', $config, [], 3))->toBe(['n' => '3'])
            ->and(fmhCall('applyValueTransformation', 'v', $config, null, null))->toBe(['n' => '1']);
    });

    it('builds a field handle with no id suffix for a field without an id', function (): void {
        expect(fmhCall('applyValueTransformation', 'v', ['as_complex_array' => ['n' => '[#field_handle]']], ['label' => 'My Field'], 1))
            ->toBe(['n' => 'my_field_']);
    });
});

describe('generateValue()', function (): void {
    it('skips an invalid rule and keeps the rules after it', function (): void {
        expect(fmhCall('generateValue', ['not a rule', ['value' => 'a', 'other' => 'b'], ['value' => 'c']], [], 1))
            ->toBe('c');
    });

    it('builds a field handle with no id suffix for a field without an id', function (): void {
        expect(fmhCall('generateValue', [['value' => '#field_handle']], ['label' => 'My Field'], 1))
            ->toBe('my_field_');
    });
});

describe('getNestedAttributeValue()', function (): void {
    it('reads null for a dotted path whose key is missing', function (): void {
        // Reading the missing key would raise a warning.
        expect(fmhCall('getNestedAttributeValue', ['a' => ['c' => 1]], 'a.b'))->toBeNull()
            ->and(fmhCall('getNestedAttributeValue', ['a' => ['c' => 1]], 'a.c'))->toBe(1);
    });
});

describe('shouldAlertUnsupportedAttribute()', function (): void {
    it('reports a set value and no empty one', function (mixed $value, bool $expected): void {
        expect(fmhCall('shouldAlertUnsupportedAttribute', [], $value))->toBe($expected);
    })->with([
        'null' => [null, false],
        'an empty string' => ['', false],
        'an empty array' => [[], false],
        'false' => [false, true],
        'a string' => ['x', true],
    ]);
});

describe('mapFieldAttributesWithContext()', function (): void {
    it('adds no warning without both the form title and the field label', function (?string $formTitle, ?string $fieldLabel): void {
        expect(fmhUnsupportedWarnings(FieldtypeMapping::class, ['type' => 'text', 'zzz' => 'v'], $formTitle, $fieldLabel))
            ->toBe([]);
    })->with([
        'no form title' => [null, 'Label'],
        'no field label' => ['Form', null],
        'neither' => [null, null],
    ]);

    it('adds no warning for an empty fieldtype, even one the mapping names', function (): void {
        expect(fmhUnsupportedWarnings(FmhEmptyKeyMapping::class, ['type' => '', 'zzz' => 'v']))->toBe([]);
    });

    it('adds no warning for an unmapped attribute that is empty', function (mixed $empty): void {
        expect(fmhUnsupportedWarnings(FieldtypeMapping::class, ['type' => 'text', 'zzz' => $empty]))->toBe([]);
    })->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);

    it('names the form, field, attribute, value and fieldtype in each warning', function (): void {
        // `zzz` is not in the attribute mapping; `is_payment` is mapped to nothing.
        $context = ['form_title' => 'Form', 'field_label' => 'Label'];

        expect(fmhUnsupportedWarnings(FieldtypeMapping::class, ['type' => 'text', 'zzz' => 'v', 'is_payment' => true]))->toBe([
            [...$context, 'attribute_name' => 'zzz', 'attribute_value' => 'v', 'form_id' => 3, 'field_type' => 'text'],
            [...$context, 'attribute_name' => 'is_payment', 'attribute_value' => true, 'form_id' => 3, 'field_type' => 'text'],
        ]);
    });
});

describe('resolveStatamicFieldtypeClass()', function (): void {
    it('looks up no fieldtype class when no fieldtype handle resolves', function (string $fieldType, string $pluginName): void {
        expect(fmhResolveFieldtypeClass($fieldType, $pluginName))->toBe([null, []]);
    })->with([
        'an unknown plugin' => ['text', 'ninja-forms'],
        'an unmapped fieldtype' => ['zzz_unmapped', 'gravity-forms'],
        'a suppressed fieldtype' => ['page', 'gravity-forms'],
    ]);

    it('looks up the class for a mapped fieldtype', function (): void {
        // The control: the recorder sees a lookup when there is a handle to look up.
        expect(fmhResolveFieldtypeClass('text', 'gravity-forms'))->toBe([null, ['App\\Fieldtypes\\Text']]);
    });
});

describe('resolveFieldtypeFromMapping()', function (): void {
    it('resolves no fieldtype for an empty type, even one the mapping names', function (string|array $fieldOrType): void {
        expect(fmhCall('resolveFieldtypeFromMapping', ['' => ['default' => 'text']], $fieldOrType))->toBeNull();
    })->with([
        'a type string' => [''],
        'a field' => [['type' => '']],
    ]);

    it('ignores a not-empty mapping on another attribute while that attribute is empty', function (string $form, mixed $empty): void {
        $byOtherAttribute = ['other' => ['#not_empty' => 'textarea']];
        $table = $form === 'default' ? ['default' => $byOtherAttribute] : $byOtherAttribute;
        $mapping = ['t' => ['default' => 'text', 'based_on_other_attribute_value' => $table]];

        expect(fmhCall('resolveFieldtypeFromMapping', $mapping, ['type' => 't', 'other' => $empty]))->toBe('text');
    })->with(['default', 'legacy'])->with([
        'null' => [null],
        'an empty string' => [''],
        'an empty array' => [[]],
    ]);

    it('maps an empty other attribute through its empty-string entry', function (string $form): void {
        $byOtherAttribute = ['other' => ['' => 'textarea']];
        $table = $form === 'default' ? ['default' => $byOtherAttribute] : $byOtherAttribute;
        $mapping = ['t' => ['default' => 'text', 'based_on_other_attribute_value' => $table]];

        expect(fmhCall('resolveFieldtypeFromMapping', $mapping, ['type' => 't', 'other' => '']))->toBe('textarea')
            ->and(fmhCall('resolveFieldtypeFromMapping', $mapping, ['type' => 't', 'other' => 'x']))->toBe('text');
    })->with(['default', 'legacy']);
});

describe('getSetAttributesFromMapping()', function (): void {
    it('sets no attributes for an empty type, even one the mapping names', function (string|array $fieldOrType): void {
        expect(FieldtypeMapping::getSetAttributesFromMapping(['' => ['set_attributes' => ['default' => ['a' => 1]]]], $fieldOrType))
            ->toBeNull();
    })->with([
        'a type string' => [''],
        'a field' => [['type' => '']],
    ]);
});

describe('replacePlaceholders()', function (): void {
    /**
     * Replace the subfield and preset placeholders in an attribute name.
     *
     * @param  string  $attributeName  The attribute name with placeholders
     * @param  ?string  $subfieldHandle  The subfield handle
     * @param  ?string  $preset  The preset value
     * @return mixed The attribute name with placeholders replaced
     */
    $replace = fn (string $attributeName, ?string $subfieldHandle, ?string $preset): mixed => fmhCall(
        'replacePlaceholders',
        $attributeName,
        null,
        $subfieldHandle,
        $preset
    );

    it('keeps the preset after a subfield handle that ends with it only without an underscore', function () use ($replace): void {
        // `stateus` ends with `us` but not with `_us`, so the preset is not a repeat.
        expect($replace('[#subfield]_[#preset]_label', 'stateus', 'us'))->toBe('stateus_us_label')
            ->and($replace('[#subfield]_[#preset]_label', 'state_us', 'us'))->toBe('state_us_label');
    });

    it('keeps the preset placeholder without a preset', function () use ($replace): void {
        // A handle ending with an underscore would end with `_` plus a missing preset.
        expect($replace('[#subfield]_[#preset]', 'x_', null))->toBe('x__[#preset]');
    });

    it('keeps the empty preset slot after a subfield handle that ends with an underscore', function () use ($replace): void {
        expect($replace('[#subfield]_[#preset]_label', 'state_', ''))->toBe('state___label');
    });

    it('replaces the preset without a subfield handle', function () use ($replace): void {
        expect($replace('[#subfield]_[#preset]_label', null, 'us'))->toBe('[#subfield]_us_label');
    });
});

describe('mapSubfieldAttributes()', function (): void {
    it('maps nothing for a subfield with an empty label, even one the mapping names', function (): void {
        expect(FmhEmptyKeyMapping::mapSubfieldAttributes('address', ['label' => '', 'customLabel' => 'Town']))->toBe([]);
    });

    it('maps nothing for a subfield whose handle is empty', function (): void {
        expect(FmhEmptyKeyMapping::mapSubfieldAttributes('address', ['label' => 'Nameless', 'customLabel' => 'Town']))->toBe([]);
    });

    it('maps the parent field attributes as well as the subfield ones', function (): void {
        expect(FmhEmptyKeyMapping::mapSubfieldAttributes('address', ['label' => 'City', 'customLabel' => 'Town'], ['parentNote' => 'P']))
            ->toBe(['city_label' => 'Town', 'city_note' => 'P']);
    });
});

describe('matchesPattern()', function (): void {
    it('matches no pattern for a null value, not even a wildcard', function (): void {
        expect(fmhCall('matchesPattern', null, '*'))->toBeFalse()
            ->and(fmhCall('matchesPattern', '', '*'))->toBeTrue();
    });
});

describe('matchesNestedAttributePattern()', function (): void {
    it('matches nothing for a string pattern, not even an empty list', function (): void {
        expect(fmhCall('matchesNestedAttributePattern', [], ['nested_attribute' => 'abc']))->toBeFalse();
    });

    it('treats numeric strings as numbers unless told otherwise', function (): void {
        expect(fmhCall('matchesNestedAttributePattern', ['a' => '1'], ['nested_attribute' => ['a' => 1]]))->toBeTrue()
            ->and(fmhCall('matchesNestedAttributePattern', ['a' => '1'], ['nested_attribute' => ['a' => 1], 'numeric_value_equivalent' => false]))->toBeFalse();
    });

    it('matches keys without regard to case when case sensitivity is off', function (): void {
        expect(fmhCall('matchesNestedAttributePattern', ['a' => 'x'], ['nested_attribute' => ['A' => 'x'], 'case_sensitive_key' => false]))->toBeTrue()
            ->and(fmhCall('matchesNestedAttributePattern', ['a' => 'x'], ['nested_attribute' => ['A' => 'x'], 'case_sensitive_key' => true]))->toBeFalse();
    });

    it('matches a key/value pattern against a list of objects', function (): void {
        expect(fmhCall('matchesNestedAttributePattern', [['a' => 'x']], ['nested_attribute' => ['a' => 'x']]))->toBeTrue();
    });
});

describe('the key/value matchers', function (): void {
    it('matches keys without regard to case', function (string $method, array $attrValue): void {
        expect(fmhCall($method, $attrValue, $method === 'matchesArrayOfArraysPositional' ? [['A' => 'x']] : ['A' => 'x'], true, false, false))
            ->toBeTrue();
    })->with([
        'two objects' => ['matchesAssociativeArrays', ['a' => 'x']],
        'a list of objects' => ['matchesArrayOfArrays', [['a' => 'x']]],
        'two lists of objects' => ['matchesArrayOfArraysPositional', [['a' => 'x']]],
    ]);

    it('matches integer keys, with and without case sensitivity', function (string $method, array $attrValue, bool $caseSensitiveKey): void {
        // An integer key is compared as its string, so it matches itself either way.
        expect(fmhCall($method, $attrValue, ['a' => 'x', 5 => 'y'], true, $caseSensitiveKey, false))->toBeTrue();
    })->with([
        'two objects' => ['matchesAssociativeArrays', ['a' => 'x', 5 => 'y']],
        'a list of objects' => ['matchesArrayOfArrays', [['a' => 'x', 5 => 'y']]],
    ])->with([
        'case sensitive' => [true],
        'case insensitive' => [false],
    ]);

    it('needs every pattern key in a list of objects, not only the first found', function (): void {
        expect(fmhCall('matchesArrayOfArrays', [['a' => 'x']], ['a' => 'x', 'b' => 'y'], true, false, false))->toBeFalse()
            ->and(fmhCall('matchesArrayOfArrays', [['a' => 'x'], ['b' => 'y']], ['a' => 'x', 'b' => 'y'], true, false, false))->toBeTrue();
    });
});
