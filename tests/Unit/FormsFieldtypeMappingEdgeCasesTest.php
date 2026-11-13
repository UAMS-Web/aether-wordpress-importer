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
