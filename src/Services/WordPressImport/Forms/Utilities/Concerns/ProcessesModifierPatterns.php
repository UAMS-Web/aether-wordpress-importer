<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Processes modifier patterns, including those matched on another attribute's value, and
 * array replacements.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait ProcessesModifierPatterns
{
    /**
     * Match a modifier value from based_on_other_attribute_value mappings.
     *
     * @param  mixed  $mappings  The other-attribute value maps
     * @param  array<int|string, mixed>  $data  The field data
     * @return mixed The matched value, or null when no match is found
     */
    private static function matchModifierOtherAttributeValue(
        mixed $mappings,
        array $data
    ): mixed {
        foreach (self::asArray($mappings) as $otherAttrName => $valueMap) {
            $normalizedOtherAttrName = self::normalizeArrayKey(
                $otherAttrName
            );
            if (
                $normalizedOtherAttrName === null
                || ! self::arrayKeyExists(
                    $normalizedOtherAttrName,
                    $data
                )
            ) {
                continue;
            }

            $otherAttrValue = $data[$normalizedOtherAttrName];
            $valueMapArray = self::asArray($valueMap);
            if (
                self::arrayKeyExists(
                    $otherAttrValue,
                    $valueMapArray
                )
            ) {
                return self::mappingValue(
                    $valueMapArray,
                    $otherAttrValue
                );
            }
        }

        return null;
    }

    /**
     * Process modifier patterns with the correct hierarchy order.
     *
     * This method eliminates duplicated logic across all pattern implementations
     * by providing a unified way to process the modifier hierarchy:
     *   1. based_on_attribute_value.specific
     *   2. based_on_attribute_value.default
     *   3. based_on_other_attribute_value.specific
     *   4. based_on_other_attribute_value.default
     *   5. specific
     *   6. default
     *
     * @param  mixed  $config  The configuration value to process (can be array, boolean, string, etc.)
     * @param  array<int|string, mixed>  $data  The field data to check for based_on_other_attribute_value conditions
     * @param  ?callable  $resultProcessor  Optional callback to process the result before returning
     * @param  ?string  $excludeFieldtype  The fieldtype to exclude
     * @param  ?string  $attrName  The attribute name for based_on_attribute_value checks
     * @param  mixed  $attrValue  The attribute value for based_on_attribute_value checks
     * @return array{value: mixed, matchFound: bool} Returns [value, matchFound] array
     */
    protected static function processModifierPatterns(
        mixed $config,
        array $data,
        ?callable $resultProcessor = null,
        ?string $excludeFieldtype = null,
        int|string|null $attrName = null,
        mixed $attrValue = null
    ): array {
        $value = false;
        $matchFound = false;

        // Handle non-array configurations (boolean, string, etc.)
        if (! \is_array($config)) {
            // Check if this is a string that refers to a field name in the data
            if (\is_string($config) && isset($data[$config])) {
                $value = (bool) $data[$config];
                $matchFound = true;
            } elseif (\is_string($config)) {
                // String field reference that doesn't exist - $value stays false
                $matchFound = true;
            } else { // Otherwise
                // Non-string value (boolean, number, etc.)
                $value = $config;
                $matchFound = true;
            }

            // Apply result processor if provided
            if ($resultProcessor !== null) {
                $value = $resultProcessor($value);
            }

            return [
                'value' => $value,
                'matchFound' => $matchFound,
            ];
        }

        $configArray = self::mappingConfig($config);
        $basedOnAttributeValueConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'based_on_attribute_value'
            )
        );

        // 1. Check for based_on_attribute_value.specific (highest priority)
        if (
            self::arrayKeyExists(
                'specific',
                $basedOnAttributeValueConfig
            )
        ) {
            $basedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnAttributeValueConfig,
                    'specific'
                )
            );
            $specificBasedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnConfig,
                    'specific'
                )
            );

            // Check for specific fieldtype configuration
            if (
                $excludeFieldtype !== null
                && $excludeFieldtype !== ''
                && self::arrayKeyExists(
                    $excludeFieldtype,
                    $specificBasedOnConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnConfig,
                        $excludeFieldtype
                    )
                );
                $result = self::processPatternMappings(
                    $fieldtypeMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($result[0]) : (bool) $result[0];
                    $matchFound = true;
                }
            }

            // Check for default configuration if no specific match
            if (
                ! $matchFound
                && self::arrayKeyExists(
                    'default',
                    $basedOnConfig
                )
            ) {
                $defaultPatternMappings = self::asArray(
                    self::mappingValue(
                        $basedOnConfig,
                        'default'
                    )
                );
                $result = self::processPatternMappings(
                    $defaultPatternMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($result[0]) : (bool) $result[0];
                    $matchFound = true;
                }
            }
        }

        // 2. Check for based_on_attribute_value.default (second priority)
        if (
            ! $matchFound
            && self::arrayKeyExists(
                'default',
                $basedOnAttributeValueConfig
            )
        ) {
            $basedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnAttributeValueConfig,
                    'default'
                )
            );
            $result = self::processPatternMappings(
                $basedOnConfig,
                $attrName,
                $attrValue,
                $data
            );
            if ($result !== null) {
                $value = $resultProcessor !== null ? $resultProcessor($result[0]) : (bool) $result[0];
                $matchFound = true;
            }
        }

        $basedOnOtherAttributeValueConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'based_on_other_attribute_value'
            )
        );

        // 3. Check for based_on_other_attribute_value.specific (third priority)
        if (
            ! $matchFound
            && self::arrayKeyExists(
                'specific',
                $basedOnOtherAttributeValueConfig
            )
        ) {
            $basedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherAttributeValueConfig,
                    'specific'
                )
            );
            $specificBasedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherConfig,
                    'specific'
                )
            );

            // Check for specific fieldtype configuration
            if (
                $excludeFieldtype !== null
                && $excludeFieldtype !== ''
                && self::arrayKeyExists(
                    $excludeFieldtype,
                    $specificBasedOnOtherConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnOtherConfig,
                        $excludeFieldtype
                    )
                );
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $fieldtypeMappings,
                    $data
                );
                if ($matchedValue !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($matchedValue) : (bool) $matchedValue;
                    $matchFound = true;
                }
            }

            // Check for default configuration if no specific match
            if (
                ! $matchFound
                && self::arrayKeyExists(
                    'default',
                    $basedOnOtherConfig
                )
            ) {
                $defaultOtherMappings = self::asArray(
                    self::mappingValue(
                        $basedOnOtherConfig,
                        'default'
                    )
                );
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $defaultOtherMappings,
                    $data
                );
                if ($matchedValue !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($matchedValue) : (bool) $matchedValue;
                    $matchFound = true;
                }
            }

            // Legacy structure - process each based_on_other_attribute_value configuration directly
            if (! $matchFound) {
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $basedOnOtherConfig,
                    $data
                );
                if ($matchedValue !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($matchedValue) : (bool) $matchedValue;
                    $matchFound = true;
                }
            }
        }

        // 4. Check for based_on_other_attribute_value.default (fourth priority)
        if (
            ! $matchFound
            && self::arrayKeyExists(
                'default',
                $basedOnOtherAttributeValueConfig
            )
        ) {
            $basedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherAttributeValueConfig,
                    'default'
                )
            );
            $matchedValue = self::matchModifierOtherAttributeValue(
                $basedOnOtherConfig,
                $data
            );
            if ($matchedValue !== null) {
                $value = $resultProcessor !== null ? $resultProcessor($matchedValue) : (bool) $matchedValue;
                $matchFound = true;
            }
        }

        $specificConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'specific'
            )
        );

        // 5. Check for specific fieldtype configuration (fifth priority)
        if (
            ! $matchFound
            && $excludeFieldtype !== null
            && $excludeFieldtype !== ''
            && self::arrayKeyExists(
                $excludeFieldtype,
                $specificConfig
            )
        ) {
            $specificValue = self::mappingValue(
                $specificConfig,
                $excludeFieldtype
            );
            $value = $resultProcessor !== null ? $resultProcessor($specificValue) : (bool) $specificValue;
            $matchFound = true;
        }

        // 6. Check for default configuration (lowest priority)
        if (
            ! $matchFound
            && self::arrayKeyExists(
                'default',
                $configArray
            )
        ) {
            $defaultValue = self::mappingValue(
                $configArray,
                'default'
            );
            $value = $resultProcessor !== null ? $resultProcessor($defaultValue) : (bool) $defaultValue;
            $matchFound = true;
        }

        return [
            'value' => $value,
            'matchFound' => $matchFound,
        ];
    }

    /**
     * Process modifier patterns for return-based patterns (like getExpectedKeys).
     *
     * This method is similar to processModifierPatterns but returns the value
     * directly instead of a [value, matchFound] array, making it suitable for methods
     * that need to return early when a match is found.
     *
     * @param  array<int|string, mixed>|string  $config  The configuration array or legacy string containing the modifier patterns
     * @param  mixed  $attrValue  The attribute value for based_on_attribute_value checks
     * @param  array<int|string, mixed>  $data  The field data to check for based_on_other_attribute_value conditions
     * @param  mixed  $fallbackValue  The value to return if no match is found
     * @param  ?callable  $resultProcessor  Optional callback to process the result before returning
     * @param  ?string  $excludeFieldtype  The fieldtype to exclude
     * @param  ?string  $attrName  The attribute name for based_on_attribute_value checks
     * @return mixed Returns the matched value or fallback value
     */
    protected static function processModifierPatternsWithReturn(
        mixed $config,
        mixed $attrValue,
        array $data,
        mixed $fallbackValue,
        ?callable $resultProcessor = null,
        ?string $excludeFieldtype = null,
        int|string|null $attrName = null
    ): mixed {
        if (! \is_array($config)) {
            return $resultProcessor !== null
                ? $resultProcessor($config)
                : $config;
        }

        $configArray = self::mappingConfig($config);
        $basedOnAttributeValueConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'based_on_attribute_value'
            )
        );

        // 1. Check for based_on_attribute_value.specific (highest priority)
        if (
            self::arrayKeyExists(
                'specific',
                $basedOnAttributeValueConfig
            )
        ) {
            $basedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnAttributeValueConfig,
                    'specific'
                )
            );
            $specificBasedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnConfig,
                    'specific'
                )
            );

            // Check for specific fieldtype configuration
            if (
                $excludeFieldtype !== null
                && $excludeFieldtype !== ''
                && self::arrayKeyExists(
                    $excludeFieldtype,
                    $specificBasedOnConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnConfig,
                        $excludeFieldtype
                    )
                );
                $result = self::processPatternMappings(
                    $fieldtypeMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    return $resultProcessor !== null ? $resultProcessor($result[0]) : $result[0];
                }
            }

            // Check for default configuration if no specific match
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnConfig
                )
            ) {
                $defaultPatternMappings = self::asArray(
                    self::mappingValue(
                        $basedOnConfig,
                        'default'
                    )
                );
                $result = self::processPatternMappings(
                    $defaultPatternMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    return $resultProcessor !== null ? $resultProcessor($result[0]) : $result[0];
                }
            }
        }

        // 2. Check for based_on_attribute_value.default (second priority)
        if (
            self::arrayKeyExists(
                'default',
                $basedOnAttributeValueConfig
            )
        ) {
            $basedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnAttributeValueConfig,
                    'default'
                )
            );
            $result = self::processPatternMappings(
                $basedOnConfig,
                $attrName,
                $attrValue,
                $data
            );
            if ($result !== null) {
                return $resultProcessor !== null ? $resultProcessor($result[0]) : $result[0];
            }
        }

        $basedOnOtherAttributeValueConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'based_on_other_attribute_value'
            )
        );

        // 3. Check for based_on_other_attribute_value.specific (third priority)
        if (
            self::arrayKeyExists(
                'specific',
                $basedOnOtherAttributeValueConfig
            )
        ) {
            $basedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherAttributeValueConfig,
                    'specific'
                )
            );
            $specificBasedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherConfig,
                    'specific'
                )
            );

            // Check for specific fieldtype configuration
            if (
                $excludeFieldtype !== null
                && $excludeFieldtype !== ''
                && self::arrayKeyExists(
                    $excludeFieldtype,
                    $specificBasedOnOtherConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnOtherConfig,
                        $excludeFieldtype
                    )
                );
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $fieldtypeMappings,
                    $data
                );
                if ($matchedValue !== null) {
                    return $resultProcessor !== null ? $resultProcessor($matchedValue) : $matchedValue;
                }
            }

            // Check for default configuration if no specific match
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnOtherConfig
                )
            ) {
                $defaultOtherMappings = self::asArray(
                    self::mappingValue(
                        $basedOnOtherConfig,
                        'default'
                    )
                );
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $defaultOtherMappings,
                    $data
                );
                if ($matchedValue !== null) {
                    return $resultProcessor !== null ? $resultProcessor($matchedValue) : $matchedValue;
                }
            }

            // Legacy structure - process each based_on_other_attribute_value configuration directly
            $matchedValue = self::matchModifierOtherAttributeValue(
                $basedOnOtherConfig,
                $data
            );
            if ($matchedValue !== null) {
                return $resultProcessor !== null ? $resultProcessor($matchedValue) : $matchedValue;
            }
        }

        // 4. Check for based_on_other_attribute_value.default (fourth priority)
        if (
            self::arrayKeyExists(
                'default',
                $basedOnOtherAttributeValueConfig
            )
        ) {
            $basedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherAttributeValueConfig,
                    'default'
                )
            );
            $matchedValue = self::matchModifierOtherAttributeValue(
                $basedOnOtherConfig,
                $data
            );
            if ($matchedValue !== null) {
                return $resultProcessor !== null ? $resultProcessor($matchedValue) : $matchedValue;
            }
        }

        $specificConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'specific'
            )
        );

        // 5. Check for specific fieldtype configuration (fifth priority)
        if (
            $excludeFieldtype !== null
            && $excludeFieldtype !== ''
            && self::arrayKeyExists(
                $excludeFieldtype,
                $specificConfig
            )
        ) {
            $specificValue = self::mappingValue(
                $specificConfig,
                $excludeFieldtype
            );

            return $resultProcessor !== null ? $resultProcessor($specificValue) : $specificValue;
        }

        // 6. Check for default configuration (lowest priority)
        if (
            self::arrayKeyExists(
                'default',
                $configArray
            )
        ) {
            $defaultValue = self::mappingValue(
                $configArray,
                'default'
            );

            return $resultProcessor !== null ? $resultProcessor($defaultValue) : $defaultValue;
        }

        return $fallbackValue;
    }

    /**
     * Check if there's a direct array replacement in value_translation configuration.
     *
     * This method checks if the entire array value should be replaced with a specific
     * value based on the value_translation configuration, before processing
     * individual elements.
     *
     * @param  mixed  $value  The value to check (should be an array)
     * @param  array<int|string, mixed>  $translationConfig  The value translation configuration
     * @return mixed|false The replacement value if found, false otherwise
     */
    protected static function checkForArrayReplacement(
        mixed $value,
        array $translationConfig
    ): mixed {
        if (! \is_array($value)) {
            return false;
        }

        // Check for direct array replacement in default configuration
        if (
            self::arrayKeyExists(
                'default',
                $translationConfig
            )
        ) {
            foreach (
                self::asArray(
                    self::mappingValue(
                        $translationConfig,
                        'default'
                    )
                ) as $translation
            ) {
                $translationArray = self::asArray($translation);
                $search = self::mappingValue(
                    $translationArray,
                    'search'
                );
                if (
                    self::arrayKeyExists(
                        'replace',
                        $translationArray
                    )
                    && \is_array($search)
                    && $search === $value
                ) {
                    return self::mappingValue(
                        $translationArray,
                        'replace'
                    );
                }
            }
        }

        // Check for direct array replacement when translationConfig is directly an array of translations
        if (
            ! self::arrayKeyExists(
                'default',
                $translationConfig
            )
            && ! self::arrayKeyExists(
                'specific',
                $translationConfig
            )
        ) {
            foreach (self::asArray($translationConfig) as $translation) {
                $translationArray = self::asArray($translation);
                $search = self::mappingValue(
                    $translationArray,
                    'search'
                );
                if (
                    self::arrayKeyExists(
                        'replace',
                        $translationArray
                    )
                    && \is_array($search)
                    && $search === $value
                ) {
                    return self::mappingValue(
                        $translationArray,
                        'replace'
                    );
                }
            }
        }

        // Check for direct array replacement in specific configuration
        if (
            self::arrayKeyExists(
                'specific',
                $translationConfig
            )
        ) {
            foreach (
                self::asArray(
                    self::mappingValue(
                        $translationConfig,
                        'specific'
                    )
                ) as $specificConfig
            ) {
                foreach (self::asArray($specificConfig) as $translation) {
                    $translationArray = self::asArray($translation);
                    $search = self::mappingValue(
                        $translationArray,
                        'search'
                    );
                    if (
                        self::arrayKeyExists(
                            'replace',
                            $translationArray
                        )
                        && \is_array($search)
                        && $search === $value
                    ) {
                        return self::mappingValue(
                            $translationArray,
                            'replace'
                        );
                    }
                }
            }
        }

        return false;
    }
}
