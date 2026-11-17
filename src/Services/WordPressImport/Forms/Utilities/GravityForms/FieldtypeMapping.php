<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use UAMSWeb\Aether\WordPressImporter\Data\Forms\GravityForms\FieldtypeMappingData;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Provides mapping from Gravity Forms field types to Statamic fieldtypes.
 *
 * This class is responsible for:
 *   - Maintaining a mapping array of Gravity Forms field types to Statamic
 *     fieldtypes
 *   - Providing lookup methods for mapping
 *   - Identifying unmapped Gravity Forms fieldtypes in a forms export
 */
class FieldtypeMapping
{
    use FieldtypeMappingTrait;

    /**
     * Gravity Forms `product` input types the Statamic product fieldtype cannot express.
     *
     * `App\Fieldtypes\Product` offers a single base-priced item or a chooser of priced
     * options. Hidden, User Defined Price and Calculation products are none of those,
     * so each is reported rather than importing as a field with no selection type.
     * Corpus counts: `hiddenproduct` 2, `price` 1, `calculation` 0 — 3 of 309 product
     * fields (issue wordpress-importer-archive#299).
     *
     * @var list<string>
     */
    private const array UNSUPPORTED_PRODUCT_INPUT_TYPES = [
        'calculation',
        'hiddenproduct',
        'price',
    ];

    /**
     * Mapping from Gravity Forms field types to Statamic fieldtypes.
     *
     * Held in {@see FieldtypeMappingData::MAPPING}, which documents its shape.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $mapping = FieldtypeMappingData::MAPPING;

    /**
     * Maps Gravity Forms field attributes to Statamic field attributes.
     *
     * Held in {@see FieldtypeMappingData::FIELD_ATTRIBUTE_MAPPING}, which documents its shape.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeMapping = FieldtypeMappingData::FIELD_ATTRIBUTE_MAPPING;

    /**
     * Maps Gravity Forms subfield labels to Statamic subfield handles with
     * hierarchical attribute inheritance.
     *
     * Held in {@see FieldtypeMappingData::SUBFIELD_MAPPING}, which documents its shape.
     *
     * @var array<int|string, mixed>
     */
    protected static array $subfieldMapping = FieldtypeMappingData::SUBFIELD_MAPPING;

    /**
     * Add a warning for a Gravity Forms product mode Statamic cannot express.
     *
     * `GF_Field_Product` has six input-type variants. `singleproduct` maps to the
     * Statamic fieldtype's `single` selection type (its default) and `select` /
     * `radio` map to `multiple`. The remaining three — `hiddenproduct` (Hidden),
     * `price` (User Defined Price) and `calculation` (Calculation) — have no analog
     * in `App\Fieldtypes\Product`, so `inputType` resolves to a null
     * `selection_type` and the field imports with no selection behavior at all.
     *
     * Extending the fieldtype for them is not proportionate — they are 3 fields out
     * of 309 in the export corpus — and a silent documented fallback would hide
     * them, so each one is reported by name instead (issue wordpress-importer-archive#299).
     *
     * @param  array<int|string, mixed>  $field  The Gravity Forms field data
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     */
    public static function addUnsupportedProductModeWarning(
        array $field,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        // Only product fields carry a product mode
        if (($field['type'] ?? null) !== 'product') {
            return;
        }

        $inputType = $field['inputType'] ?? null;

        // A product field with no inputType is a Single Product, the default
        if (! \is_string($inputType)) {
            // Without the return the strict lookup below finds no text value among the
            // unsupported modes, and returns the same way.
            // @pest-mutate-ignore: RemoveEarlyReturn
            return;
        }

        // Anything the fieldtype can express needs no warning
        if (
            ! \in_array(
                $inputType,
                self::UNSUPPORTED_PRODUCT_INPUT_TYPES,
                // The value is text here and no unsupported mode is numeric, so a loose
                // comparison answers the same as a strict one.
                // @pest-mutate-ignore: TrueToFalse
                true
            )
        ) {
            return;
        }

        // Identify the field precisely so the migrating team can find it
        $fieldId = isset($field['id']) && \is_scalar($field['id'])
            ? (string) $field['id']
            : '?';

        ImportWarningManager::addWarning(
            'forms',
            'unsupported_product_modes',
            [
                'plugin' => 'Gravity Forms',
                'form_title' => $formTitle,
                'form_id' => $formId,
                'field_id' => $fieldId,
                'field_label' => $fieldLabel,
                'input_type' => $inputType,
                'reason' => \sprintf(
                    'The Gravity Forms "%s" product mode has no analog in the Statamic '
                        .'product fieldtype, so this field imports without a selection '
                        .'type and needs a manual port.',
                    $inputType
                ),
            ]
        );
    }

