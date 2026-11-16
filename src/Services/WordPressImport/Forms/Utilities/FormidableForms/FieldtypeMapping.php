<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use UAMSWeb\Aether\WordPressImporter\Data\Forms\FormidableForms\FieldtypeMappingData;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Provides mapping from Formidable Forms field types to Statamic fieldtypes.
 *
 * This class is responsible for:
 *   - Maintaining a mapping array of Formidable Forms field types to Statamic
 *     fieldtypes
 *   - Providing lookup methods for mapping
 *   - Identifying unmapped Formidable Forms fieldtypes in a forms export
 */
class FieldtypeMapping
{
    use FieldtypeMappingTrait;

    /**
     * Mapping from Formidable Forms field types to Statamic fieldtypes.
     *
     * Held in {@see FieldtypeMappingData::MAPPING}, which documents its shape.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $mapping = FieldtypeMappingData::MAPPING;

    /**
     * Maps Formidable Forms field attributes to Statamic field attributes.
     *
     * Held in {@see FieldtypeMappingData::FIELD_ATTRIBUTE_MAPPING}, which documents its shape.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeMapping = FieldtypeMappingData::FIELD_ATTRIBUTE_MAPPING;

    /**
     * Mapping configuration for Formidable Forms field attributes to Statamic field
     * attributes and related configuration options.
     *
     * Held in {@see FieldtypeMappingData::FIELD_ATTRIBUTE_OPTIONS_MAPPING}, which documents its shape.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeOptionsMapping = FieldtypeMappingData::FIELD_ATTRIBUTE_OPTIONS_MAPPING;

    /**
     * Maps Formidable Forms subfield labels to Statamic subfield handles with
     * hierarchical attribute inheritance.
     *
     * Held in {@see FieldtypeMappingData::SUBFIELD_MAPPING}, which documents its shape.
     *
     * @var array<int|string, mixed>
     */
    protected static array $subfieldMapping = FieldtypeMappingData::SUBFIELD_MAPPING;

    /**
     * The Formidable Forms address subfield keys, in the order the form shows them.
     *
     * @var list<string>
     */
    public const array ADDRESS_SUBFIELD_KEYS = [
        'line1',
        'line2',
        'city',
        'state',
        'zip',
        'country',
    ];

