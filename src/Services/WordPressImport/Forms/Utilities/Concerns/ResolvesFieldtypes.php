<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use Illuminate\Support\Str;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping as FormidableFormsFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping as GravityFormsFieldtypeMapping;

/**
 * Resolves a field's Statamic fieldtype, linked field, set attributes and preset values
 * from a mapping, and replaces placeholders in mapped values.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait ResolvesFieldtypes
{
    /**
     * Get the Statamic preset value for a parent field using the mapping config.
     *
     * This is DRY and generic: it finds the attribute mapped to 'preset' and applies
     * value_translation if present.
     *
     * The attribute counts as mapped to 'preset' when its 'statamic_attribute' is
     * the string 'preset' or an array whose 'default' is 'preset' (the form every
     * shipped preset mapping uses). A 'based_on_attribute_value' exclusion in that
     * array (Gravity Forms `addressType` 'us' mapped to null) does not suppress the
     * value returned here: the exclusion only keeps the preset out of the field's
     * config because it equals the fieldtype's default, while this value picks the
     * preset-specific subfield handle, which still needs the address type (#429).
     *
     * @param  array<int|string, mixed>  $parentField  The parent field data
     * @param  array<int|string, mixed>  $mappingConfig  The fieldtype mapping config
     */
    protected static function getStatamicPresetValue(
        array $parentField,
        array $mappingConfig
    ): ?string {
        // Iterate through the mapping config to find the attribute mapped to 'preset'
        foreach ($mappingConfig as $attr => $config) {
            $configArray = self::mappingConfig($config);
            $statamicAttr = self::mappingValue($configArray, 'statamic_attribute');

            // Read the target from either the plain string or the array's 'default'
            $statamicTarget = \is_array($statamicAttr)
                ? self::stringValue(self::mappingValue(self::asArray($statamicAttr), 'default'))
                : self::stringValue($statamicAttr);

            // Check if this attribute is mapped to Statamic's 'preset' attribute
            if ($statamicTarget === 'preset') {
                $normalizedAttr = self::normalizeArrayKey($attr);
                if ($normalizedAttr === null) {
                    continue;
                }

                // Retrieve the value from the parent field for this attribute
                $value = self::mappingValue(
                    $parentField,
                    $normalizedAttr
                );

                // If the field does not carry this attribute, try the next one mapped to 'preset'
                if ($value === null) {
                    continue;
                }

                // If a value_translation array exists, translate the value accordingly
                $valueTranslation = self::asArray(
                    self::mappingValue($configArray, 'value_translation')
                );
                if ($valueTranslation !== []) {
                    $translated = self::mappingValue(
                        $valueTranslation,
                        $value
                    );

                    return self::stringValue($translated) ?? self::stringValue($value);
                }

                // Return the raw value if no translation is needed
                return self::stringValue($value);
            }
        }

        // No attribute mapped to 'preset' is set on the parent field
        return null;
    }

    /**
     * Resolve the Statamic fieldtype class for a given field type and plugin.
     *
     * @param  string|array<int|string, mixed>  $fieldType  The field type (e.g., 'address') or full field array
     * @param  string  $pluginName  The plugin name (e.g., 'gravity-forms')
     * @return ?string Fully qualified Statamic fieldtype class name or null if not found
     */
    public static function resolveStatamicFieldtypeClass(
        string|array $fieldType,
        string $pluginName
    ): ?string {
        // Map plugin to mapping class and get the Statamic fieldtype handle
        $statamicType = match ($pluginName) {
            // Use GravityForms mapping class to get Statamic fieldtype handle
            'gravity-forms' => GravityFormsFieldtypeMapping::getStatamicFieldtype($fieldType),
            // Use FormidableForms mapping class to get Statamic fieldtype handle
            'formidable-forms' => FormidableFormsFieldtypeMapping::getStatamicFieldtype($fieldType),
            // Unknown plugin, cannot resolve fieldtype
            default => null,
        };

        // If no Statamic fieldtype handle was found, return null
        if (
            in_array($statamicType, [null, '', false], true)
        ) {
            return null;
        }

        // Convert Statamic fieldtype handle to fully qualified class name (e.g., 'address' => 'App\\Fieldtypes\\Address')
        $class = 'App\\Fieldtypes\\'.Str::studly($statamicType);

        // Return the class name if it exists, otherwise return null
        return class_exists($class) ? $class : null;
    }

    /**
     * Resolve the Statamic fieldtype from a mapping array, supporting attribute-based
     * mapping.
     *
     * @param  array<int|string, mixed>  $mappingArray  The mapping array (type => mapping config)
     * @param  string|array<int|string, mixed>  $fieldOrType  The field type string or full field array
     * @return string|null|false Statamic fieldtype, null if not mapped, or false if suppressed
     */
    protected static function resolveFieldtypeFromMapping(
        array $mappingArray,
        string|array $fieldOrType
    ): string|null|false {
        if (\is_string($fieldOrType)) {
            $type = $fieldOrType;
            $field = [];
        } else { // Otherwise
            $type = self::stringValue($fieldOrType['type'] ?? null);
            $field = $fieldOrType;
        }

        if (
            $type === null
            || $type === ''
            || ! self::arrayKeyExists($type, $mappingArray)
        ) {
            return null;
        }

        $mapping = self::mappingValue(
            $mappingArray,
            $type
        );

        if (! \is_array($mapping)) {
            return self::resolveFieldtypeMappingValue($mapping);
        }

        // Parse field_options JSON if it exists to make attributes available for based_on_other_attribute_value
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
                // Merge parsed field_options with the original field data
                $parsedField = \array_merge(
                    $field,
                    $fieldOptions
                );
            }
        }

        // Handle based_on_other_attribute_value logic
        if (isset($mapping['based_on_other_attribute_value'])) {
            $basedOnConfig = self::asArray(
                $mapping['based_on_other_attribute_value']
            );

            // Check if based_on_other_attribute_value has 'default' and 'specific' structure
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnConfig
                )
                || self::arrayKeyExists(
                    'specific',
                    $basedOnConfig
                )
            ) {
                $defaultBasedOnConfig = self::asArray(
                    self::mappingValue($basedOnConfig, 'default')
                );
                $specificBasedOnConfig = self::asArray(
                    self::mappingValue($basedOnConfig, 'specific')
                );

                // Process each based_on_other_attribute_value configuration
                $configToUse = $defaultBasedOnConfig;

                // Check for fieldtype-specific configuration
                $typeSpecificConfig = self::mappingValue(
                    $specificBasedOnConfig,
                    $type
                );
                if ($typeSpecificConfig !== null) {
                    $configToUse = self::asArray($typeSpecificConfig);
                }

                foreach ($configToUse as $otherAttrName => $valueMappings) {
                    $normalizedOtherAttrName = self::normalizeArrayKey($otherAttrName);
                    if ($normalizedOtherAttrName === null) {
                        continue;
                    }

                    $valueMappingsArray = self::asArray($valueMappings);

                    // Get the value of the other attribute from parsed field data
                    $otherAttrValue = self::mappingValue(
                        $parsedField,
                        $normalizedOtherAttrName
                    );

                    // Check if we have a mapping for this value
                    if (self::arrayKeyExists($otherAttrValue, $valueMappingsArray)) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                $otherAttrValue
                            )
                        );
                    }

                    // Check for #not_set case
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
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                '#not_set'
                            )
                        );
                    }

                    // Check for #not_empty case
                    if (
                        self::arrayKeyExists(
                            '#not_empty',
                            $valueMappingsArray
                        )
                        && (! in_array($otherAttrValue, [null, '', []], true))
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                '#not_empty'
                            )
                        );
                    }

                    // Check for empty string case
                    if (
                        self::arrayKeyExists(
                            '',
                            $valueMappingsArray
                        )
                        && $otherAttrValue === ''
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                ''
                            )
                        );
                    }
                }
            } else { // Otherwise
                // Legacy structure - process each based_on_other_attribute_value configuration
                foreach ($basedOnConfig as $otherAttrName => $valueMappings) {
                    $normalizedOtherAttrName = self::normalizeArrayKey($otherAttrName);
                    if ($normalizedOtherAttrName === null) {
                        continue;
                    }

                    $valueMappingsArray = self::asArray($valueMappings);

                    // Get the value of the other attribute from parsed field data
                    $otherAttrValue = self::mappingValue(
                        $parsedField,
                        $normalizedOtherAttrName
                    );

                    // Check if we have a mapping for this value
                    if (self::arrayKeyExists($otherAttrValue, $valueMappingsArray)) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                $otherAttrValue
                            )
                        );
                    }

                    // Check for #not_set case
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
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                '#not_set'
                            )
                        );
                    }

                    // Check for #not_empty case
                    if (
                        self::arrayKeyExists(
                            '#not_empty',
                            $valueMappingsArray
                        )
                        && (! in_array($otherAttrValue, [null, '', []], true))
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                '#not_empty'
                            )
                        );
                    }

                    // Check for empty string case
                    if (
                        self::arrayKeyExists(
                            '',
                            $valueMappingsArray
                        )
                        && $otherAttrValue === ''
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                ''
                            )
                        );
                    }
                }
            }
        }

        // Handle attributes-based mapping
        if (
            isset($mapping['attributes'])

        ) {
            foreach (self::asArray($mapping['attributes']) as $attr => $attrMapValue) {
                $attrMap = self::asArray($attrMapValue);
                $normalizedAttr = self::normalizeArrayKey($attr);
                if ($normalizedAttr === null) {
                    continue;
                }

                $attrValue = self::mappingValue(
                    $parsedField,
                    $normalizedAttr
                );

                // Check for exact value match
                if (
                    $attrValue !== null
                    && self::arrayKeyExists($attrValue, $attrMap)
                ) {
                    return self::resolveFieldtypeMappingValue(
                        self::mappingValue(
                            $attrMap,
                            $attrValue
                        )
                    );
                }

                // Check for #not_set case
                if (
                    self::arrayKeyExists(
                        '#not_set',
                        $attrMap
                    )
                    && ! self::arrayKeyExists(
                        $normalizedAttr,
                        $parsedField
                    )
                ) {
                    return self::resolveFieldtypeMappingValue(
                        self::mappingValue(
                            $attrMap,
                            '#not_set'
                        )
                    );
                }

                // Check for #empty case
                $emptyMapping = self::mappingValue(
                    $attrMap,
                    '#empty'
                );
                if (
                    $attrValue === ''
                    && $emptyMapping !== null
                ) {
                    return self::resolveFieldtypeMappingValue($emptyMapping);
                }

                // Check for #not_empty case
                if (
                    self::arrayKeyExists(
                        '#not_empty',
                        $attrMap
                    )
                    && ($attrValue !== '' && $attrValue !== [])
                ) {
                    return self::resolveFieldtypeMappingValue(
                        self::mappingValue(
                            $attrMap,
                            '#not_empty'
                        )
                    );
                }
            }
        }

        // Check for linked_field configuration
        $linkedField = static::getLinkedFieldFromMapping(
            $mappingArray,
            $fieldOrType
        );
        if ($linkedField !== null) {
            // If linked_field is found, return the linked field handle instead of the default fieldtype
            return $linkedField;
        }

        return self::resolveFieldtypeMappingValue(
            self::mappingValue($mapping, 'default')
        );
    }

    /**
     * Get the linked field handle for a fieldtype mapping.
     *
     * This method resolves linked field handles from the mapping configuration,
     * supporting the same conditional logic patterns as regular fieldtype mapping:
     *   - `default`: Simple default linked field handle
     *   - `based_on_attribute_value`: Link based on the value of a specific attribute
     *   - `based_on_other_attribute_value`: Link based on the value of another
     *     attribute
     *
     * When a linked field is used, the resulting Statamic blueprint field will have
     * a 'field' property instead of a 'type' property, with all other attributes
     * (except 'handle') placed in a 'config' array.
     *
     * Example mapping structure:
     * ```php
     * 'textarea' => [
     *     'default' => 'textarea',
     *     'linked_field' => [
     *         'default' => 'uamsweb_common.bard_simple',
     *         'based_on_attribute_value' => [
     *             'useRichTextEditor' => [
     *                 'true' => 'uamsweb_common.bard_simple',
     *                 true => 'uamsweb_common.bard_simple',
     *                 '1' => 'uamsweb_common.bard_simple',
     *                 1 => 'uamsweb_common.bard_simple',
     *             ],
     *         ],
     *     ],
     * ],
     * ```
     *
     * This would result in a Statamic blueprint field like:
     * ```php
     * [
     *     'handle' => 'test_field_123',
     *     'field' => 'uamsweb_common.bard_simple',
     *     'config' => [
     *         'display' => 'Test Field',
     *         'validate' => ['required'],
     *     ],
     * ]
     * ```
     *
     * @param  array<int|string, mixed>  $mappingArray  The mapping array (type => mapping config)
     * @param  string|array<int|string, mixed>  $fieldOrType  The field type string or full field array
     * @return ?string The linked field handle or null if not found
     */
    public static function getLinkedFieldFromMapping(
        array $mappingArray,
        string|array $fieldOrType
    ): ?string {
        if (\is_string($fieldOrType)) {
            $type = $fieldOrType;
            $field = [];
        } else { // Otherwise
            $type = self::stringValue($fieldOrType['type'] ?? null);
            $field = $fieldOrType;
        }

        if (
            $type === null
            || $type === ''
            || ! self::arrayKeyExists($type, $mappingArray)
        ) {
            return null;
        }

        $mapping = self::mappingValue(
            $mappingArray,
            $type
        );

        if (
            ! \is_array($mapping)
            || ! isset($mapping['linked_field'])
        ) {
            return null;
        }

        $linkedFieldConfig = self::asArray($mapping['linked_field']);

        // Parse field_options JSON if it exists to make attributes available for based_on_other_attribute_value
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
                // Merge parsed field_options with the original field data
                $parsedField = \array_merge(
                    $field,
                    $fieldOptions
                );
            }
        }

        // Handle based_on_other_attribute_value logic
        $basedOnOtherConfig = self::asArray(
            self::mappingValue($linkedFieldConfig, 'based_on_other_attribute_value')
        );
        if ($basedOnOtherConfig !== []) {
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnOtherConfig
                )
                || self::arrayKeyExists(
                    'specific',
                    $basedOnOtherConfig
                )
            ) {
                $resolvedLinkedField = self::resolveStringMappingFromConditionalConfig(
                    self::resolveStructuredConditionalConfig(
                        $basedOnOtherConfig,
                        $type
                    ),
                    $parsedField
                );
                if ($resolvedLinkedField !== null) {
                    return $resolvedLinkedField;
                }
            } else {
                $resolvedLinkedField = self::resolveStringMappingFromConditionalConfig(
                    $basedOnOtherConfig,
                    $parsedField
                );
                if ($resolvedLinkedField !== null) {
                    return $resolvedLinkedField;
                }
            }
        }

        // Handle based_on_attribute_value logic
        $basedOnAttributeConfig = self::asArray(
            self::mappingValue($linkedFieldConfig, 'based_on_attribute_value')
        );
        if ($basedOnAttributeConfig !== []) {
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnAttributeConfig
                )
                || self::arrayKeyExists(
                    'specific',
                    $basedOnAttributeConfig
                )
            ) {
                $resolvedLinkedField = self::resolveStringMappingFromConditionalConfig(
                    self::resolveStructuredConditionalConfig(
                        $basedOnAttributeConfig,
                        $type
                    ),
                    $parsedField
                );
                if ($resolvedLinkedField !== null) {
                    return $resolvedLinkedField;
                }
            } else {
                $resolvedLinkedField = self::resolveStringMappingFromConditionalConfig(
                    $basedOnAttributeConfig,
                    $parsedField
                );
                if ($resolvedLinkedField !== null) {
                    return $resolvedLinkedField;
                }
            }
        }

        return self::resolveOptionalStringMappingValue(
            self::mappingValue($linkedFieldConfig, 'default')
        );
    }

    /**
     * Get the set_attributes configuration for a fieldtype mapping.
     *
     * Returns the table's `default` attributes with `[#site_handle]` replaced in
     * their keys and string values. Any other part of the table is ignored.
     *
     * @param  array<int|string, mixed>  $mappingArray  The mapping array (type => mapping config)
     * @param  string|array<int|string, mixed>  $fieldOrType  The field type string or full field array
     * @param  ?string  $siteHandle  The site handle for placeholder replacement
     * @return array<int|string, mixed>|null The set_attributes configuration or null if not found
     */
    public static function getSetAttributesFromMapping(
        array $mappingArray,
        string|array $fieldOrType,
        ?string $siteHandle = null
    ): ?array {
        $type = \is_string($fieldOrType)
            ? $fieldOrType
            : self::stringValue($fieldOrType['type'] ?? null);

        if (
            $type === null
            || $type === ''
            || ! self::arrayKeyExists($type, $mappingArray)
        ) {
            return null;
        }

        $mapping = self::mappingValue(
            $mappingArray,
            $type
        );

        if (
            ! \is_array($mapping)
            || ! isset($mapping['set_attributes'])
        ) {
            return null;
        }

        $setAttributes = self::asArray($mapping['set_attributes']);

        // Start with the default attributes
        $attributes = self::asArray(
            self::mappingValue($setAttributes, 'default')
        );

        // Process placeholders in the attributes
        if ($attributes !== [] && $siteHandle !== null) {
            $processedAttributes = [];
            foreach ($attributes as $key => $value) {
                // Process placeholders in the key
                $processedKey = self::replacePlaceholders(
                    self::normalizeMixedToString($key),
                    null,
                    null,
                    null,
                    $siteHandle
                );

                // Process placeholders in the value if it's a string
                if (\is_string($value)) {
                    $processedValue = self::replacePlaceholders(
                        $value,
                        null,
                        null,
                        null,
                        $siteHandle
                    );
                } else { // Otherwise
                    $processedValue = $value;
                }

                $processedAttributes[$processedKey] = $processedValue;
            }

            $attributes = $processedAttributes;
        }

        return $attributes;
    }

    /**
     * Get the translated preset value for placeholder replacement.
     * This is different from getStatamicPresetValue because it should always
     * return a value for placeholder replacement, even when the actual preset
     * attribute should be excluded.
     *
     * @param  array<int|string, mixed>  $parentField  The parent field data
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration
     * @return ?string The translated preset value for placeholder replacement
     */
    protected static function getPresetValueForPlaceholder(
        array $parentField,
        array $mappingConfig
    ): ?string {
        // Iterate through the mapping config to find the attribute mapped to 'preset'
        foreach ($mappingConfig as $attr => $config) {
            $configArray = self::mappingConfig($config);
            $statamicAttr = self::mappingValue($configArray, 'statamic_attribute');
            $isPresetAttribute = false;

            // Handle simple string statamic_attribute
            if ($statamicAttr === 'preset') {
                $isPresetAttribute = true;
            } elseif (\is_array($statamicAttr)) {
                $statamicAttrArray = self::asArray($statamicAttr);
                if (self::stringValue(self::mappingValue($statamicAttrArray, 'default')) === 'preset') {
                    $isPresetAttribute = true;
                }
            }

            if ($isPresetAttribute) {
                $normalizedAttr = self::normalizeArrayKey($attr);
                if ($normalizedAttr === null) {
                    continue;
                }

                // Retrieve the value from the parent field for this attribute
                $value = self::mappingValue(
                    $parentField,
                    $normalizedAttr
                );

                // If the value is not set, return null
                if ($value === null) {
                    return null;
                }

                // Check for placeholder_translation first (takes precedence)
                $placeholderTranslation = self::asArray(
                    self::mappingValue($configArray, 'placeholder_translation')
                );
                if ($placeholderTranslation !== []) {
                    $translated = self::mappingValue(
                        $placeholderTranslation,
                        $value
                    );

                    return self::stringValue($translated) ?? self::stringValue($value);
                }

                // Fallback to value_translation if no placeholder_translation is defined
                $valueTranslation = self::asArray(
                    self::mappingValue($configArray, 'value_translation')
                );
                if ($valueTranslation !== []) {
                    $translated = self::mappingValue(
                        $valueTranslation,
                        $value
                    );

                    return self::stringValue($translated) ?? self::stringValue($value);
                }

                // Return the raw value if no translation is needed
                return self::stringValue($value);
            }
        }

        // No attribute mapped to 'preset' found in the mapping config
        return null;
    }

    /**
     * Replace placeholders in attribute names with actual values.
     *
     * This method handles the replacement of various placeholders in attribute names:
     *   - '*' is replaced with the provided handle
     *   - '[#preset]' is replaced with the provided preset value
     *   - '[#subfield]' is replaced with the provided subfield handle
     *   - '[#site_handle]' is replaced with the provided site
     *     handle
     *
     * @param  string  $attributeName  The attribute name with placeholders
     * @param  ?string  $handle  The handle to substitute for '*' placeholder
     * @param  ?string  $subfieldHandle  The subfield handle to substitute for '[#subfield]' placeholder
     * @param  ?string  $preset  The preset value to substitute for '[#preset]' placeholder
     * @param  ?string  $siteHandle  The site handle to substitute for '[#site_handle]'
     *                               placeholders
     * @return string The attribute name with placeholders replaced
     */
    protected static function replacePlaceholders(
        string $attributeName,
        ?string $handle = null,
        ?string $subfieldHandle = null,
        ?string $preset = null,
        ?string $siteHandle = null
    ): string {
        // Replace '*' with the handle (for simple subfield handle replacement)
        if ($handle !== null) {
            $attributeName = \str_replace(
                '*',
                $handle,
                $attributeName
            );
        }

        // Drop the '[#preset]' that follows '[#subfield]' when the subfield handle already
        // ends with the preset. In the host app, `App\Fieldtypes\Address` resolves the
        // State subfield to its preset-specific handle (`state_province_region_canadian`),
        // so '[#subfield]_[#preset]_label' would otherwise repeat the preset (#497).
        // Standalone, the handle is the canonical `state_province_region` and the key
        // is unchanged.
        if (
            $subfieldHandle !== null
            && $preset !== null
            && $preset !== ''
            && \str_ends_with(
                $subfieldHandle,
                '_'.$preset
            )
        ) {
            $attributeName = \str_replace(
                '[#subfield]_[#preset]',
                '[#subfield]',
                $attributeName
            );
        }

        // Replace '[#preset]' first, then '[#subfield]' to handle nested placeholders
        // This ensures that if subfieldHandle contains [#preset], it gets resolved before
        // being used as a replacement for [#subfield]
        if ($preset !== null) {
            $attributeName = \str_replace(
                '[#preset]',
                $preset,
                $attributeName
            );
        }

        // Replace '[#subfield]' with the actual subfield handle (after preset replacement)
        if ($subfieldHandle !== null) {
            // First resolve any placeholders in the subfieldHandle itself
            $resolvedSubfieldHandle = $subfieldHandle;
            if ($preset !== null) {
                $resolvedSubfieldHandle = \str_replace(
                    '[#preset]',
                    $preset,
                    $resolvedSubfieldHandle
                );
            }

            $attributeName = \str_replace(
                '[#subfield]',
                $resolvedSubfieldHandle,
                $attributeName
            );
        }

        // Replace '[#site_handle]' with the site handle
        if ($siteHandle !== null) {
            return \str_replace(
                '[#site_handle]',
                $siteHandle,
                $attributeName
            );
        }

        return $attributeName;
    }

    /**
     * Recursively replace site handle placeholders in mapped attribute structures.
     *
     * @param  mixed  $value  Mapped value or nested array
     * @param  string  $siteHandle  Site handle for [#site_handle]
     * @return mixed Structure with placeholders resolved where applicable
     */
    protected static function applySiteHandlePlaceholderToMappedStructure(
        mixed $value,
        string $siteHandle
    ): mixed {
        if (\is_string($value)) {
            return self::replacePlaceholders(
                $value,
                null,
                null,
                null,
                $siteHandle
            );
        }

        if (! \is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $nested) {
            $resolvedKey = \is_string($key)
                ? self::replacePlaceholders(
                    $key,
                    null,
                    null,
                    null,
                    $siteHandle
                )
                : $key;
            $result[$resolvedKey] = self::applySiteHandlePlaceholderToMappedStructure(
                $nested,
                $siteHandle
            );
        }

        return $result;
    }
}
