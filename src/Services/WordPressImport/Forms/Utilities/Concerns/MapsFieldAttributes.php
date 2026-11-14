<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Maps field attributes and field options through the mapping configuration, and resolves
 * the fieldtype-specific configuration of an attribute.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait MapsFieldAttributes
{
    /**
     * Get fieldtype-specific configuration from a mapping configuration.
     *
     * This method handles the new configuration structure where each property can
     * have both a default value and fieldtype-specific overrides.
     *
     * @param  array<int|string, mixed>  $attrConfig  The attribute configuration
     * @param  ?string  $fieldtype  The current fieldtype (null for default)
     * @return array<int|string, mixed> The resolved configuration for the current fieldtype
     */
    protected static function getFieldtypeSpecificConfig(
        array $attrConfig,
        ?string $fieldtype = null,
        int|string|null $attrName = null
    ): array {
        // Initialize the config array
        $config = [];

        // Define the properties that support fieldtype-specific overrides
        $properties = [
            'statamic_attribute',
            'key_translation',
            'value_translation',
            'value_substitution_regex',
            'value_calculation',
            'value_transformations',
            'exclude_fieldtypes',
            'value_prepend',
            'collapse_whitespace',
            'as_array',
            'convert_file_size',
            'as_integer',
            'as_boolean',
            'invert_boolean',
            'convert_dollar_amount',
            'alert_unsupported',
            'check_against_list',
            'statamic_other_field_value',
            'statamic_other_field_value_based_on_attribute_value',
            'statamic_other_field_value_based_on_nested_attribute_value',
            'statamic_type_based_on_attribute_value',
            'statamic_form_config',
            'transform_json_to_array',
            'separate_value_field_option',
            'translate_field_references',
            'translate_field_references_calculation',
            'combine_value_with_other_attribute_value',
            'explode_string',
            'trim',
            'trim_characters',
            'preserve_newlines',
            'unescape_slashes',
            'choices_as_fields',
            'extract_array_value',
            'extract_object_values',
            'expected_keys',
            'deduplicate_values',
            'get_selected_choice',
        ];

        // Process each property that supports fieldtype-specific overrides
        foreach ($properties as $property) {
            // Skip properties that don't exist in the attribute configuration
            if (! isset($attrConfig[$property])) {
                continue;
            }

            // Get the property configuration for processing
            $propertyConfig = $attrConfig[$property];

            // Initialize flag to track if we've found a matching configuration
            $matchFound = false;

            // Define all possible "new structure" keys
            $newStructureKeys = [
                'based_on_other_attribute_value',
                'specific_based_on_attribute_value',
                'based_on_attribute_value',
                'specific',
                'default',
            ];

            // Check if this is a new structure (has any of the new structure keys)
            $isNewStructure = \is_array($propertyConfig)
                && array_intersect_key(
                    $propertyConfig,
                    array_flip($newStructureKeys)
                ) !== [];

            // Handle properties that need to preserve their entire structure
            if (
                $isNewStructure
            ) {
                // Define properties that always require their complete structure to be preserved
                $preserveStructureProperties = [
                    'statamic_other_field_value_based_on_attribute_value',
                    'statamic_other_field_value_based_on_nested_attribute_value',
                ];

                // Preserve the entire configuration for these special properties
                if (
                    \in_array($property, $preserveStructureProperties, true)
                ) {
                    $config[$property] = $propertyConfig;
                    $matchFound = true;
                }
            }

            // Handle properties with complex nested structures that need preservation
            if (
                $isNewStructure
                && ! $matchFound
            ) {
                // Define keys that indicate complex nested structures requiring full preservation
                $complexStructureKeys = [
                    'based_on_other_attribute_value',
                    'specific_based_on_attribute_value',
                    'based_on_attribute_value',
                ];

                // Check if the property has any complex structure keys and preserve the entire config
                foreach ($complexStructureKeys as $complexKey) {
                    if (isset($propertyConfig[$complexKey])) {
                        // Preserve the entire property configuration since it contains complex nested structures
                        $config[$property] = $propertyConfig;

                        // Mark that we've found a matching configuration
                        $matchFound = true;

                        // Exit the loop since we found a complex structure to preserve
                        break;
                    }
                }
            }

            // Handle fieldtype-specific override (if not already processed)
            if (
                $isNewStructure
                && isset($propertyConfig['specific'])
                && $fieldtype !== null
                && $fieldtype !== ''
                && self::arrayKeyExists(
                    $fieldtype,
                    $propertyConfig['specific']
                )
                && ! $matchFound
            ) {
                // Use the fieldtype-specific configuration value
                $specificConfig = self::asArray(
                    $propertyConfig['specific']
                );
                $config[$property] = self::mappingValue(
                    $specificConfig,
                    $fieldtype
                );

                // Mark that we've found a matching configuration
                $matchFound = true;
            }

            // Handle default value (if not already processed)
            if (
                $isNewStructure
                && isset($propertyConfig['default'])
                && ! $matchFound
            ) {
                // Use the default configuration value
                $config[$property] = $propertyConfig['default'];

                // Mark that we've found a matching configuration
                $matchFound = true;
            }

            // Legacy structure: direct value (if not already processed)
            if (! $matchFound) {
                $config[$property] = $propertyConfig;
            }
        }

        // Return the processed configuration with fieldtype-specific overrides applied
        return $config;
    }

    /**
     * Map field attributes from WordPress form plugin to Statamic field attributes.
     *
     * Uses the fieldAttributeMapping array to transform field attributes from
     * WordPress form plugins (Gravity Forms, Formidable Forms) to Statamic
     * field attributes.
     *
     * @param  array<int|string, mixed>  $fieldData  The WordPress form field data
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for conditional logic mapping
     * @param  ?string  $statamicFieldtype  Optional Statamic fieldtype handle (not used for exclude_fieldtypes)
     * @param  ?string  $siteHandle  Optional site handle for placeholder replacement in mapped values
     * @return array<int|string, mixed> The mapped Statamic field attributes
     */
    public static function mapFieldAttributes(
        array $fieldData,
        array $allFields = [],
        ?string $statamicFieldtype = null,
        ?string $siteHandle = null
    ): array {
        // Use the source fieldtype for exclude_fieldtypes logic
        $excludeFieldtype = self::stringValue(
            $fieldData['type'] ?? null
        );

        // Check if fieldAttributeMapping is defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'fieldAttributeMapping'
            )
        ) {
            // Return empty array since no mappings are available
            return [];
        }

        // Get standard attribute mappings from trait
        $mappedAttributes = static::mapAttributesFromMapping(
            $fieldData,
            static::$fieldAttributeMapping,
            $allFields,
            null,
            $excludeFieldtype,
            null,
            $siteHandle
        );

        // Handle conditional logic mapping
        $conditionalLogicData = static::extractConditionalLogicData($fieldData);

        if ($conditionalLogicData !== null) {
            // Transform conditional logic to Statamic format
            $conditionalLogic = static::mapConditionalLogic(
                $conditionalLogicData,
                $allFields
            );

            // Merge conditional logic attributes with other mapped attributes
            $mappedAttributes = \array_merge(
                $mappedAttributes,
                $conditionalLogic
            );
        }

        // Filter out null values from mapped attributes (consistent with mapFieldOptions)
        $mappedAttributes = \array_filter(
            $mappedAttributes,
            fn ($value): bool => $value !== null
        );

        // Return all mapped field attributes including conditional logic
        return $mappedAttributes;
    }

    /**
     * Map field options from WordPress form plugin to Statamic field attributes.
     *
     * Uses the fieldAttributeOptionsMapping array to transform field options from
     * WordPress form plugins (Gravity Forms, Formidable Forms) to Statamic
     * field attributes.
     *
     * @param  array<int|string, mixed>  $fieldData  The WordPress form field data
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for conditional logic mapping
     * @param  ?string  $statamicFieldtype  Optional Statamic fieldtype handle
     * @param  ?string  $siteHandle  Optional site handle for placeholder replacement
     * @return array<int|string, mixed> The mapped Statamic field attributes
     */
    public static function mapFieldOptions(
        array $fieldData,
        array $allFields = [],
        ?string $statamicFieldtype = null,
        ?string $siteHandle = null
    ): array {
        // Use the source fieldtype for exclude_fieldtypes logic
        $excludeFieldtype = self::stringValue(
            $fieldData['type'] ?? null
        );

        // Check if fieldAttributeOptionsMapping is defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'fieldAttributeOptionsMapping'
            )
        ) {
            // Return empty array since no mappings are available
            return [];
        }

        // Parse field_options JSON if it exists
        $parsedFieldData = $fieldData;
        if (
            isset($fieldData['field_options'])
            && \is_string($fieldData['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $fieldData['field_options'],
                true
            );
            if (\is_array($fieldOptions)) {
                // Merge parsed field_options with the original field data
                $parsedFieldData = \array_merge(
                    $fieldData,
                    $fieldOptions
                );
            }
        }

        // Get standard attribute mappings from trait using parsed data
        $fieldAttributeOptionsMapping = self::resolveFieldAttributeOptionsMapping();
        $mappedAttributes = static::mapAttributesFromMapping(
            $parsedFieldData,
            $fieldAttributeOptionsMapping,
            $allFields,
            null,
            $excludeFieldtype,
            $fieldData,
            $siteHandle
        );

        // Handle conditional logic mapping
        $conditionalLogicData = static::extractConditionalLogicData($fieldData);

        if ($conditionalLogicData !== null) {
            // Transform conditional logic to Statamic format
            $conditionalLogic = static::mapConditionalLogic(
                $conditionalLogicData,
                $allFields
            );

            // Merge conditional logic attributes with other mapped attributes
            $mappedAttributes = \array_merge(
                $mappedAttributes,
                $conditionalLogic
            );
        }

        // Filter out null values from mapped attributes (consistent with mapFieldAttributes)
        $mappedAttributes = \array_filter(
            $mappedAttributes,
            fn ($value): bool => $value !== null
        );

        // Return all mapped field options attributes including conditional logic
        return $mappedAttributes;
    }

    /**
     * Get all field attributes that have mappings defined.
     *
     * @return array<int|string, mixed> Array of field attribute names that have mappings
     */
    public static function getMappedFieldAttributes(): array
    {
        // Check if fieldAttributeMapping is defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'fieldAttributeMapping'
            )
        ) {
            return [];
        }

        // Initialize array to collect mapped attribute names
        $mappedAttributes = [];

        // Iterate through all defined field attribute mappings
        foreach (static::$fieldAttributeMapping as $attributeName => $mapping) {
            // Check if this attribute has a valid Statamic attribute mapping
            if (
                isset($mapping['statamic_attribute'])

            ) {
                // Add the attribute name to our list of mapped attributes
                $mappedAttributes[] = $attributeName;
            }
        }

        // Return the list of field attributes that have mappings
        return $mappedAttributes;
    }

    /**
     * Whether an unsupported attribute is worth reporting to the operator.
     *
     * **An attribute sitting at its default carries no operator-actionable information (wordpress-importer-archive#1157).**
     * The checklist exists to name manual work; an attribute whose value is what every form has
     * anyway names none, and five such notices in one afternoon were carried into a migration
     * checklist before anyone examined the values.
     *
     * Three gates, in order:
     *
     * - `alert_unsupported => false` suppresses at any value, for an attribute with no runtime
     *   meaning to carry across — `duplicatable` describes whether the Gravity Forms editor offers
     *   a duplicate control while building the form, and says nothing about the rendered form.
     * - `alert_unsupported_default` suppresses only while the value equals that default, so an
     *   attribute that matters *when set* keeps its warning for exactly the case that needs it.
     *   `is_payment` is `false` on all 214 fields measured, and a payment field is a genuine
     *   migration concern Statamic has no analog for — suppressing it outright would remove the
     *   warning for the one estate state that would need it.
     * - An empty value is never reported, which is the pre-existing behavior.
     *
     * **`false` is not empty here, and that is why `is_payment` was reported at all.** The emptiness
     * test is `null`/`''`/`[]`, and a strict comparison passes `false` through all three — so a
     * boolean attribute that is false everywhere warned on every field of every form.
     *
     * @param  array<int|string, mixed>  $mapping
     */
    protected static function shouldAlertUnsupportedAttribute(
        array $mapping,
        mixed $attributeValue
    ): bool {
        // Explicitly suppressed at any value.
        if (($mapping['alert_unsupported'] ?? true) === false) {
            return false;
        }

        // Suppressed while the attribute sits at the default the estate universally carries.
        if (
            \array_key_exists('alert_unsupported_default', $mapping)
            && $attributeValue === $mapping['alert_unsupported_default']
        ) {
            return false;
        }

        // Pre-existing behavior: an absent or empty value is not a finding.
        return ! in_array($attributeValue, [null, '', []], true);
    }

    /**
     * Map field attributes from WordPress form plugin to Statamic field attributes
     * with context for warnings.
     *
     * This method extends the basic mapFieldAttributes method to include context
     * information for generating warnings about unsupported attributes.
     *
     * @param  array<int|string, mixed>  $fieldData  The WordPress form field data
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?int  $formId  The form ID for warning context
     * @param  ?string  $siteHandle  Optional site handle for placeholder replacement in mapped values
     * @return array<int|string, mixed> The mapped Statamic field attributes
     */
    public static function mapFieldAttributesWithContext(
        array $fieldData,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?int $formId = null,
        ?string $siteHandle = null
    ): array {
        // First, get the basic mapped attributes
        $mappedAttributes = self::mapFieldAttributes(
            $fieldData,
            [],
            null,
            $siteHandle
        );

        // If we don't have context information, just return the mapped attributes
        if (
            $formTitle === null
            || $fieldLabel === null
        ) {
            return $mappedAttributes;
        }

        // Check if fieldAttributeMapping is defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'fieldAttributeMapping'
            )
        ) {
            return $mappedAttributes;
        }

        // Only add unsupported attribute warnings for mapped fieldtypes

        // Get the field type from the field data
        $fieldType = self::stringValue($fieldData['type'] ?? null);

        // Get the full mapping array for the implementing class
        $mappingArray = static::getMapping();

        // Determine if this fieldtype is mapped using the new mapping structure
        $fieldTypeMapping = self::mappingConfig(
            $fieldType !== null
                ? self::mappingValue($mappingArray, $fieldType)
                : null
        );
        $isMapped = $fieldType !== null
            && $fieldType !== ''
            && self::arrayKeyExists($fieldType, $mappingArray)
            && self::isFieldtypeMapped($fieldTypeMapping);

        // If the fieldtype is not mapped, do not add unsupported attribute warnings
        if (! $isMapped) {
            return $mappedAttributes;
        }

        // Collect warnings for unsupported attributes
        foreach ($fieldData as $attributeName => $attributeValue) {
            // Skip if this attribute is not defined in our mapping
            if (! isset(static::$fieldAttributeMapping[$attributeName])) {
                // This attribute is not mapped at all - add warning
                if ((! in_array($attributeValue, [null, '', []], true))) {
                    ImportWarningManager::addWarning(
                        'forms',
                        'unsupported_attributes',
                        [
                            'form_title' => $formTitle,
                            'field_label' => $fieldLabel,
                            'attribute_name' => $attributeName,
                            'attribute_value' => $attributeValue,
                            'form_id' => $formId,
                            'field_type' => $fieldData['type'] ?? 'unknown',
                        ]
                    );
                }

                continue;
            }

            // Get the mapping configuration for this attribute
            $mapping = static::$fieldAttributeMapping[$attributeName];

            // Check if this attribute is mapped to null (unsupported)
            if (($mapping['statamic_attribute'] ?? null) === null) {
                if (self::shouldAlertUnsupportedAttribute($mapping, $attributeValue)) {
                    ImportWarningManager::addWarning(
                        'forms',
                        'unsupported_attributes',
                        [
                            'form_title' => $formTitle,
                            'field_label' => $fieldLabel,
                            'attribute_name' => $attributeName,
                            'attribute_value' => $attributeValue,
                            'form_id' => $formId,
                            'field_type' => $fieldData['type'] ?? 'unknown',
                        ]
                    );
                }
            }
        }

        // Filter out null values from mapped attributes
        $mappedAttributes = \array_filter(
            $mappedAttributes,
            fn ($value): bool => $value !== null
        );

        return $mappedAttributes;
    }
}
