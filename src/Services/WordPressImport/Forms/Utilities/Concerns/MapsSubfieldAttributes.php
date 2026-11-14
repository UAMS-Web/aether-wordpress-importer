<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Maps subfield attributes and conditional logic, and resolves field handles and names.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait MapsSubfieldAttributes
{
    /**
     * Process subfield attribute mappings to replace placeholders and handle
     * conditional logic.
     *
     * This method processes attribute mappings for subfields, supporting:
     *   - Simple string mappings with placeholder replacement
     *   - based_on_other_attribute_value patterns (e.g., based on addressType)
     *   - based_on_attribute_value patterns (e.g., based on current attribute value)
     *   - default values in array configurations
     *
     * @param  array<int|string, mixed>  $attributeMappings  The attribute mappings to process
     * @param  array<int|string, mixed>  $parentField  The parent field data for context
     * @param  string  $subfieldHandle  The subfield handle
     * @param  array<int|string, mixed>  $subfieldData  The subfield data
     * @param  ?string  $preset  The preset value
     * @return array<int|string, mixed> The processed attribute mappings
     */
    protected static function processSubfieldAttributeMappings(
        array $attributeMappings,
        array $parentField,
        string $subfieldHandle,
        array $subfieldData = [],
        ?string $preset = null
    ): array {
        $processedMappings = [];

        foreach ($attributeMappings as $attrName => $attrConfig) {
            $attrConfigArray = self::mappingConfig($attrConfig);
            $statamicAttribute = self::mappingValue(
                $attrConfigArray,
                'statamic_attribute'
            );
            if ($statamicAttribute !== null) {
                $processedConfig = $attrConfigArray;

                // Use helper method to process the statamic attribute
                $result = self::processSubfieldStatamicAttribute(
                    $statamicAttribute,
                    $attrName,
                    $parentField,
                    $subfieldHandle,
                    $subfieldData,
                    $preset
                );

                if ($result !== null) {
                    [$processedAttribute, $matchFound] = $result;
                    if ($matchFound === true) {
                        $processedConfig['statamic_attribute'] = $processedAttribute;
                        $processedMappings[$attrName] = $processedConfig;
                    } else { // Otherwise
                        // Keep the original config if no match was found
                        $processedMappings[$attrName] = $attrConfigArray;
                    }
                } else { // Otherwise
                    // Keep the original config if no processing was needed
                    $processedMappings[$attrName] = $attrConfigArray;
                }
            } else { // Otherwise
                // Keep the original config if no statamic_attribute to process
                $processedMappings[$attrName] = $attrConfigArray;
            }
        }

        return $processedMappings;
    }

    /**
     * Process a single statamic attribute configuration for subfield mapping.
     *
     * This helper method DRYs out the logic for processing statamic_attribute
     * configurations in subfield mappings, handling all the conditional patterns.
     *
     * @param  mixed  $statamicAttr  The statamic attribute configuration
     * @param  string  $attrName  The attribute name
     * @param  array<int|string, mixed>  $parentField  The parent field data
     * @param  string  $subfieldHandle  The subfield handle
     * @param  array<int|string, mixed>  $subfieldData  The subfield data for attribute value lookup
     * @param  ?string  $preset  The preset value
     * @return array<int|string, mixed>|null Returns [processed_attribute, match_found] or null if no processing needed
     */
    protected static function processSubfieldStatamicAttribute(
        mixed $statamicAttr,
        int|string|null $attrName,
        array $parentField,
        string $subfieldHandle,
        array $subfieldData = [],
        ?string $preset = null
    ): ?array {
        // Only process statamic_attribute if it's set and has a value
        if (
            $statamicAttr !== null
            && $statamicAttr !== ''
        ) {
            // Handle array-based configurations
            if (\is_array($statamicAttr)) {
                $statamicAttrArray = self::asArray($statamicAttr);

                // Handle based_on_other_attribute_value logic
                $basedOnOtherConfig = self::asArray(
                    self::mappingValue(
                        $statamicAttrArray,
                        'based_on_other_attribute_value'
                    )
                );
                if ($basedOnOtherConfig !== []) {
                    $addressTypeConfig = self::asArray(
                        self::mappingValue(
                            $basedOnOtherConfig,
                            'addressType'
                        )
                    );
                    $addressType = self::stringValue(
                        self::mappingValue(
                            $parentField,
                            'addressType'
                        )
                    );
                    $resolvedAttribute = $addressType !== null
                        ? self::mappingValue(
                            $addressTypeConfig,
                            $addressType
                        )
                        : null;

                    // If we resolved an attribute, use it
                    if ($resolvedAttribute !== null) {
                        return [
                            self::replacePlaceholders(
                                self::normalizeMixedToString($resolvedAttribute),
                                null,
                                $subfieldHandle,
                                $preset
                            ),
                            true,
                        ];
                    }
                }

                // Handle based_on_attribute_value logic
                $basedOnConfig = self::mappingValue(
                    $statamicAttrArray,
                    'based_on_attribute_value'
                );
                if ($basedOnConfig !== null) {
                    $normalizedAttrName = self::normalizeArrayKey($attrName);
                    $attrValue = $normalizedAttrName !== null
                        ? self::mappingValue(
                            $subfieldData,
                            $normalizedAttrName
                        )
                        : null;
                    $basedOnConfigArray = \is_array($basedOnConfig)
                        ? $basedOnConfig
                        : self::normalizeMixedToString($basedOnConfig);

                    // Use existing helper method to process based_on_attribute_value patterns
                    // This leverages the same logic used in the main field mapping for consistency
                    $result = self::processBasedOnAttributeValue(
                        $basedOnConfigArray,
                        null,
                        self::normalizeMixedToString($attrName),
                        $attrValue,
                        $parentField
                    );

                    if ($result !== null) {
                        [$resolvedAttribute, $matchFound] = $result;
                        if ($matchFound) {
                            // If resolvedAttribute is null, this means the attribute should be excluded
                            if ($resolvedAttribute === null) {
                                return null; // Exclude this attribute
                            }

                            return [
                                self::replacePlaceholders(
                                    self::normalizeMixedToString($resolvedAttribute),
                                    null,
                                    $subfieldHandle,
                                    $preset
                                ),
                                $matchFound,
                            ];
                        }
                    }

                    // If based_on_attribute_value didn't find a match, fall back to default
                    // This handles the case where based_on_attribute_value only has exclusion mappings
                    $defaultAttribute = self::mappingValue(
                        $statamicAttrArray,
                        'default'
                    );
                    if ($defaultAttribute !== null) {
                        return [
                            self::replacePlaceholders(
                                self::normalizeMixedToString($defaultAttribute),
                                null,
                                $subfieldHandle,
                                $preset
                            ),
                            true,
                        ];
                    }
                }

                // Handle default value (if not already processed)
                $defaultAttribute = self::mappingValue(
                    $statamicAttrArray,
                    'default'
                );
                if ($defaultAttribute !== null) {
                    return [
                        self::replacePlaceholders(
                            self::normalizeMixedToString($defaultAttribute),
                            null,
                            $subfieldHandle,
                            $preset
                        ),
                        true,
                    ];
                }
            }

            // Handle simple string statamic_attribute (if not already processed)
            if (\is_string($statamicAttr)) {
                return [
                    self::replacePlaceholders(
                        $statamicAttr,
                        null,
                        $subfieldHandle,
                        $preset
                    ),
                    true,
                ];
            }
        }

        return null;
    }

    /**
     * Maps Gravity Forms subfield attributes to Statamic field attributes.
     *
     * This method processes subfield data (like address field inputs) and maps
     * them to Statamic field configuration attributes.
     *
     * @param  string  $fieldType  The Gravity Forms field type
     * @param  array<int|string, mixed>  $subfieldData  The subfield data (e.g., from inputs array)
     * @param  array<int|string, mixed>  $parentField  The parent field data for context
     * @param  ?string  $pluginName  The plugin name for fieldtype resolution
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for context
     * @return array<int|string, mixed> The mapped Statamic subfield attributes
     */
    public static function mapSubfieldAttributes(
        string $fieldType,
        array $subfieldData,
        array $parentField = [],
        ?string $pluginName = null,
        array $allFields = []
    ): array {
        // Return early if subfield mapping is not defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'subfieldMapping'
            )
        ) {
            return [];
        }

        // Extract the subfield label from the input data for mapping lookup
        $label = self::stringValue(
            self::mappingValue(
                $subfieldData,
                'label'
            )
        );

        // Return early if label is missing from the subfield data
        if ($label === null || $label === '') {
            return [];
        }

        $subfieldMappingConfig = static::getSubfieldMapping();

        // Build the attribute mappings hierarchy from general to specific
        $attributeMappings = self::asArray(
            self::mappingValue(
                $subfieldMappingConfig,
                'attributes'
            )
        );
        $fieldtypesConfig = self::asArray(
            self::mappingValue(
                $subfieldMappingConfig,
                'fieldtypes'
            )
        );
        $fieldtypeConfig = self::asArray(
            self::mappingValue(
                $fieldtypesConfig,
                $fieldType
            )
        );

        // Merge with fieldtype-specific attributes (overrides general)
        if (
            self::arrayKeyExists(
                'attributes',
                $fieldtypeConfig
            )
        ) {
            $attributeMappings = \array_merge(
                $attributeMappings,
                self::asArray(
                    self::mappingValue(
                        $fieldtypeConfig,
                        'attributes'
                    )
                )
            );
        }

        // Merge with subfield-specific attributes (overrides fieldtype)
        $subfieldsConfig = self::asArray(
            self::mappingValue(
                $fieldtypeConfig,
                'subfields'
            )
        );
        $subfieldConfig = self::asArray(
            self::mappingValue(
                $subfieldsConfig,
                $label
            )
        );
        if (
            self::arrayKeyExists(
                'attributes',
                $subfieldConfig
            )
        ) {
            $attributeMappings = \array_merge(
                $attributeMappings,
                self::asArray(
                    self::mappingValue(
                        $subfieldConfig,
                        'attributes'
                    )
                )
            );
        }

        // Get the canonical handle from the mapping array for this subfield
        $mappingHandle = self::stringValue(
            self::mappingValue(
                $subfieldConfig,
                'handle'
            )
        );

        // Will hold the resolved Statamic handle for config keys
        $statamicHandle = null;

        // Get the mapping config for the current fieldtype
        // Use the field attribute mapping (not subfield mapping) for preset translation
        $fieldAttributeMapping = property_exists(
            static::class,
            'fieldAttributeMapping'
        )
            ? static::$fieldAttributeMapping
            : [];
        $statamicPreset = self::getStatamicPresetValue(
            $parentField,
            $fieldAttributeMapping
        );

        // Get the preset value for placeholder replacement (different from actual preset attribute)
        $presetForPlaceholder = self::getPresetValueForPlaceholder(
            $parentField,
            $fieldAttributeMapping
        );

        // Fallback to old logic if not found
        $preset = $statamicPreset ?? self::stringValue(
            self::mappingValue(
                $parentField,
                'preset'
            )
        ) ?? self::stringValue(
            self::mappingValue(
                $parentField,
                'addressType'
            )
        );
        $presetForPlaceholderString = self::stringValue($presetForPlaceholder);

        // Attempt to resolve the Statamic fieldtype class and use its helper to get the correct handle
        if ($pluginName !== null && $pluginName !== '') {
            // Resolve the Statamic fieldtype class for this field
            $fieldtypeClass = static::resolveStatamicFieldtypeClass(
                $fieldType,
                $pluginName
            );

            // If the class exists, instantiate and configure it
            if (
                $fieldtypeClass !== null
                && $fieldtypeClass !== ''
                && class_exists($fieldtypeClass)
            ) {
                $fieldtypeInstance = new $fieldtypeClass;

                // Set the preset config if available
                $config = [];
                if ($preset !== null && $preset !== '') {
                    $config['preset'] = $preset;
                }

                if (
                    method_exists(
                        $fieldtypeInstance,
                        'setConfig'
                    )
                ) {
                    $fieldtypeInstance->setConfig($config);
                }

                // Use the helper to get the preset-specific Statamic handle
                if (
                    method_exists(
                        $fieldtypeInstance,
                        'getPresetSpecificSubfieldHandle'
                    )
                    && $mappingHandle !== null
                ) {
                    $statamicHandle = self::stringValue(
                        $fieldtypeInstance->getPresetSpecificSubfieldHandle(
                            $mappingHandle,
                            $preset
                        )
                    );
                }
            }
        }

        // Fallback to the mapping handle if no Statamic handle was resolved
        if ($statamicHandle === null || $statamicHandle === '') {
            $statamicHandle = $mappingHandle;
        }

        $statamicHandle = self::stringValue($statamicHandle);

        // Return early if no handle or no attribute mappings are available
        if (
            $statamicHandle === null
            || $statamicHandle === ''
            || $attributeMappings === []
        ) {
            return [];
        }

        // Process the attribute mappings to replace placeholders
        $processedMappings = self::processSubfieldAttributeMappings(
            $attributeMappings,
            $parentField,
            $statamicHandle,
            $subfieldData,
            $presetForPlaceholderString
        );

        // Map the subfield attributes using the processed mappings
        // Combine subfield data with parent field data so that based_on_other_attribute_value can access both
        $combinedData = \array_merge(
            $subfieldData,
            $parentField
        );

        // Process attributes that exist in the source data
        $mapped = [];

        // First, process attributes from subfield data
        foreach ($subfieldData as $attrName => $attrValue) {
            $result = self::processAttributeIfValid(
                $attrName,
                $attrValue,
                $processedMappings,
                $combinedData,
                [],
                $statamicHandle,
                $presetForPlaceholderString
            );
            if ($result !== null) {
                $mapped = \array_merge(
                    $mapped,
                    $result
                );
            }
        }

        // Then, process attributes from parent field that weren't already processed
        foreach ($parentField as $attrName => $attrValue) {
            $result = self::processAttributeIfValid(
                $attrName,
                $attrValue,
                $processedMappings,
                $combinedData,
                $subfieldData,
                $statamicHandle,
                $presetForPlaceholderString
            );
            if ($result !== null) {
                $mapped = \array_merge(
                    $mapped,
                    $result
                );
            }
        }

        // Post-process the mapped result to replace placeholders in attribute names
        $postProcessPreset = $presetForPlaceholderString ?? self::stringValue(
            self::mappingValue(
                $parentField,
                'addressType'
            )
        );

        // Return the mapped subfield attributes
        return self::postProcessMappedAttributes(
            $mapped,
            $statamicHandle,
            $postProcessPreset
        );
    }

    /**
     * Gets the appropriate Statamic conditional attribute based on action and logic
     * type.
     *
     * This method maps WordPress form plugin action and logic types to Statamic
     * conditional attributes. It handles the conversion of:
     *   - actionType: "show" | "hide" → if/unless attributes
     *   - logicType: "all" | "any" → if/if_any/unless/unless_any attributes
     *
     * @param  string  $actionType  The WordPress form plugin action type ("show" or "hide")
     * @param  string  $logicType  The WordPress form plugin logic type ("all" or "any")
     * @return ?string The Statamic attribute name or null if not supported
     */
    protected static function getStatamicConditionalAttribute(
        string $actionType,
        string $logicType
    ): ?string {
        // Map WordPress form plugin action and logic types to Statamic conditional attributes
        return match ($actionType) {
            'show' => $logicType === 'any' ? 'if_any' : 'if',
            'hide' => $logicType === 'any' ? 'unless_any' : 'unless',
            default => null,
        };
    }

    /**
     * Maps conditional logic to Statamic conditional logic attributes.
     *
     * This is a template method that delegates to the implementing class's specific
     * conditional logic mapping implementation.
     *
     * @param  mixed  $conditionalLogicData  The conditional logic data (format depends on implementing class)
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return array<int|string, mixed> Statamic conditional logic attributes
     */
    protected static function mapConditionalLogic(
        $conditionalLogicData,
        array $allFields = []
    ): array {
        // This method should be overridden by implementing classes
        // that support conditional logic mapping
        return [];
    }

    /**
     * Gets the field handle for a given field ID.
     *
     * This method searches through all form fields to find a matching field ID
     * and converts the field's name/label to a Statamic handle format.
     *
     * @param  string  $fieldId  The field ID to search for
     * @param  array<int|string, mixed>  $allFields  Array of all form fields
     * @return ?string The field handle or null if not found
     */
    protected static function getFieldHandleById(
        string $fieldId,
        array $allFields = []
    ): ?string {
        // Search through all form fields to find matching field ID
        foreach ($allFields as $field) {
            $fieldData = self::asArray($field);
            $candidateFieldId = self::mappingValue($fieldData, 'id');

            // Check if field ID matches (convert to string for comparison)
            if (
                $candidateFieldId !== null
                && self::normalizeMixedToString($candidateFieldId) === $fieldId
            ) {
                // Get the field name/label using the implementing class's method
                $fieldName = static::getFieldNameFromField($fieldData);

                if ($fieldName !== null && $fieldName !== '') {
                    // Convert the name to a Statamic handle format using the implementing class's method
                    return static::convertFieldNameToHandle(
                        $fieldName,
                        $fieldId
                    );
                }
            }
        }

        // Return null if field ID not found in any field
        return null;
    }

    /**
     * Gets the field name/label from a field array.
     *
     * This method should be overridden by implementing classes to extract
     * the appropriate field name or label from their specific field structure.
     *
     * @param  array<int|string, mixed>  $field  The field array
     * @return ?string The field name/label or null if not found
     */
    protected static function getFieldNameFromField(
        array $field
    ): ?string {
        // Default implementation - should be overridden by implementing classes
        return null;
    }

    /**
     * Converts a field name/label to a Statamic handle format with field ID suffix.
     *
     * This method provides the common logic for converting field names to
     * Statamic handles. It converts the name to lowercase, replaces
     * non-alphanumeric characters with underscores, collapses multiple
     * underscores, and appends the field ID for uniqueness.
     *
     * @param  string  $fieldName  The field name/label
     * @param  string  $fieldId  The field ID to append for uniqueness
     * @return string The Statamic handle
     */
    protected static function convertFieldNameToHandle(
        string $fieldName,
        string $fieldId
    ): string {
        // Convert to lowercase and trim whitespace
        $handle = \strtolower(\trim($fieldName));

        // Replace non-alphanumeric characters with underscores
        $handle = \preg_replace(
            '/[^a-z0-9_]/',
            '_',
            $handle
        ) ?? $handle;

        // Collapse multiple consecutive underscores into single underscore
        $handle = \preg_replace(
            '/_+/',
            '_',
            $handle
        ) ?? $handle;

        // Remove leading and trailing underscores
        $handle = \trim(
            $handle,
            '_'
        );

        // Append field ID to ensure uniqueness
        return $handle.'_'.$fieldId;
    }
}
