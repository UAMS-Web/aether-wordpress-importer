<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Processes the mappings that depend on a value, a pattern, or the value of the same or
 * another attribute, and the attribute mapping built from them.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait ProcessesConditionalMappings
{
    /**
     * Process value mappings for based_on_other_attribute_value configurations.
     *
     * @param  array<int|string, mixed>  $valueMappings  The value mappings to process
     * @param  string  $otherAttrName  The name of the other attribute
     * @param  array<int|string, mixed>  $data  The field data
     * @return array{0: mixed, 1: bool}|null Returns [value, found] tuple if match found, null otherwise
     */
    protected static function processValueMappings(
        array $valueMappings,
        int|string $otherAttrName,
        array $data
    ): ?array {
        // Get the value of the other attribute
        $otherAttrValue = $data[$otherAttrName] ?? null;
        $lookupValue = self::mappingLookupKey($otherAttrValue);

        // Check if we have a mapping for this value
        if (
            self::arrayKeyExists(
                $lookupValue,
                $valueMappings
            )
        ) {
            return [$valueMappings[$lookupValue], true];
        }

        // Check for #not_set case
        if (
            self::arrayKeyExists(
                '#not_set',
                $valueMappings
            )
            && self::isAttributeNotSet(
                $otherAttrName,
                $data
            )
        ) {
            return [$valueMappings['#not_set'], true];
        }

        // Check for #not_empty case
        if (
            self::arrayKeyExists(
                '#not_empty',
                $valueMappings
            )
            && self::isAttributeNotEmpty(
                $otherAttrName,
                $data
            )
        ) {
            return [$valueMappings['#not_empty'], true];
        }

        // Check for #empty case
        if (
            self::arrayKeyExists(
                '#empty',
                $valueMappings
            )
            && self::isAttributeEmpty(
                $otherAttrName,
                $data
            )
        ) {
            return [$valueMappings['#empty'], true];
        }

        // Check for empty string case
        if (
            self::arrayKeyExists(
                '',
                $valueMappings
            )
            && $otherAttrValue === ''
        ) {
            return [$valueMappings[''], true];
        }

        return null;
    }

    /**
     * Process pattern mappings for based_on_attribute_value configurations.
     *
     * @param  array<int|string, mixed>  $patternMappings  The pattern mappings to process
     * @param  string  $attrName  The name of the current attribute
     * @param  mixed  $attrValue  The value of the current attribute
     * @param  array<int|string, mixed>  $data  The field data
     * @return array{0: mixed, 1: bool}|null Returns [value, found] tuple if match found, null otherwise
     */
    protected static function processPatternMappings(
        array $patternMappings,
        int|string|null $attrName,
        mixed $attrValue,
        array $data
    ): ?array {
        foreach ($patternMappings as $pattern => $targetAttribute) {
            // Check for '#not_set' special case first
            if ($pattern === '#not_set') {
                if (
                    self::isAttributeNotSet(
                        $attrName,
                        $data
                    )
                ) {
                    return [$targetAttribute, true];
                }
            } elseif ($pattern === '#not_empty') { // Check for '#not_empty' special case
                if (
                    self::isAttributeNotEmpty(
                        $attrName,
                        $data
                    )
                ) {
                    return [$targetAttribute, true];
                }
            } else { // Otherwise
                // Check against the original value before translation
                if (
                    self::matchesPattern(
                        $attrValue,
                        $pattern
                    )
                ) {
                    return [$targetAttribute, true];
                }
            }
        }

        return null;
    }

    /**
     * Process fieldtype-specific or general mappings for
     * based_on_other_attribute_value.
     *
     * @param  array<int|string, mixed>|string  $config  The configuration array or legacy string
     * @param  ?string  $excludeFieldtype  The fieldtype to check for
     * @param  array<int|string, mixed>  $data  The field data
     * @return array{0: mixed, 1: bool}|null Returns [value, found] tuple if match found, null otherwise
     */
    protected static function processBasedOnOtherAttributeValue(
        mixed $config,
        mixed $excludeFieldtype,
        array $data
    ): ?array {
        $matchFound = false;
        $result = null;
        $configArray = self::mappingConfig($config);
        $excludeFieldtypeString = self::stringValue($excludeFieldtype) ?? '';

        // Check if config is an array
        // Check for 'specific' key
        if (
            self::arrayKeyExists(
                'specific',
                $configArray
            )
        ) {
            $specificConfig = self::asArray(
                self::mappingValue(
                    $configArray,
                    'specific'
                )
            );
            // Check for fieldtype-specific configuration
            if (
                $excludeFieldtypeString !== ''
                && self::arrayKeyExists(
                    $excludeFieldtypeString,
                    $specificConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificConfig,
                        $excludeFieldtypeString
                    )
                );

                foreach ($fieldtypeMappings as $otherAttrName => $valueMappings) {
                    $normalizedOtherAttrName = self::normalizeArrayKey(
                        $otherAttrName
                    );
                    if ($normalizedOtherAttrName === null) {
                        continue;
                    }

                    $result = self::processValueMappings(
                        self::asArray($valueMappings),
                        $normalizedOtherAttrName,
                        $data
                    );
                    if ($result !== null) {
                        $matchFound = true;
                        break;
                    }
                }
            }
        }

        // Check for 'default' key (if match not found)
        if (
            self::arrayKeyExists(
                'default',
                $configArray
            )
            && ! $matchFound
        ) {
            $defaultConfig = self::asArray(
                self::mappingValue(
                    $configArray,
                    'default'
                )
            );

            foreach ($defaultConfig as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey(
                    $otherAttrName
                );
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $result = self::processValueMappings(
                    self::asArray($valueMappings),
                    $normalizedOtherAttrName,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                    break;
                }
            }
        }

        // Legacy structure - process each based_on_other_attribute_value configuration directly (if match not found)
        if (
            ! $matchFound
            && $configArray !== []
            && ! self::arrayKeyExists(
                'specific',
                $configArray
            )
            && ! self::arrayKeyExists(
                'default',
                $configArray
            )
        ) {
            foreach ($configArray as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey(
                    $otherAttrName
                );
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $result = self::processValueMappings(
                    self::asArray($valueMappings),
                    $normalizedOtherAttrName,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                    break;
                }
            }
        }

        // Check if config is a string (if match not found)
        if (
            \is_string($config)
            && ! $matchFound
        ) {
            // For string configs, we would process them directly
            // This would be the legacy string-based configuration
            $result = [$config, true];
            $matchFound = true;
        }

        return $matchFound ? $result : null;
    }

    /**
     * Process fieldtype-specific or general mappings for based_on_attribute_value.
     *
     * @param  array<int|string, mixed>|string  $config  The configuration array or legacy string
     * @param  ?string  $excludeFieldtype  The fieldtype to check for
     * @param  string  $attrName  The name of the current attribute
     * @param  mixed  $attrValue  The value of the current attribute
     * @param  array<int|string, mixed>  $data  The field data
     * @return array{0: mixed, 1: bool}|null Returns [value, found] tuple if match found, null otherwise
     */
    protected static function processBasedOnAttributeValue(
        mixed $config,
        mixed $excludeFieldtype,
        int|string|null $attrName,
        mixed $attrValue,
        array $data
    ): ?array {
        $matchFound = false;
        $result = null;
        $configArray = self::mappingConfig($config);
        $excludeFieldtypeString = self::stringValue($excludeFieldtype) ?? '';

        // Check if config is an array
        // Check for 'specific' key
        if (
            self::arrayKeyExists(
                'specific',
                $configArray
            )
        ) {
            $specificConfig = self::asArray(
                self::mappingValue(
                    $configArray,
                    'specific'
                )
            );
            // Check for fieldtype-specific configuration
            if (
                $excludeFieldtypeString !== ''
                && self::arrayKeyExists(
                    $excludeFieldtypeString,
                    $specificConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificConfig,
                        $excludeFieldtypeString
                    )
                );
                $result = self::processPatternMappings(
                    $fieldtypeMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                }
            }
        }

        // Check for 'default' key (if match not found)
        if (
            self::arrayKeyExists(
                'default',
                $configArray
            )
            && ! $matchFound
        ) {
            $defaultConfig = self::asArray(
                self::mappingValue(
                    $configArray,
                    'default'
                )
            );
            $result = self::processPatternMappings(
                $defaultConfig,
                $attrName,
                $attrValue,
                $data
            );
            if ($result !== null) {
                $matchFound = true;
            }
        }

        // Legacy structure - process each based_on_attribute_value configuration directly (if match not found)
        if (
            ! $matchFound
            && $configArray !== []
            && ! self::arrayKeyExists(
                'specific',
                $configArray
            )
            && ! self::arrayKeyExists(
                'default',
                $configArray
            )
        ) {
            // Check for fieldtype-specific configuration first
            if (
                $excludeFieldtypeString !== ''
                && self::arrayKeyExists(
                    $excludeFieldtypeString,
                    $configArray
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $configArray,
                        $excludeFieldtypeString
                    )
                );
                $result = self::processPatternMappings(
                    $fieldtypeMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                }
            } else { // If no fieldtype-specific mapping was found, check for general mappings
                $result = self::processPatternMappings(
                    $configArray,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                }
            }
        }

        // Check if config is a string (if match not found)
        if (
            \is_string($config)
            && ! $matchFound
        ) {
            // For string configs, we would process them directly
            // This would be the legacy string-based configuration
            $result = [$config, true];
            $matchFound = true;
        }

        return $matchFound ? $result : null;
    }

    /**
     * Process an attribute if it meets the criteria.
     *
     * @param  string  $attrName  The attribute name
     * @param  mixed  $attrValue  The attribute value
     * @param  array<int|string, mixed>  $processedMappings  The processed mappings
     * @param  array<int|string, mixed>  $combinedData  The combined data array
     * @param  array<int|string, mixed>  $subfieldData  The subfield data (for checking if already processed)
     * @param  ?string  $statamicHandle  The Statamic handle
     * @param  ?string  $preset  The preset value
     * @return array<int|string, mixed>|null Returns mapped attributes array or null if no mapping
     */
    protected static function processAttributeIfValid(
        int|string|null $attrName,
        mixed $attrValue,
        array $processedMappings,
        array $combinedData,
        array $subfieldData = [],
        ?string $statamicHandle = null,
        ?string $preset = null
    ): ?array {
        $normalizedAttrName = self::normalizeArrayKey($attrName);
        if ($normalizedAttrName === null) {
            return null;
        }

        // Skip if this attribute is not in processedMappings
        if (! isset($processedMappings[$normalizedAttrName])) {
            return null;
        }

        // Skip if already processed from subfield data (for parent field processing)
        if ($subfieldData !== [] && isset($subfieldData[$normalizedAttrName])) {
            return null;
        }

        $attrConfig = self::asArray(
            self::mappingValue(
                $processedMappings,
                $normalizedAttrName
            )
        );
        $statamicAttr = self::mappingValue(
            $attrConfig,
            'statamic_attribute'
        );
        if ($statamicAttr === null) {
            return null;
        }

        // Skip if attrValue is empty string or null (these shouldn't create attributes)
        if ($attrValue === null || $attrValue === '') {
            return null;
        }

        // Process the attribute
        return self::processAttributeMapping(
            $normalizedAttrName,
            $attrValue,
            $attrConfig,
            $combinedData,
            $statamicHandle,
            $preset
        );
    }

    /**
     * Process a single attribute mapping.
     *
     * @param  string  $attrName  The attribute name
     * @param  mixed  $attrValue  The attribute value
     * @param  array<int|string, mixed>  $attrConfig  The attribute configuration
     * @param  array<int|string, mixed>  $combinedData  The combined data array
     * @param  ?string  $statamicHandle  The Statamic handle
     * @param  ?string  $preset  The preset value
     * @return array<int|string, mixed>|null Returns mapped attributes array or null if no mapping
     */
    protected static function processAttributeMapping(
        int|string|null $attrName,
        mixed $attrValue,
        array $attrConfig,
        array $combinedData,
        ?string $statamicHandle,
        ?string $preset
    ): ?array {
        $statamicAttr = self::mappingValue(
            $attrConfig,
            'statamic_attribute'
        );
        if ($statamicAttr === null) {
            return null;
        }

        // Handle complex statamic_attribute structures with proper precedence order
        if (\is_array($statamicAttr)) {
            $statamicAttrArray = self::mappingConfig($statamicAttr);
            $basedOnAttributeValueArray = self::asArray(
                self::mappingValue(
                    $statamicAttrArray,
                    'based_on_attribute_value'
                )
            );
            $resolvedAttribute = null;
            $finalValue = $attrValue;

            // 1. based_on_attribute_value.specific
            if (
                self::arrayKeyExists(
                    'specific',
                    $basedOnAttributeValueArray
                )
            ) {
                $result = self::processBasedOnAttributeValue(
                    $basedOnAttributeValueArray,
                    null,
                    $attrName,
                    $attrValue,
                    $combinedData
                );
                if ($result !== null) {
                    [$resolvedAttribute] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 2. based_on_attribute_value.default
            if (
                $resolvedAttribute === null
                && self::arrayKeyExists(
                    'default',
                    $basedOnAttributeValueArray
                )
            ) {
                $result = self::processBasedOnAttributeValue(
                    $basedOnAttributeValueArray,
                    null,
                    $attrName,
                    $attrValue,
                    $combinedData
                );
                if ($result !== null) {
                    [$resolvedAttribute] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 3. based_on_attribute_value (legacy)
            if (
                $resolvedAttribute === null
                && $basedOnAttributeValueArray !== []
                && ! self::arrayKeyExists(
                    'default',
                    $basedOnAttributeValueArray
                )
                && ! self::arrayKeyExists(
                    'specific',
                    $basedOnAttributeValueArray
                )
            ) {
                $result = self::processBasedOnAttributeValue(
                    $basedOnAttributeValueArray,
                    null,
                    $attrName,
                    $attrValue,
                    $combinedData
                );
                if ($result !== null) {
                    [$resolvedAttribute] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 4. based_on_other_attribute_value.specific
            $basedOnOtherAttributeValue = self::asArray(
                self::mappingValue(
                    $statamicAttrArray,
                    'based_on_other_attribute_value'
                )
            );
            if (
                $resolvedAttribute === null
                && self::arrayKeyExists(
                    'specific',
                    $basedOnOtherAttributeValue
                )
            ) {
                $basedOnConfig = self::asArray(
                    self::mappingValue(
                        $basedOnOtherAttributeValue,
                        'specific'
                    )
                );
                $result = self::processBasedOnOtherAttributeValueMapping(
                    $basedOnConfig,
                    $combinedData,
                    $attrValue
                );
                if ($result !== null) {
                    [$resolvedAttribute, $finalValue] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 5. based_on_other_attribute_value.default
            if (
                $resolvedAttribute === null
                && self::arrayKeyExists(
                    'default',
                    $basedOnOtherAttributeValue
                )
            ) {
                $basedOnConfig = self::asArray(
                    self::mappingValue(
                        $basedOnOtherAttributeValue,
                        'default'
                    )
                );
                $result = self::processBasedOnOtherAttributeValueMapping(
                    $basedOnConfig,
                    $combinedData,
                    $attrValue
                );
                if ($result !== null) {
                    [$resolvedAttribute, $finalValue] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 6. based_on_other_attribute_value (legacy)
            if (
                $resolvedAttribute === null
                && $basedOnOtherAttributeValue !== []
                && ! self::arrayKeyExists(
                    'default',
                    $basedOnOtherAttributeValue
                )
                && ! self::arrayKeyExists(
                    'specific',
                    $basedOnOtherAttributeValue
                )
            ) {
                $result = self::processBasedOnOtherAttributeValueMapping(
                    $basedOnOtherAttributeValue,
                    $combinedData,
                    $attrValue
                );
                if ($result !== null) {
                    [$resolvedAttribute, $finalValue] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            $resolvedAttribute ??= self::mappingValue(
                $statamicAttrArray,
                'specific'
            ) ?? self::mappingValue(
                $statamicAttrArray,
                'default'
            );

            // If we still don't have a resolved attribute, delegate to mapAttributesFromMapping
            if ($resolvedAttribute === null) {
                $mappingKey = self::normalizeArrayKey($attrName);
                if ($mappingKey === null) {
                    return null;
                }

                return static::mapAttributesFromMapping(
                    $combinedData,
                    [$mappingKey => $attrConfig],
                    [],
                    $statamicHandle,
                    $preset
                );
            }

            // Apply value_translation if present in the original attrConfig and we have a resolved attribute
            if (isset($attrConfig['value_translation'])) {
                $finalValue = static::applyValueTranslation(
                    $finalValue,
                    $attrConfig,
                    $attrName,
                    $combinedData,
                    null,
                    null,
                    $resolvedAttribute
                );
            }

            return [
                self::normalizeArrayKeyForAssignment($resolvedAttribute) => $finalValue,
            ];
        }

        // Otherwise
        // Handle simple string statamic_attribute
        $statamicAttrString = self::stringValue($statamicAttr)
            ?? self::normalizeMixedToString($statamicAttr);
        $finalValue = self::processSimpleStatamicAttribute(
            $attrValue,
            $attrConfig,
            $statamicAttrString,
            $combinedData,
            $attrName
        );

        return [
            self::normalizeArrayKeyForAssignment($statamicAttrString) => $finalValue,
        ];
    }

    /**
     * Process based_on_other_attribute_value mapping logic.
     *
     * This method handles the common logic for processing
     * based_on_other_attribute_value configurations, supporting both
     * 'default'/'specific' structure and legacy structure.
     *
     * @param  array<int|string, mixed>  $basedOnConfig  The based_on_other_attribute_value configuration
     * @param  array<int|string, mixed>  $data  The data array to get other attribute values from
     * @param  mixed  $attrValue  The attribute value to map
     * @return array<int|string, mixed>|null Returns [resolvedAttribute, attrValue] if match found, null otherwise
     */
    protected static function processBasedOnOtherAttributeValueMapping(
        array $basedOnConfig,
        array $data,
        mixed $attrValue
    ): ?array {
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
                self::mappingValue(
                    $basedOnConfig,
                    'default'
                )
            );
            $configToUse = $defaultBasedOnConfig;

            // Process each based_on_other_attribute_value configuration
            foreach ($configToUse as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey(
                    $otherAttrName
                );
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $valueMappingsArray = self::asArray($valueMappings);
                $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;

                // Check for exact value match
                if (
                    self::arrayKeyExists(
                        $otherAttrValue,
                        $valueMappingsArray
                    )
                ) {
                    $resolvedAttribute = self::mappingValue(
                        $valueMappingsArray,
                        $otherAttrValue
                    );

                    return [$resolvedAttribute, $attrValue];
                }
            }
        } else { // Otherwise
            // Legacy structure - process each based_on_other_attribute_value configuration
            foreach ($basedOnConfig as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey(
                    $otherAttrName
                );
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $valueMappingsArray = self::asArray($valueMappings);
                $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;

                // Check for exact value match
                if (
                    self::arrayKeyExists(
                        $otherAttrValue,
                        $valueMappingsArray
                    )
                ) {
                    $resolvedAttribute = self::mappingValue(
                        $valueMappingsArray,
                        $otherAttrValue
                    );

                    return [$resolvedAttribute, $attrValue];
                }
            }
        }

        return null;
    }

    /**
     * Post-process mapped attributes to replace placeholders and filter empty keys.
     *
     * @param  array<int|string, mixed>  $mapped  The mapped attributes array
     * @param  ?string  $statamicHandle  The statamic handle for placeholder replacement
     * @param  ?string  $preset  The preset value for placeholder replacement
     * @return array<int|string, mixed> The processed mapped attributes
     */
    protected static function postProcessMappedAttributes(
        array $mapped,
        ?string $statamicHandle,
        ?string $preset
    ): array {
        $processedMapped = [];
        foreach ($mapped as $key => $value) {
            $processedKey = self::replacePlaceholders(
                (string) $key,
                null,
                $statamicHandle,
                $preset
            );
            $processedMapped[$processedKey] = $value;
        }

        // Remove any attributes with empty keys
        return \array_filter(
            $processedMapped,
            fn ($key): bool => $key !== '',
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Process simple statamic_attribute with optional value_translation.
     *
     * @param  mixed  $attrValue  The attribute value
     * @param  array<int|string, mixed>  $attrConfig  The attribute configuration
     * @param  string  $statamicAttr  The statamic attribute name
     * @param  array<int|string, mixed>  $data  The full data array for context
     * @param  ?string  $attrName  The attribute name being processed
     * @return mixed The processed value
     */
    protected static function processSimpleStatamicAttribute(
        mixed $attrValue,
        array $attrConfig,
        string $statamicAttr,
        array $data = [],
        int|string|null $attrName = null
    ): mixed {
        // Apply value_translation if present
        if (isset($attrConfig['value_translation'])) {
            return static::applyValueTranslation(
                $attrValue,
                $attrConfig,
                $attrName,
                $data,
                null,
                null,
                $statamicAttr
            );
        }

        return $attrValue;
    }
}
