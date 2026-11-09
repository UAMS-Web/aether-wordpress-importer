<?php

declare(strict_types=1);

/**
 * Unit tests for the Forms subfield mapping's host-fieldtype path (#98).
 *
 * When `mapSubfieldAttributes()` is given a plugin name, it resolves the
 * host app's Statamic fieldtype class, sets the parent field's preset on an
 * instance of it, and asks it for the preset-specific subfield handle, falling
 * back to the handle in the subfield mapping. `FormsSubfieldAttributeMappingTest`
 * covers the fallback with no host class; this file covers the host class.
 * The class is supplied by overriding `resolveStatamicFieldtypeClass()` in a
 * subclass, so no `App\Fieldtypes` class is defined for the rest of the run.
 *
 * It also covers `getMappedFieldAttributes()`, which lists the attributes a
 * class maps to a Statamic attribute. A line-coverage run left both
 * unexecuted.
 *
 * The early returns for a class with no `$subfieldMapping` or no
 * `$fieldAttributeMapping` are not covered here: every shipped mapping class
 * defines both, and a test class without them fails PHPStan, which analyzes
 * the whole trait for that class.
 *
 * @command  php vendor/bin/pest tests/Unit/FormsSubfieldHostFieldtypeTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;

/**
 * A stand-in for a host app fieldtype that records what it is given.
 */
final class FormsSubfieldRecordingFieldtype
{
    /**
     * Each call made on any instance, in order.
     *
     * @var list<list<mixed>>
     */
    public static array $calls = [];

    /**
     * The handle to return; `null` returns the subfield handle with `_host` appended.
     */
    public static mixed $handle = null;

    /**
     * Record the configuration set on the fieldtype.
     *
     * @param  array<string, mixed>  $config  The configuration
     */
    public function setConfig(array $config): void
    {
        self::$calls[] = ['setConfig', $config];
    }

    /**
     * Record the lookup and return the configured handle.
     *
     * @param  string  $handle  The subfield handle from the mapping
     * @param  ?string  $preset  The preset
     * @return mixed The handle to use
     */
    public function getPresetSpecificSubfieldHandle(string $handle, ?string $preset): mixed
    {
        self::$calls[] = ['getPresetSpecificSubfieldHandle', $handle, $preset];

        return self::$handle ?? $handle.'_host';
    }
}

/**
 * A stand-in for a host app fieldtype with neither helper.
 */
final class FormsSubfieldPlainFieldtype {}

/**
 * A Gravity Forms mapping class whose host fieldtype class is set by the test.
 */
final class FormsSubfieldHostMapping extends FieldtypeMapping
{
    /**
     * The class name to resolve to.
     */
    public static ?string $resolved = null;

    /**
     * Each resolution requested, as `[fieldType, pluginName]`.
     *
     * @var list<list<mixed>>
     */
    public static array $requests = [];

    /**
     * Record the request and return the configured class name.
     *
     * @param  string|array<int|string, mixed>  $fieldType  The field type
     * @param  string  $pluginName  The plugin name
     * @return ?string The class name
     */
    public static function resolveStatamicFieldtypeClass(string|array $fieldType, string $pluginName): ?string
    {
        self::$requests[] = [$fieldType, $pluginName];

        return self::$resolved;
    }
}

/**
 * A Gravity Forms mapping class with a small attribute mapping.
 */
