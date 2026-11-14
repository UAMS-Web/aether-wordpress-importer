<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\FormatsMappedValues;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\MapsAttributesFromMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\MapsFieldAttributes;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\MapsNotSetAttributes;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\MapsSubfieldAttributes;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\MatchesAttributePatterns;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\ProcessesConditionalMappings;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\ProcessesModifierPatterns;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\ResolvesFieldtypes;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\TransformsMappedValues;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\TranslatesArrayKeys;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns\TranslatesAttributeValues;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Shared trait for fieldtype mapping functionality.
 *
 * This trait provides common methods for mapping WordPress form field types
 * to Statamic fieldtypes, including utilities for discovering and reporting
 * unmapped field types.
 */
trait FieldtypeMappingTrait
{
    /**
     * Format mapped values: complex arrays, dates, numbers, slugs, strings and options.
     */
    use FormatsMappedValues;

    /**
     * Map a field's attributes from a mapping configuration.
     */
    use MapsAttributesFromMapping;

    /**
     * Map field attributes and field options, and resolve fieldtype-specific configuration.
     */
    use MapsFieldAttributes;

    /**
     * Map the #not_set cases for attributes the input data does not carry.
     */
    use MapsNotSetAttributes;

    /**
     * Map subfield attributes, conditional logic and field handles.
     */
    use MapsSubfieldAttributes;

    /**
     * Match attribute values against patterns.
     */
    use MatchesAttributePatterns;

    /**
     * Process value, pattern and attribute-based mappings.
     */
    use ProcessesConditionalMappings;

    /**
     * Process modifier patterns and array replacements.
     */
    use ProcessesModifierPatterns;

    /**
     * Resolve fieldtypes, linked fields, sets, presets and placeholders from a mapping.
     */
    use ResolvesFieldtypes;

    /**
     * Combine, validate, convert and transform mapped values.
     */
    use TransformsMappedValues;

    /**
     * Translate the keys of array values and normalize options.
     */
    use TranslatesArrayKeys;

    /**
     * Translate attribute values and field references.
     */
    use TranslatesAttributeValues;

    /**
     * Validation lists for checking attribute values against predefined sets.
     *
     * This array contains lists of valid values that can be used to validate
     * attribute values during the mapping process. Each key represents a list
     * identifier that can be referenced in the fieldAttributeMapping array.
     *
     * @var array<string, array<string>>
     */
    protected static array $validationLists = [
        'visibility_values' => [
            'hidden',
        ],
    ];

    /**
     * Extracted other_label from options processing.
     *
     * This property stores the other_label extracted from other_* keys in options
     * during the transformOptionsJsonToArray process.
     */
    protected static ?string $extractedOtherLabel = null;

    /**
     * Normalize mixed values to array shape for safe iteration and offsets.
     *
     * @param  mixed  $value  The candidate array value
     * @return array<int|string, mixed> The normalized array value
     */
    protected static function asArray(
        mixed $value
    ): array {
        if (! \is_array($value)) {
            return [];
        }

        // Re-assert the broad declared shape so callers always receive a
        // generic mixed-keyed array. Without this widening, static analysis
        // would preserve narrow inferred shapes from configuration data and
        // falsely report dynamic keys (e.g. '#not_set') as non-existent.
        /** @var array<int|string, mixed> $value */
        return $value;
    }