    /**
     * Get the Statamic address preset for a Formidable Forms address field.
     *
     * Reads the `address_type` field option through the same mapping that writes
     * the field's `preset`, so the preset that picks a preset-specific subfield
     * handle is the one the blueprint field gets: `international` for
     * `international`, `europe` or an unset `address_type`, and `american` for
     * `us` and `generic`.
     *
     * @param  array<int|string, mixed>  $field  The Formidable Forms field data
     * @return ?string The Statamic preset, or null when the mapping yields none
     */
    public static function getAddressPreset(
        array $field
    ): ?string {
        // Parse the field options so `address_type` is readable beside the field data
        $parsedField = $field;
        if (
            isset($field['field_options'])
            && \is_string($field['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $field['field_options'],
                true
            );
            if (\is_array($fieldOptions)) {
                $parsedField = \array_merge(
                    $field,
                    $fieldOptions
                );
            }
        }

        // Map only the `address_type` option, which also resolves an unset one
        $mapped = static::mapAttributesFromMapping(
            $parsedField,
            [
                'address_type' => self::mappingValue(
                    self::resolveFieldAttributeOptionsMapping(),
                    'address_type'
                ),
            ],
            [],
            null,
            'address',
            $field
        );

        return self::stringValue(
            self::mappingValue(
                $mapped,
                'preset'
            )
        );
    }

    /**
     * Get the Statamic fieldtype for a given Formidable Forms field type or field
     * array.
     *
     * @param  string|array<int|string, mixed>  $formidableField  Either the field type string or the full field array
     * @return string|null|false Statamic fieldtype, null if not mapped, or false if suppressed
     */
    public static function getStatamicFieldtype(
        string|array $formidableField
    ): string|null|false {
        return static::resolveFieldtypeFromMapping(
            static::$mapping,
            $formidableField
        );
    }

    /**
     * Get the mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array
     */
    public static function getMapping(): array
    {
        return static::$mapping;
    }

    /**
     * Get the field attribute mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array for field attributes
     */
    public static function getFieldAttributeMapping(): array
    {
        return static::$fieldAttributeMapping;
    }

    /**
     * Get the field options mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array for field options
     */
    public static function getFieldOptionsMapping(): array
    {
        return static::$fieldAttributeOptionsMapping;
    }

    /**
     * Extracts conditional logic data from Formidable Forms field data.
     *
     * @param  array<int|string, mixed>  $fieldData  The Formidable Forms field data
     * @return array<int|string, mixed>|null The conditional logic data or null if not present
     */
    protected static function extractConditionalLogicData(
        array $fieldData
    ): ?array {
        // Check if conditional logic is present in field_options
        if (isset($fieldData['field_options']) && \is_string($fieldData['field_options'])) {
            $fieldOptions = \json_decode(
                $fieldData['field_options'],
                true
            );

            if (\is_array($fieldOptions) && static::hasConditionalLogic($fieldOptions)) {
                return $fieldOptions;
            }
        }

        return null;
    }

    /**
     * Checks if the field options contain conditional logic configuration.
     *
     * @param  array<int|string, mixed>  $fieldOptions  The decoded field_options array
     * @return bool True if conditional logic is present
     */
    protected static function hasConditionalLogic(
        array $fieldOptions
    ): bool {
        $hideField = $fieldOptions['hide_field'] ?? null;

        return $hideField !== null
            && (
                \is_array($hideField)
                || $hideField === true
                || $hideField === '1'
                || $hideField === 1
            );
    }

    /**
     * Maps Formidable Forms conditional logic to Statamic conditional logic
     * attributes.
     *
     * This method transforms Formidable Forms conditional logic configuration into
     * Statamic's conditional logic format. It handles the conversion of:
     *   - show_hide: "show" | "hide" → if/unless attributes
     *   - any_all: "all" | "any" → if/if_any/unless/unless_any attributes
     *   - hide_field: array of field IDs → field handles
     *   - hide_field_cond: array of operators → condition strings
     *   - hide_opt: array of values → condition values
     *
     * Formidable Forms stores conditional logic in the field_options JSON with these
     * key properties:
     *   - show_hide: "show" or "hide" (action type, defaults to "show")
     *   - any_all: "all" or "any" (logic type, defaults to "any", as in Formidable Pro)
     *   - hide_field: Array of field IDs to check
     *   - hide_field_cond: Array of operators (==, !=, >, <, LIKE)
     *   - hide_opt: Array of values to compare against
     *
     * The method maps these to Statamic's conditional logic format:
     *   - if: Show when all conditions are met
     *   - if_any: Show when any condition is met
     *   - unless: Hide when all conditions are met
     *   - unless_any: Hide when any condition is met
     *
     * Example Formidable Forms field_options:
     *   {"show_hide":"show","any_all":"all","hide_field":["915"],"hide_opt":["red"]}
     *
     * Maps to Statamic:
     *   {"if": {"target_radio_field_1_915": "equals red"}}
     *
     * @param  array<int|string, mixed>  $fieldOptions  The Formidable Forms field_options array
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return array<int|string, mixed> Statamic conditional logic attributes
     */
    public static function mapConditionalLogic(
        $fieldOptions,
        array $allFields = []
    ): array {
        // Extract action type (show/hide) with fallback to 'show'
        $actionType = \is_string($fieldOptions['show_hide'] ?? null)
            ? $fieldOptions['show_hide']
            : 'show';

        // Extract logic type (all/any) with fallback to 'any', Formidable Pro's default, when not specified
        $logicType = \is_string($fieldOptions['any_all'] ?? null)
            ? $fieldOptions['any_all']
            : 'any';

        // Extract field IDs array
        $fieldIds = \is_array($fieldOptions['hide_field'] ?? null)
            ? $fieldOptions['hide_field']
            : [];

        // Extract operators array
        $operators = \is_array($fieldOptions['hide_field_cond'] ?? null)
            ? $fieldOptions['hide_field_cond']
            : [];

        // Extract values array
        $values = \is_array($fieldOptions['hide_opt'] ?? null)
            ? $fieldOptions['hide_opt']
            : [];

        // If no field IDs, return empty array
        if ($fieldIds === []) {
            // Without the return the loop below runs no condition, and the empty
            // condition list returns the same empty array.
            // @pest-mutate-ignore: RemoveEarlyReturn
            return [];
        }

        // Initialize array to store Statamic conditional logic
        $statamicConditions = [];

        // Process each condition
        foreach ($fieldIds as $index => $fieldId) {
            // Skip conditions that do not provide a scalar field ID.
            if (! \is_scalar($fieldId)) {
                continue;
            }

            // Normalize the field ID to a string for downstream lookups.
            $fieldIdString = (string) $fieldId;

            // Skip empty field IDs.
            if ($fieldIdString === '') {
                continue;
            }

            // Get operator for this condition (fallback to '==' if not set)
            $operator = \is_scalar($operators[$index] ?? null)
                ? (string) $operators[$index]
                : '==';

            // Get value for this condition (fallback to empty string if not set)
            $value = \is_scalar($values[$index] ?? null)
                ? (string) $values[$index]
                : '';

            // Find the field handle by field ID for cross-reference
            $fieldHandle = static::getFieldHandleById(
                $fieldIdString,
                $allFields
            );

            // Skip this condition if field handle not found
            if (
                $fieldHandle === null
                || (
                    // A handle always ends in "_" and the field id, so it is never empty
                    // @pest-mutate-ignore: EmptyStringToNotEmpty
                    $fieldHandle === ''
                )
            ) {
                continue;
            }

            // Convert Formidable Forms operator to Statamic condition string
            $conditionString = static::convertOperatorToStatamicCondition(
                $operator,
                $value
            );

            // Skip this condition if operator is not supported
            if ($conditionString === null) {
                continue;
            }

            // Add the condition to the Statamic conditions array
            $statamicConditions[$fieldHandle] = $conditionString;
        }

        // Determine the appropriate Statamic conditional attribute based on action and logic type
        $statamicAttribute = static::getStatamicConditionalAttribute(
            $actionType,
            $logicType
        );

        // Return empty array if no valid attribute or no conditions
        if (
            $statamicAttribute === null
            || (
                // getStatamicConditionalAttribute() answers a non-empty attribute or null
                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $statamicAttribute === ''
            )
            || $statamicConditions === []
        ) {
            return [];
        }

        // Return the conditional logic in Statamic format
        return [$statamicAttribute => $statamicConditions];
    }

    /**
     * Maps Formidable Forms action conditional logic to Statamic conditional logic
     * attributes.
     *
     * This method handles the nested structure used in action conditions
     * (confirmations and email notifications), which is different from field-level
     * conditional logic.
     *
     * @param  array<int|string, mixed>  $conditions  The action conditions array
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return array<int|string, mixed> Statamic conditional logic attributes
     */
    public static function mapActionConditionalLogic(
        array $conditions,
        array $allFields = []
    ): array {
        // Extract the action type, falling back to 'show'.
        //
        // `show_hide` is the FIELD-level key and is absent on every corpus email
        // action. An ACTION's directive lives in `send_stop` (`send` / `stop`), so
        // without reading it a "send unless" rule imported as "send when" — the
        // notification firing exactly when it should be suppressed. That inversion
        // was inert while this mapping only ran on the discarded processEmailView()
        // path; wiring it into the persisted path (wordpress-importer-archive#38) makes it shippable, so read
        // the key that actually governs an action. wordpress-importer-archive#39
        $actionType = 'show';
        if (\is_string($conditions['show_hide'] ?? null)) {
            $actionType = $conditions['show_hide'];
        } elseif (\is_string($conditions['send_stop'] ?? null)) {
            $actionType = $conditions['send_stop'] === 'stop' ? 'hide' : 'show';
        }

        // Extract logic type (all/any) with fallback to 'any', Formidable Pro's default, when not specified
        $logicType = \is_string($conditions['any_all'] ?? null)
            ? $conditions['any_all']
            : 'any';

        // Initialize array to store Statamic conditional logic
        $statamicConditions = [];

        // Process each condition - handle nested structure
        foreach ($conditions as $key => $value) {
            // Skip non-numeric keys (like 'send_stop', 'any_all')
            if (! \is_numeric($key) || ! \is_array($value)) {
                continue;
            }

            // Extract condition data from the nested structure
            $fieldId = $value['hide_field'] ?? null;
            $operator = \is_scalar($value['hide_field_cond'] ?? null)
                ? (string) $value['hide_field_cond']
                : '==';
            $conditionValue = \is_scalar($value['hide_opt'] ?? null)
                ? (string) $value['hide_opt']
                : '';

            // Skip if no field ID
            if (! \is_scalar($fieldId)) {
                continue;
            }

            // Normalize scalar field IDs to strings and skip empties.
            $fieldIdString = (string) $fieldId;
            if ($fieldIdString === '') {
                continue;
            }

            // Find the field handle by field ID for cross-reference
            $fieldHandle = static::getFieldHandleById(
                $fieldIdString,
                $allFields
            );

            // Skip this condition if field handle not found
            if (
                $fieldHandle === null
                || (
                    // A handle always ends in "_" and the field id, so it is never empty
                    // @pest-mutate-ignore: EmptyStringToNotEmpty
                    $fieldHandle === ''
                )
            ) {
                continue;
            }

            // Convert Formidable Forms operator to Statamic condition string
            $conditionString = static::convertOperatorToStatamicCondition(
                $operator,
                $conditionValue
            );

            // Skip this condition if operator is not supported
            if ($conditionString === null) {
                continue;
            }

            // Add the condition to the Statamic conditions array
            $statamicConditions[$fieldHandle] = $conditionString;
        }

        // If no conditions were processed, return empty array
        if ($statamicConditions === []) {
            return [];
        }

        // Determine the appropriate Statamic conditional attribute based on action and logic type
        $statamicAttribute = static::getStatamicConditionalAttribute(
            $actionType,
            $logicType
        );

        // Return empty array if no valid attribute
        if (
            $statamicAttribute === null
            || (
                // getStatamicConditionalAttribute() answers a non-empty attribute or null
                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $statamicAttribute === ''
            )
        ) {
            return [];
        }

        // Return the conditional logic in Statamic format
        return [$statamicAttribute => $statamicConditions];
    }

    /**
     * Gets the Statamic conditional attribute based on action type and logic type.
     *
     * This method maps Formidable Forms action and logic types to Statamic
     * conditional logic attributes.
     *
     * @param  string  $actionType  The action type ('show' or 'hide')
     * @param  string  $logicType  The logic type ('all' or 'any')
     * @return ?string The Statamic conditional attribute or null if not supported
     */
    protected static function getStatamicConditionalAttribute(
        string $actionType,
        string $logicType
    ): ?string {
        // Map Formidable Forms action and logic types to Statamic conditional attributes
        return match ($actionType) {
            'show' => $logicType === 'any' ? 'if_any' : 'if',
            'hide' => $logicType === 'any' ? 'unless_any' : 'unless',
            default => null,
        };
    }

    /**
     * Gets the field name/label from a Formidable Forms field array.
     *
     * @param  array<int|string, mixed>  $field  The Formidable Forms field array
     * @return ?string The field name or null if not found
     */
    protected static function getFieldNameFromField(
        array $field
    ): ?string {
        $fieldName = $field['name'] ?? null;

        if (! \is_string($fieldName) || $fieldName === '') {
            return null;
        }

        return $fieldName;
    }

    /**
     * Converts Formidable Forms operator and value to Statamic condition string.
     *
     * This method maps Formidable Forms operators to Statamic condition strings:
     *   - == (equals): "equals {value}" or "equals empty" for empty values
     *   - != (not equals): "not {value}" or "not empty" for empty values
     *   - > (greater than): "> {value}"
     *   - >= (greater than or equal to): ">= {value}"
     *   - < (less than): "< {value}"
     *   - <= (less than or equal to): "<= {value}"
     *   - LIKE (contains): "contains {value}"
     *
     * These Formidable Forms operators are not supported in Statamic, but are also
     * not used in any active UAMS forms:
     *   - not LIKE (does not contain)
     *   - LIKE% (starts with)
     *   - %LIKE (ends with)
     *
     * @param  string  $operator  The Formidable Forms operator
     * @param  string  $value  The condition value
     * @return ?string The Statamic condition string or null if not supported
     */
    protected static function convertOperatorToStatamicCondition(
        string $operator,
        string $value
    ): ?string {
        // Convert Formidable Forms operators to Statamic condition strings
        return match ($operator) {
            '==' => $value === '' ? 'equals empty' : "equals {$value}",
            '!=' => $value === '' ? 'not empty' : "not {$value}",
            '>' => "> {$value}",
            '>=' => ">= {$value}",
            '<' => "< {$value}",
            '<=' => "<= {$value}",
            'LIKE' => "contains {$value}",
            'not LIKE' => null,
            'LIKE%' => null,
            '%LIKE' => null,
            default => null,
        };
    }
}