final class FormsSubfieldAttributeListMapping extends FieldtypeMapping
{
    /**
     * The field attribute mapping.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeMapping = [
        'label' => ['statamic_attribute' => 'display'],
        'cssClass' => ['statamic_attribute' => ['default' => 'classes']],
        'unset' => ['statamic_attribute' => null],
        'none' => ['other' => 'x'],
        'empty' => ['statamic_attribute' => ''],
    ];
}

beforeEach(function (): void {
    FormsSubfieldRecordingFieldtype::$calls = [];
    FormsSubfieldRecordingFieldtype::$handle = null;
    FormsSubfieldHostMapping::$resolved = FormsSubfieldRecordingFieldtype::class;
    FormsSubfieldHostMapping::$requests = [];
});

it('uses the handle the host fieldtype gives for the preset', function (
    array $parentField,
    array $config,
    ?string $preset
): void {
    expect(FormsSubfieldHostMapping::mapSubfieldAttributes('address', ['label' => 'City', 'customLabel' => 'C'], $parentField, 'gravity-forms'))->toBe(['city_host_label' => 'C'])
        ->and(FormsSubfieldHostMapping::$requests)->toBe([['address', 'gravity-forms']])
        ->and(FormsSubfieldRecordingFieldtype::$calls)->toBe([['setConfig', $config], ['getPresetSpecificSubfieldHandle', 'city', $preset]]);
})->with([
    'a raw preset' => [['preset' => 'canadian'], ['preset' => 'canadian'], 'canadian'],
    'a raw preset beside the mapped address type, which wins (#429)' => [['preset' => 'canadian', 'addressType' => 'us'], ['preset' => 'us'], 'us'],
    'an address type' => [['addressType' => 'us'], ['preset' => 'us'], 'us'],
    'neither' => [[], [], null],
    'an empty preset' => [['preset' => ''], [], ''],
]);

it('falls back to the mapping handle when the host fieldtype gives none', function (
    mixed $handle
): void {
    FormsSubfieldRecordingFieldtype::$handle = $handle;

    expect(FormsSubfieldHostMapping::mapSubfieldAttributes('address', ['label' => 'City', 'customLabel' => 'C'], ['addressType' => 'us'], 'gravity-forms'))->toBe(['city_label' => 'C']);
})->with([
    'an empty handle' => [''],
    'a handle that is not a string' => [['city_x']],
]);

it('falls back to the mapping handle when no host fieldtype resolves', function (
    ?string $resolved,
    ?string $pluginName,
    int $requests
): void {
    FormsSubfieldHostMapping::$resolved = $resolved;

    expect(FormsSubfieldHostMapping::mapSubfieldAttributes('address', ['label' => 'City', 'customLabel' => 'C'], ['addressType' => 'us'], $pluginName))->toBe(['city_label' => 'C'])
        ->and(FormsSubfieldHostMapping::$requests)->toHaveCount($requests)
        ->and(FormsSubfieldRecordingFieldtype::$calls)->toBe([]);
})->with([
    'no plugin' => [FormsSubfieldRecordingFieldtype::class, null, 0],
    'an empty plugin' => [FormsSubfieldRecordingFieldtype::class, '', 0],
    'no class' => [null, 'gravity-forms', 1],
    'an empty class' => ['', 'gravity-forms', 1],
    'a class that does not exist' => ['App\\Fieldtypes\\NoSuchFieldtype', 'gravity-forms', 1],
]);

it('uses the mapping handle when the host fieldtype has neither helper', function (): void {
    FormsSubfieldHostMapping::$resolved = FormsSubfieldPlainFieldtype::class;

    expect(FormsSubfieldHostMapping::mapSubfieldAttributes('address', ['label' => 'City', 'customLabel' => 'C'], ['addressType' => 'us'], 'gravity-forms'))->toBe(['city_label' => 'C']);
});

it('asks the host fieldtype for no handle when the subfield mapping has none', function (): void {
    expect(FormsSubfieldHostMapping::mapSubfieldAttributes('time', ['label' => 'Hour', 'customLabel' => 'H'], [], 'gravity-forms'))->toBe([])
        ->and(FormsSubfieldRecordingFieldtype::$calls)->toBe([['setConfig', []]]);
});

it('lists the attributes a class maps to a Statamic attribute', function (): void {
    expect(FormsSubfieldAttributeListMapping::getMappedFieldAttributes())->toBe(['label', 'cssClass', 'empty'])
        ->and(FieldtypeMapping::getMappedFieldAttributes())->toContain('label', 'description')
        ->and(FieldtypeMapping::getMappedFieldAttributes())->not->toContain('duplicatable');
});

it('resolves a based_on_attribute_value lookup for an attribute with no name as unset', function (): void {
    $method = new ReflectionMethod(FieldtypeMapping::class, 'processSubfieldStatamicAttribute');

    expect($method->invoke(null, ['based_on_attribute_value' => ['#not_set' => 'unset_[#subfield]'], 'default' => 'd_[#subfield]'], null, [], 'city', ['label' => 'City']))->toBe(['unset_city', true]);
});