    /**
     * Normalize mixed values to strings for safe string-only operations.
     *
     * @param  mixed  $value  The candidate string value
     * @return ?string The normalized string value
     */
    protected static function stringValue(
        mixed $value
    ): ?string {
        if (! \is_string($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Normalize mixed configuration values to mapping arrays.
     *
     * @param  mixed  $value  The candidate mapping configuration
     * @return array<int|string, mixed> The normalized mapping configuration
     */
    protected static function mappingConfig(
        mixed $value
    ): array {
        return self::asArray($value);
    }

    /**
     * Read a mapping array entry when the key exists.
     *
     * @param  array<int|string, mixed>  $map  The mapping array
     * @param  int|string  $key  The key to read
     * @return mixed The mapped value, or null when the key is absent
     */
    protected static function tryMappingValue(
        array $map,
        int|string $key
    ): mixed {
        if (! array_key_exists($key, $map)) {
            return null;
        }

        return $map[$key];
    }

    /**
     * Safely read a value from a mixed array using a mixed key.
     *
     * Consolidates the recurring "check key exists, then read offset" pattern
     * for mixed-typed configuration data. Returns null when the candidate is
     * not an array or the normalized key is not present.
     *
     * @param  mixed  $array  The candidate array to read from
     * @param  mixed  $key  The candidate key to look up
     * @return mixed The stored value, or null when missing or inaccessible
     */
    protected static function mappingValue(
        mixed $array,
        mixed $key
    ): mixed {
        // Bail out when the candidate is not a real array.
        if (! \is_array($array)) {
            return null;
        }

        // Normalize the key to a valid int or string array offset.
        $normalizedKey = self::normalizeArrayKey($key);

        // Bail out when the key cannot be used as an array offset.
        if ($normalizedKey === null) {
            return null;
        }

        // Return the stored value when present, otherwise null.
        return $array[$normalizedKey] ?? null;
    }

    /**
     * Get the Statamic fieldtype for a given WordPress field.
     *
     * @param  string|array<int|string, mixed>  $wordpressField  The field type
     *                                                           or full WordPress field data
     * @return string|null|false Statamic fieldtype, null if not mapped, or
     *                           false if suppressed
     */
    abstract public static function getStatamicFieldtype(
        string|array $wordpressField
    ): string|null|false;

    /**
     * Add a warning for an unmapped or null-mapped fieldtype.
     *
     * @param  string  $fieldType  The field type being checked
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     */
    public static function addUnmappedFieldtypeWarning(
        string $fieldType,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        ImportWarningManager::addWarning(
            'forms',
            'unmapped_fieldtypes',
            [
                'form_title' => $formTitle,
                'field_label' => $fieldLabel,
                'form_id' => $formId,
                'field_type' => $fieldType,
            ]
        );
    }

    /**
     * Check if a fieldtype mapping indicates the field should be processed.
     *
     * @param  array<int|string, mixed>  $mapping  The mapping configuration for a fieldtype
     * @return bool True if the fieldtype is mapped to a valid fieldtype or false (suppressed), false if unmapped
     */
    public static function isFieldtypeMapped(
        array $mapping
    ): bool {
        // Check if default is set and is not null (null means unmapped, false means suppressed)
        if (
            isset($mapping['default'])

        ) {
            return true;
        }

        // Check if any attribute-based mappings exist
        if (
            isset($mapping['attributes'])
            && \is_array($mapping['attributes'])
        ) {
            foreach ($mapping['attributes'] as $attrMap) {
                if (! \is_array($attrMap)) {
                    continue;
                }

                foreach ($attrMap as $val) {
                    if ($val !== null) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check whether a skipped fieldtype should leave a trace in the imported blueprint.
     *
     * **A separate flag from `alert_unsupported`, deliberately.** That flag governs a
     * warning, whose audience is the operator running the import and who sees it once.
     * This one governs a note left in the blueprint, whose audience is an editor opening
     * the form months later. Tying the two together would mean the first time they needed
     * to diverge, one of them would be wrong — and they already diverge: `captcha` is
     * quiet AND leaves no trace, while `quantity` warns AND leaves one (wordpress-importer-archive#831).
     *
     * @param  array<int|string, mixed>  $mapping  The mapping configuration for a fieldtype
     * @return bool True when the type declares `trace_when_skipped => true`
     */
    public static function tracesWhenSkipped(
        array $mapping
    ): bool {
        // Absent means no trace: the flag opts a type in rather than out, so a type
        // added later is silent until someone decides it changes what the form does.
        return \array_key_exists(
            'trace_when_skipped',
            $mapping
        )
            && $mapping['trace_when_skipped'] === true;
    }

    /**
     * Check if a fieldtype is mapped to null or not mapped at all, and warn if so.
     *
     * @param  array<int|string, mixed>  $mapping  The mapping array (fieldtype => statamic_fieldtype)
     * @param  string  $fieldType  The field type to check
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     */
    public static function warnIfFieldtypeUnmapped(
        array $mapping,
        string $fieldType,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        $entry = $mapping[$fieldType] ?? null;

        // ---
        // A type declaring `alert_unsupported => false` is deliberately unsupported
        // and deliberately quiet: `captcha` has no Statamic equivalent and never
        // will, so warning on every one is noise that trains a reader to skim. The
        // flag was already declared per-type and simply never consulted here (wordpress-importer-archive#815).
        // ---
        if (
            \is_array($entry)
            && \array_key_exists(
                'alert_unsupported',
                $entry
            )
            && $entry['alert_unsupported'] === false
        ) {
            return;
        }

        if (
            ! self::arrayKeyExists(
                $fieldType,
                $mapping
            )
            || ! \is_array($mapping[$fieldType])
            || ! self::isFieldtypeMapped($mapping[$fieldType])
        ) {
            self::addUnmappedFieldtypeWarning(
                $fieldType,
                $formTitle,
                $fieldLabel,
                $formId
            );
        }
    }

    /**
     * Check if an attribute is not set (i.e., the key doesn't exist in the data
     * array). This is used to support '#not_set' in value_translation mappings.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array to check against
     * @return bool True if the attribute is not set, false otherwise
     */
    protected static function isAttributeNotSet(
        int|string|null $attrName,
        array $data
    ): bool {
        if (self::normalizeArrayKey($attrName) === null) {
            return true;
        }

        return ! self::arrayKeyExists(
            $attrName,
            $data
        );
    }

    /**
     * Check if an attribute is not set in field options specifically.
     *
     * This is used to support '#not_set' in value_translation mappings for field
     * options.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $fieldData  The original field data
     * @return bool True if the attribute is not set in field_options, false otherwise
     */
    protected static function isFieldOptionNotSet(
        int|string|null $attrName,
        array $fieldData
    ): bool {
        if (self::normalizeArrayKey($attrName) === null) {
            return true;
        }

        // Check if field_options exists and is a string
        if (
            ! isset($fieldData['field_options'])
            || ! \is_string($fieldData['field_options'])
        ) {
            return true; // If no field_options, consider it not set
        }

        // Parse the field_options JSON
        $fieldOptions = \json_decode(
            $fieldData['field_options'],
            true
        );
        if (! \is_array($fieldOptions)) {
            return true; // If parsing fails, consider it not set
        }

        // Check if the attribute exists in the parsed field_options
        return ! self::arrayKeyExists(
            $attrName,
            $fieldOptions
        );
    }

    /**
     * Check if an attribute is not empty (not null, not empty string, not empty
     * array).
     *
     * This is used to support '#not_empty' in value_translation mappings.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @return bool True if the attribute is set and not empty, false otherwise
     */
    protected static function isAttributeNotEmpty(
        int|string|null $attrName,
        array $data
    ): bool {
        $normalizedAttrName = self::normalizeArrayKey($attrName);

        if ($normalizedAttrName === null) {
            return false;
        }

        if (
            ! self::arrayKeyExists(
                $normalizedAttrName,
                $data
            )
        ) {
            return false; // Not set counts as empty
        }

        $value = $data[$normalizedAttrName];

        // Check for null
        if ($value === null) {
            return false;
        }

        // Check for empty string
        if ($value === '') {
            return false;
        }

        // Check for empty array
        if (
            \is_array($value)
            && $value === []
        ) {
            return false;
        }

        // Check for array with only empty values (like ["foo":"","bar":""])
        if (\is_array($value)) {
            foreach ($value as $val) {
                if (
                    ! in_array($val, [null, '', []], true)
                ) {
                    return true; // Found at least one non-empty value
                }
            }

            return false; // All values are empty
        }

        return true; // Value exists and is not empty
    }

    /**
     * Check if an attribute is not empty in field options specifically.
     *
     * This is used to support '#not_empty' in value_translation mappings for field
     * options.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $fieldData  The original field data
     * @return bool True if the attribute is set and not empty in field_options, false otherwise
     */
    protected static function isFieldOptionNotEmpty(
        int|string|null $attrName,
        array $fieldData
    ): bool {
        $normalizedAttrName = self::normalizeArrayKey($attrName);

        if ($normalizedAttrName === null) {
            return false;
        }

        // Check if field_options exists and is a string
        if (
            ! isset($fieldData['field_options'])
            || ! \is_string($fieldData['field_options'])
        ) {
            return false; // If no field_options, count it as empty
        }

        // Parse the field_options JSON
        $fieldOptions = \json_decode(
            $fieldData['field_options'],
            true
        );
        if (! \is_array($fieldOptions)) {
            return false; // If parsing fails, count it as empty
        }

        // Check if the attribute exists in the parsed field_options
        if (
            ! self::arrayKeyExists(
                $normalizedAttrName,
                $fieldOptions
            )
        ) {
            return false; // Not set counts as empty
        }

        $value = $fieldOptions[$normalizedAttrName];

        // Check for null
        if ($value === null) {
            return false;
        }

        // Check for empty string
        if ($value === '') {
            return false;
        }

        // Check for empty array
        if (
            \is_array($value)
            && $value === []
        ) {
            return false;
        }

        // Check for array with only empty values (like ["foo":"","bar":""])
        if (\is_array($value)) {
            foreach ($value as $val) {
                if (
                    ! in_array($val, [null, '', []], true)
                ) {
                    return true; // Found at least one non-empty value
                }
            }

            return false; // All values are empty
        }

        return true; // Value exists and is not empty
    }

    /**
     * Check if an attribute is not empty, handling both regular data and field
     * options.
     *
     * This is used to support '#not_empty' in various mapping contexts.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for field options
     * @return bool True if the attribute is set and not empty, false otherwise
     */
    protected static function isAttributeNotEmptyWithFallback(
        int|string|null $attrName,
        array $data,
        ?array $originalFieldData = null
    ): bool {
        // If we have original field data, check if the attribute is not empty in field options
        if ($originalFieldData !== null) {
            return self::isFieldOptionNotEmpty(
                $attrName,
                $originalFieldData
            );
        }

        // Fallback to checking if the attribute is not empty in the current data
        return self::isAttributeNotEmpty(
            $attrName,
            $data
        );
    }

    /**
     * Check if an attribute is not set, handling both regular data and field options.
     * This is used to support '#not_set' in various mapping contexts.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for field options
     * @return bool True if the attribute is not set, false otherwise
     */
    protected static function isAttributeNotSetWithFallback(
        int|string|null $attrName,
        array $data,
        ?array $originalFieldData = null
    ): bool {
        // If we have original field data, check if the attribute is not set in field options
        if ($originalFieldData !== null) {
            return self::isFieldOptionNotSet(
                $attrName,
                $originalFieldData
            );
        }

        // Fallback to checking if the attribute is not set in the current data
        return self::isAttributeNotSet(
            $attrName,
            $data
        );
    }

    /**
     * Check if an attribute is empty, handling both regular data and field options.
     * This is used to support '#empty' in various mapping contexts.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for field options
     * @return bool True if the attribute is set and is an empty string, false otherwise
     */
    protected static function isAttributeEmptyWithFallback(
        int|string|null $attrName,
        array $data,
        ?array $originalFieldData = null
    ): bool {
        // If we have original field data, check if the attribute is empty in field options
        if ($originalFieldData !== null) {
            return self::isFieldOptionEmpty(
                $attrName,
                $originalFieldData
            );
        }

        // Fallback to checking if the attribute is empty in the current data
        return self::isAttributeEmpty(
            $attrName,
            $data
        );
    }

    /**
     * Check if an attribute is empty (set but empty string).
     * This is used to support '#empty' in value_translation mappings.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @return bool True if the attribute is set and is an empty string, false otherwise
     */
    protected static function isAttributeEmpty(
        int|string|null $attrName,
        array $data
    ): bool {
        $normalizedAttrName = self::normalizeArrayKey($attrName);

        if ($normalizedAttrName === null) {
            return false;
        }

        if (
            ! self::arrayKeyExists(
                $normalizedAttrName,
                $data
            )
        ) {
            return false; // Not set means not empty
        }

        $value = $data[$normalizedAttrName];

        // Check for empty string specifically
        return $value === '';
    }

    /**
     * Check if an attribute is empty in field options specifically.
     *
     * This is used to support '#empty' in value_translation mappings for field
     * options.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $fieldData  The original field data
     * @return bool True if the attribute is set and is an empty string in field_options, false otherwise
     */
    protected static function isFieldOptionEmpty(
        int|string|null $attrName,
        array $fieldData
    ): bool {
        $normalizedAttrName = self::normalizeArrayKey($attrName);

        if ($normalizedAttrName === null) {
            return false;
        }

        // Check if field_options exists and is a string
        if (
            ! isset($fieldData['field_options'])
            || ! \is_string($fieldData['field_options'])
        ) {
            return false; // If no field_options, consider it not empty
        }

        // Parse the field_options JSON
        $fieldOptions = \json_decode(
            $fieldData['field_options'],
            true
        );
        if (! \is_array($fieldOptions)) {
            return false; // If parsing fails, consider it not empty
        }

        // Check if the attribute exists in the parsed field_options
        if (
            ! self::arrayKeyExists(
                $normalizedAttrName,
                $fieldOptions
            )
        ) {
            return false; // Not set means not empty
        }

        $value = $fieldOptions[$normalizedAttrName];

        // Check for empty string specifically
        return $value === '';
    }

    /**
     * Check if an array field option should be skipped for fieldtypes with subfields.
     *
     * This method identifies array field options like placeholder configurations
     * (e.g., {"first":"","middle":"","last":""}) that are used for fieldtypes
     * with subfields (e.g., name, address) and should be disregarded during
     * mapping since they represent subfield-specific configurations rather than
     * general field attributes.
     *
     * @param  string  $attrName  The attribute name to check
     * @param  mixed  $attrValue  The attribute value to check
     * @param  ?string  $fieldtype  The fieldtype to check against
     * @return bool True if the array field option should be skipped, false otherwise
     */
    protected static function shouldSkipArrayFieldOption(
        int|string|null $attrName,
        mixed $attrValue,
        ?string $fieldtype
    ): bool {
        // Only check array values
        if (! \is_array($attrValue)) {
            // Not an array, so no need to filter
            return false;
        }

        // Define fieldtypes that have subfields
        $fieldtypesWithSubfields = [
            'name',
            'address',
        ];

        // Only apply this logic to fieldtypes with subfields
        if (
            ! \in_array(
                $fieldtype,
                $fieldtypesWithSubfields,
                true
            )
        ) {
            // Clean up variables to free up memory
            unset(
                $fieldtypesWithSubfields
            );

            // Not a fieldtype with subfields, so no filtering needed
            return false;
        }

        // Check if this is a placeholder configuration for subfields
        // Common patterns: {"first":"","middle":"","last":""} for name fields
        // or similar structures for address fields
        if ($attrName === 'placeholder') {
            $allEmptyStrings = array_all($attrValue, fn ($value): bool => $value === '');
            // If all values are empty strings, this is likely a subfield configuration
            if ($allEmptyStrings) {
                // Clean up variables to free up memory
                unset(
                    $fieldtypesWithSubfields,
                    $allEmptyStrings
                );

                // Skip this array field option as it's a subfield configuration
                return true;
            }
        }

        // Clean up variables to free up memory
        unset(
            $fieldtypesWithSubfields
        );

        // No filtering needed for this attribute
        return false;
    }

    /**
     * Get the fieldtype mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array for fieldtypes
     */
    public static function getMapping(): array
    {
        return static::$mapping;
    }

    /**
     * Resolve field-attribute options mapping when defined on the implementing class.
     *
     * @return array<int|string, mixed> The options mapping array, or empty when
     *                                  undefined
     */
    protected static function resolveFieldAttributeOptionsMapping(): array
    {
        if (
            ! property_exists(
                static::class,
                'fieldAttributeOptionsMapping'
            )
        ) {
            return [];
        }

        $classVariables = \get_class_vars(static::class);

        return self::mappingConfig(
            $classVariables['fieldAttributeOptionsMapping'] ?? []
        );
    }

    /**
     * Get the subfield mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The subfield mapping array
     */
    public static function getSubfieldMapping(): array
    {
        if (
            ! property_exists(
                static::class,
                'subfieldMapping'
            )
        ) {
            return [];
        }

        return static::$subfieldMapping;
    }

    /**
     * Normalize mixed values into a safe array shape.
     *
     * @param  mixed  $value  The candidate value
     * @return array<int|string, mixed> The normalized array value
     */
    protected static function normalizeMixedToArray(
        mixed $value
    ): array {
        return \is_array($value) ? $value : [];
    }

    /**
     * Resolve a mixed fieldtype mapping result to string|null|false.
     *
     * @param  mixed  $value  The candidate mapping result
     * @return string|null|false The resolved fieldtype, null, or false when suppressed
     */
    protected static function resolveFieldtypeMappingValue(
        mixed $value
    ): string|null|false {
        if ($value === false || $value === null) {
            return $value;
        }

        return self::stringValue($value);
    }

    /**
     * Resolve a mixed mapping value to an optional string.
     *
     * @param  mixed  $value  The candidate mapping result
     * @return ?string The resolved string value, or null when not a string
     */
    protected static function resolveOptionalStringMappingValue(
        mixed $value
    ): ?string {
        return self::stringValue($value);
    }

    /**
     * Resolve the active conditional mapping block from a default/specific config.
     *
     * @param  array<int|string, mixed>  $basedOnConfig  The conditional mapping config
     * @param  string  $type  The current fieldtype
     * @return array<int|string, mixed> The resolved mapping block to evaluate
     */
    protected static function resolveStructuredConditionalConfig(
        array $basedOnConfig,
        string $type
    ): array {
        $defaultBasedOnConfig = self::asArray(
            self::mappingValue($basedOnConfig, 'default')
        );
        $specificBasedOnConfig = self::asArray(
            self::mappingValue($basedOnConfig, 'specific')
        );
        $typeSpecificConfig = self::mappingValue(
            $specificBasedOnConfig,
            $type
        );

        if ($typeSpecificConfig !== null) {
            return self::asArray($typeSpecificConfig);
        }

        return $defaultBasedOnConfig;
    }

    /**
     * Resolve a string mapping from a conditional attribute-value config block.
     *
     * @param  array<int|string, mixed>  $configToUse  Attribute-name keyed mapping rules
     * @param  array<int|string, mixed>  $parsedField  Parsed field data
     * @return ?string Resolved string mapping value or null when unmatched
     */
    protected static function resolveStringMappingFromConditionalConfig(
        array $configToUse,
        array $parsedField
    ): ?string {
        foreach ($configToUse as $otherAttrName => $valueMappings) {
            $normalizedOtherAttrName = self::normalizeArrayKey($otherAttrName);
            if ($normalizedOtherAttrName === null) {
                continue;
            }

            $valueMappingsArray = self::asArray($valueMappings);
            $otherAttrValue = self::mappingValue(
                $parsedField,
                $normalizedOtherAttrName
            );

            if (self::arrayKeyExists($otherAttrValue, $valueMappingsArray)) {
                return self::resolveOptionalStringMappingValue(
                    self::mappingValue(
                        $valueMappingsArray,
                        $otherAttrValue
                    )
                );
            }

            if (
                self::arrayKeyExists(
                    '#not_set',
                    $valueMappingsArray
                )
                && ! self::arrayKeyExists(
                    $normalizedOtherAttrName,
                    $parsedField
                )
            ) {
                return self::resolveOptionalStringMappingValue(
                    self::mappingValue(
                        $valueMappingsArray,
                        '#not_set'
                    )
                );
            }

            if (
                self::arrayKeyExists(
                    '#not_empty',
                    $valueMappingsArray
                )
                && (! in_array($otherAttrValue, [null, '', []], true))
            ) {
                return self::resolveOptionalStringMappingValue(
                    self::mappingValue(
                        $valueMappingsArray,
                        '#not_empty'
                    )
                );
            }

            if (
                self::arrayKeyExists(
                    '',
                    $valueMappingsArray
                )
                && $otherAttrValue === ''
            ) {
                return self::resolveOptionalStringMappingValue(
                    self::mappingValue(
                        $valueMappingsArray,
                        ''
                    )
                );
            }
        }

        return null;
    }

    /**
     * Normalize a transformation lookup key to a string.
     *
     * @param  int|string  $key  The candidate transformation key
     * @return string The normalized transformation key
     */
    protected static function normalizeTransformationKey(
        int|string $key
    ): string {
        return \is_string($key) ? $key : (string) $key;
    }

    /**
     * Normalize mixed values into a safe string representation.
     *
     * @param  mixed  $value  The candidate value
     * @return string The normalized string value
     */
    protected static function normalizeMixedToString(
        mixed $value
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        if (
            \is_int($value)
            || \is_float($value)
            || \is_bool($value)
        ) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Normalize resolved transformation values to the expected array config shape.
     *
     * @param  mixed  $transformationValue  The resolved value
     * @param  string  $key  The target transformation key
     * @return array<int|string, mixed>|null The normalized configuration
     */
    protected static function normalizeTransformationConfig(
        mixed $transformationValue,
        string $key
    ): ?array {
        if (\is_array($transformationValue)) {
            return $transformationValue;
        }

        if ($transformationValue === null) {
            return null;
        }

        return [$key => $transformationValue];
    }

    /**
     * Normalize mixed keys to supported array assignment key types.
     *
     * @param  mixed  $key  The candidate key
     * @return int|string The normalized key for array assignment
     */
    protected static function normalizeArrayKeyForAssignment(
        mixed $key
    ): int|string {
        $normalizedKey = self::normalizeArrayKey($key);
        if ($normalizedKey !== null) {
            return $normalizedKey;
        }

        return self::normalizeMixedToString($key);
    }

    /**
     * Determine whether an array contains a given key.
     *
     * @param  mixed  $key  The candidate array key
     * @param  mixed  $array  The candidate array
     */
    protected static function arrayKeyExists(
        mixed $key,
        mixed $array
    ): bool {
        $normalizedKey = self::normalizeArrayKey($key);

        if (
            $normalizedKey === null
            || ! \is_array($array)
        ) {
            return false;
        }

        return \array_key_exists(
            $normalizedKey,
            $array
        );
    }

    /**
     * Normalize candidate array keys for safe lookup operations.
     *
     * @param  mixed  $key  The candidate key value
     * @return int|string|null The normalized key, or null when unsupported
     */
    protected static function normalizeArrayKey(
        mixed $key
    ): int|string|null {
        if (
            \is_int($key)
            || \is_string($key)
        ) {
            return $key;
        }

        if (
            \is_bool($key)
            || \is_float($key)
        ) {
            return (string) $key;
        }

        return null;
    }

    /**
     * Resolve a value-translation mapping lookup key for scalar or array values.
     *
     * @param  mixed  $value  The mapped attribute value
     */
    private static function mappingLookupKey(mixed $value): int|string
    {
        if (\is_array($value)) {
            return (string) \json_encode($value);
        }

        if (\is_int($value)) {
            return $value;
        }

        return self::normalizeMixedToString($value);
    }

    /**
     * Convert a foreach index to a 1-based numeric count for transformations.
     *
     * @param  int|string  $index  The array index from foreach
     * @return ?int The 1-based count, or null when the index is not numeric
     */
    private static function numericChoiceCount(
        int|string $index
    ): ?int {
        if (\is_int($index)) {
            return $index + 1;
        }

        if (\is_numeric($index)) {
            return (int) $index + 1;
        }

        return null;
    }
}