    /**
     * Get the Statamic fieldtype for a given Gravity Forms field type or field array.
     *
     * @param  string|array<int|string, mixed>  $gravityField  Either the field type string or the full field array
     * @return string|null|false Statamic fieldtype, null if not mapped, or false if suppressed
     */
    public static function getStatamicFieldtype(
        string|array $gravityField
    ): string|null|false {
        return static::resolveFieldtypeFromMapping(
            static::$mapping,
            $gravityField
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
     * Get the subfield mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The subfield mapping array
     */
    public static function getSubfieldMapping(): array
    {
        return static::$subfieldMapping;
    }

    /**
     * Extracts conditional logic data from Gravity Forms field data.
     *
     * @param  array<int|string, mixed>  $fieldData  The Gravity Forms field data
     * @return array<int|string, mixed>|null The conditional logic data or null if not present
     */
    protected static function extractConditionalLogicData(
        array $fieldData
    ): ?array {
        // Check if conditional logic is present in the field data
        if (isset($fieldData['conditionalLogic'])) {
            // Handle JSON string format
            if (\is_string($fieldData['conditionalLogic'])) {
                $decoded = \json_decode(
                    $fieldData['conditionalLogic'],
                    true
                );
                if (\is_array($decoded)) {
                    return $decoded;
                }
            }

            // Handle array format
            if (\is_array($fieldData['conditionalLogic'])) {
                return $fieldData['conditionalLogic'];
            }
        }

        return null;
    }

    /**
     * Maps Gravity Forms conditional logic to Statamic conditional logic attributes.
     *
     * This method transforms Gravity Forms conditional logic configuration into
     * Statamic's conditional logic format. It handles the conversion of:
     *   - actionType: "show" | "hide" → if/unless attributes
     *   - logicType: "all" | "any" → if/if_any/unless/unless_any attributes
     *   - rules: array of conditions → field handle and condition string
     *
     * @param  array<int|string, mixed>|null  $conditionalLogic  The Gravity Forms conditional logic object
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return array<int|string, mixed> Statamic conditional logic attributes
     */
    public static function mapConditionalLogic(
        $conditionalLogic,
        array $allFields = []
    ): array {
        // If no conditional logic, return empty array. Past this guard, a value that is
        // not a table reads no key and an empty table has no rules, so the rules check
        // below returns the same empty array.
        // @pest-mutate-ignore: BooleanOrToBooleanAnd,RemoveEarlyReturn
        if (! \is_array($conditionalLogic) || $conditionalLogic === []) {
            return [];
        }

        // Check if conditional logic is enabled (if the property exists)
        if (isset($conditionalLogic['enabled']) && $conditionalLogic['enabled'] === false) {
            return [];
        }

        // Extract action type (show/hide) with fallback to 'show'
        $actionTypeRaw = $conditionalLogic['actionType'] ?? 'show';
        $actionType = \is_string($actionTypeRaw) ? $actionTypeRaw : 'show';

        // Extract logic type (all/any) with fallback to 'all'
        $logicTypeRaw = $conditionalLogic['logicType'] ?? 'all';
        $logicType = \is_string($logicTypeRaw) ? $logicTypeRaw : 'all';

        // Extract rules array with fallback to empty array
        $rules = $conditionalLogic['rules'] ?? [];

        // If no rules, return empty array
        if (! \is_array($rules) || $rules === []) {
            return [];
        }

        // Initialize array to store Statamic conditional logic
        $statamicConditions = [];

        // Process each rule in the conditional logic
        foreach ($rules as $rule) {
            if (! \is_array($rule)) {
                continue;
            }

            // Extract field ID from the rule. A Gravity Forms field id is a number, so
            // neither the empty fallback nor any other text names a field.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $fieldIdRaw = $rule['fieldId'] ?? '';
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $fieldId = \is_scalar($fieldIdRaw) ? (string) $fieldIdRaw : '';

            // Extract operator from the rule. The empty fallback is no operator, and
            // neither is any other text the converter does not name.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $operatorRaw = $rule['operator'] ?? '';
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $operator = \is_string($operatorRaw) ? $operatorRaw : '';

            // Extract value from the rule
            $valueRaw = $rule['value'] ?? '';
            $value = \is_scalar($valueRaw) ? (string) $valueRaw : '';

            // Find the field handle by field ID for cross-reference
            $fieldHandle = static::getFieldHandleById(
                $fieldId,
                $allFields
            );

            // Skip this rule if field handle not found
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

            // Convert Gravity Forms operator to Statamic condition string
            $conditionString = static::convertOperatorToStatamicCondition(
                $operator,
                $value
            );

            // Skip this rule if operator is not supported
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
     * Gets the field name/label from a Gravity Forms field array.
     *
     * @param  array<int|string, mixed>  $field  The Gravity Forms field array
     * @return ?string The field label or null if not found
     */
    protected static function getFieldNameFromField(
        array $field
    ): ?string {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : null;
    }

    /**
     * Converts Gravity Forms operator and value to Statamic condition string.
     *
     * @param  string  $operator  The Gravity Forms operator
     * @param  string  $value  The condition value
     * @return ?string The Statamic condition string or null if not supported
     */
    protected static function convertOperatorToStatamicCondition(
        string $operator,
        string $value
    ): ?string {
        // Convert Gravity Forms operators to Statamic condition strings
        return match ($operator) {
            'is' => $value === '' ? 'equals empty' : "equals {$value}",
            'isnot' => $value === '' ? 'not empty' : "not {$value}",
            '>' => "> {$value}",
            '<' => "< {$value}",
            'contains' => "contains {$value}",
            default => null,
        };
    }
}
