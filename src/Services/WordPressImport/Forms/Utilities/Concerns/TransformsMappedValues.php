<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Combines a value with other attribute values, validates it against a list, converts
 * file sizes, and applies the configured value transformations.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait TransformsMappedValues
{
    /**
     * Process combine_value_with_other_attribute_value configuration.
     *
     * @param  mixed  $mappedValue  The current mapped value
     * @param  array<int|string, mixed>  $config  The attribute configuration
     * @param  array<int|string, mixed>  $data  The input data
     * @return mixed The processed mapped value
     */
    protected static function processCombineValueWithOtherAttributeValue(
        mixed $mappedValue,
        array $config,
        array $data
    ) {
        if (
            ! isset($config['combine_value_with_other_attribute_value'])
            || ! \is_array($config['combine_value_with_other_attribute_value'])
        ) {
            return $mappedValue;
        }

        $combineConfig = self::asArray(
            $config['combine_value_with_other_attribute_value']
        );

        // Validate required keys
        $combinationType = self::stringValue(
            $combineConfig['combination_type'] ?? null
        );
        $attributeNames = self::asArray(
            $combineConfig['attribute_names'] ?? null
        );
        if (
            $combinationType === null
            || $attributeNames === []
        ) {
            return $mappedValue;
        }

        // Collect values from specified attributes
        $valuesToCombine = [];

        // Always include the current attribute's value first
        if (
            $mappedValue !== null
            && $mappedValue !== ''
        ) {
            if (\is_array($mappedValue)) {
                // If mappedValue is already an array, join it back to a string
                $stringParts = [];
                foreach ($mappedValue as $part) {
                    $stringParts[] = self::normalizeMixedToString($part);
                }

                $valuesToCombine[] = \implode(
                    ',',
                    $stringParts
                );
            } else { // Otherwise
                $valuesToCombine[] = self::normalizeMixedToString($mappedValue);
            }
        }

        // Then add values from the specified additional attributes
        foreach ($attributeNames as $additionalAttrName) {
            $normalizedAttrName = self::normalizeArrayKey($additionalAttrName);
            if ($normalizedAttrName === null) {
                continue;
            }

            $additionalValue = self::mappingValue(
                $data,
                $normalizedAttrName
            );
            if (
                $additionalValue !== null
                && $additionalValue !== ''
            ) {
                $valuesToCombine[] = self::normalizeMixedToString($additionalValue);
            }
        }

        // Combine values based on combination type
        $separator = self::stringValue(
            $combineConfig['string_separator'] ?? null
        );
        if (
            $combinationType === 'string'
            && $separator !== null
        ) {
            $mappedValue = \implode(
                $separator,
                $valuesToCombine
            );
        }

        // If no values were combined, return null to indicate no processing should occur
        if ($valuesToCombine === []) {
            return null;
        }

        return $mappedValue;
    }

    /**
     * Convert file size from one unit to another.
     *
     * Supports conversion between B, KB, MB, GB, TB units.
     * Input format should be like "800MB" or "1.5GB".
     * Normalizes whitespace in the input (e.g., "800 MB" becomes "800MB").
     *
     * An input that cannot be read as a size (a blank limit, a limit with no
     * unit, or text such as "abc") returns null, so the mapped attribute is
     * left out rather than written as a limit of zero. The host app's Assets
     * fieldtype reads a numeric `max_file_size` as a `max` validation rule, so
     * a zero would refuse every upload, while an absent value adds no rule.
     *
     * @param  string  $fileSize  The file size string (e.g., "800MB" or "800 MB")
     * @param  string  $targetUnit  The target unit (e.g., "KB")
     * @return string|null The converted file size as a numeric string, or null when the input cannot be read as a size
     */
    protected static function convertFileSize(
        string $fileSize,
        string $targetUnit
    ): ?string {
        // Normalize the input by removing all whitespace
        $normalizedFileSize = \preg_replace(
            '/\s+/',
            '',
            $fileSize
        ) ?? $fileSize;

        // Extract the numeric value and unit from the normalized input
        if (
            \preg_match(
                '/^(\d+(?:\.\d+)?)([KMGT]?B)$/i',
                $normalizedFileSize,
                $matches
            ) !== 1
        ) {
            // Return null if the format is invalid, so the attribute is left out
            return null;
        }

        $size = (float) $matches[1];
        $unit = \strtoupper($matches[2]);

        // Convert to bytes first
        $bytes = self::convertToBytes(
            $size,
            $unit
        );

        // Convert from bytes to target unit
        $result = self::convertFromBytes(
            $bytes,
            \strtoupper($targetUnit)
        );

        return (string) $result;
    }

    /**
     * Validate a value against a predefined validation list.
     *
     * Checks if the given value exists in the specified validation list.
     * This method is used to ensure that attribute values conform to
     * predefined sets of valid values (e.g., HTML5 autocomplete values).
     *
     * @param  string  $value  The value to validate
     * @param  string  $listKey  The key of the validation list to check against
     * @return bool True if the value is in the list, false otherwise
     */
    public static function validateValueAgainstList(
        string $value,
        string $listKey
    ): bool {
        // Check if the validation list exists
        if (! isset(static::$validationLists[$listKey])) {
            // Return false if the list doesn't exist
            return false;
        }

        // Get the validation list
        $validationList = static::$validationLists[$listKey];

        // Check if the value exists in the list
        $isValid = \in_array(
            $value,
            $validationList,
            true
        );

        return $isValid;
    }

    /**
     * Convert a size value to bytes.
     *
     * @param  float  $size  The size value
     * @param  string  $unit  The unit (B, KB, MB, GB, TB)
     * @return int The size in bytes
     */
    protected static function convertToBytes(
        float $size,
        string $unit
    ): int {
        $multipliers = [
            'B' => 1,
            'KB' => 1000,
            'MB' => 1000 * 1000,
            'GB' => 1000 * 1000 * 1000,
            'TB' => 1000 * 1000 * 1000 * 1000,
        ];

        return (int) ($size * ($multipliers[$unit] ?? 1));
    }

    /**
     * Convert bytes to a target unit.
     *
     * @param  int  $bytes  The size in bytes
     * @param  string  $targetUnit  The target unit (B, KB, MB, GB, TB)
     * @return int|float The size in the target unit
     */
    protected static function convertFromBytes(
        int $bytes,
        string $targetUnit
    ): int|float {
        $divisors = [
            'B' => 1,
            'KB' => 1000,
            'MB' => 1000 * 1000,
            'GB' => 1000 * 1000 * 1000,
            'TB' => 1000 * 1000 * 1000 * 1000,
        ];

        $result = $bytes / ($divisors[$targetUnit] ?? 1);

        // Return as integer if it's a whole number, otherwise as float
        $intResult = (int) $result;

        return $result === (float) $intResult ? $intResult : $result;
    }

    /**
     * Resolve value transformations based on fieldtype and attribute values.
     *
     * @param  array<int|string, mixed>  $valueTransformations  The value transformations configuration
     * @param  array<int|string, mixed>  $data  The field data
     * @param  string  $key  The key to get transformations for
     * @param  ?string  $fieldtype  The current fieldtype
     * @return array<int|string, mixed>|null The resolved transformation configuration for the key
     */
    protected static function resolveValueTransformations(
        array $valueTransformations,
        array $data,
        string $key,
        ?string $fieldtype
    ): ?array {
        // Check for specific_based_on_attribute_value first (most specific)
        if (
            isset($valueTransformations['specific_based_on_attribute_value'])
            && $fieldtype !== null
            && $fieldtype !== ''
        ) {
            $specificBasedOnAttributeValue = self::normalizeMixedToArray(
                $valueTransformations['specific_based_on_attribute_value']
            );
            if (self::arrayKeyExists($fieldtype, $specificBasedOnAttributeValue)) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnAttributeValue,
                        $fieldtype
                    )
                );
                foreach ($fieldtypeMappings as $attributeName => $attributeValueMappings) {
                    $normalizedAttributeName = self::normalizeArrayKey($attributeName);
                    if ($normalizedAttributeName === null) {
                        continue;
                    }

                    // Get the value of the attribute from the field data
                    $attributeValue = $data[$normalizedAttributeName] ?? null;

                    // Check if this attribute value has specific transformations for the key
                    $attributeValueMappingsArray = self::normalizeMixedToArray(
                        $attributeValueMappings
                    );
                    $attributeValueKey = self::normalizeArrayKey($attributeValue);
                    if ($attributeValueKey === null) {
                        continue;
                    }

                    $attributeValueConfig = self::mappingValue(
                        $attributeValueMappingsArray,
                        $attributeValueKey
                    );
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($attributeValueConfig)
                        )
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($attributeValueConfig),
                                $key
                            ),
                            $key
                        );
                    }
                }
            }
        }

        // Check for based_on_attribute_value
        if (isset($valueTransformations['based_on_attribute_value'])) {
            $basedOnAttributeValue = self::normalizeMixedToArray(
                $valueTransformations['based_on_attribute_value']
            );

            // Check for flat structure first (direct based_on_attribute_value)
            // This handles the case where based_on_attribute_value is used directly in value_transformations
            foreach ($data as $attrName => $attrValue) {
                $notEmptyConfig = self::mappingValue(
                    $basedOnAttributeValue,
                    '#not_empty'
                );
                if (
                    self::arrayKeyExists(
                        $key,
                        self::asArray($notEmptyConfig)
                    )
                    && (! in_array($attrValue, [null, '', []], true))
                ) {
                    $transformationValue = self::mappingValue(
                        self::asArray($notEmptyConfig),
                        $key
                    );

                    return \is_array($transformationValue) ? $transformationValue : [$key => $transformationValue];
                }

                $notSetConfig = self::mappingValue(
                    $basedOnAttributeValue,
                    '#not_set'
                );
                if (
                    self::arrayKeyExists(
                        $key,
                        self::asArray($notSetConfig)
                    )
                    && ! self::arrayKeyExists(
                        $attrName,
                        $data
                    )
                ) {
                    $transformationValue = self::mappingValue(
                        self::asArray($notSetConfig),
                        $key
                    );

                    return \is_array($transformationValue) ? $transformationValue : [$key => $transformationValue];
                }

                $emptyConfig = self::mappingValue(
                    $basedOnAttributeValue,
                    '#empty'
                );
                if (
                    self::arrayKeyExists(
                        $key,
                        self::asArray($emptyConfig)
                    )
                    && (in_array($attrValue, [null, '', []], true))
                ) {
                    $transformationValue = self::mappingValue(
                        self::asArray($emptyConfig),
                        $key
                    );

                    return \is_array($transformationValue) ? $transformationValue : [$key => $transformationValue];
                }
            }

            // Check for flat structure with transformation type as key
            // This handles the case where the key is the transformation type (like 'based_on_attribute_value')
            if ($key === 'based_on_attribute_value') {
                // Check each attribute in the data to see if any match the conditions
                foreach ($data as $attrName => $attrValue) {
                    $flatNotEmptyConfig = self::mappingValue(
                        $basedOnAttributeValue,
                        '#not_empty'
                    );
                    // Check for special conditions first
                    if (
                        $flatNotEmptyConfig !== null
                        && (! in_array($attrValue, [null, '', []], true))
                    ) {
                        return self::normalizeTransformationConfig(
                            self::asArray($flatNotEmptyConfig),
                            $key
                        );
                    }

                    $flatNotSetConfig = self::mappingValue(
                        $basedOnAttributeValue,
                        '#not_set'
                    );
                    if (
                        $flatNotSetConfig !== null
                        && ! self::arrayKeyExists(
                            $attrName,
                            $data
                        )
                    ) {
                        return self::normalizeTransformationConfig(
                            self::asArray($flatNotSetConfig),
                            $key
                        );
                    }

                    $flatEmptyConfig = self::mappingValue(
                        $basedOnAttributeValue,
                        '#empty'
                    );
                    if (
                        $flatEmptyConfig !== null
                        && (in_array($attrValue, [null, '', []], true))
                    ) {
                        return self::normalizeTransformationConfig(
                            self::asArray($flatEmptyConfig),
                            $key
                        );
                    }

                    // Then check for specific attribute values (only for scalar values)
                    if (\is_scalar($attrValue)) {
                        $attrValueKey = self::mappingLookupKey($attrValue);
                        $attrValueConfig = self::mappingValue(
                            $basedOnAttributeValue,
                            $attrValueKey
                        );
                        if ($attrValueConfig !== null) {
                            return self::normalizeTransformationConfig(
                                self::asArray($attrValueConfig),
                                $key
                            );
                        }
                    }
                }
            }

            // Check for specific first
            if (
                isset($basedOnAttributeValue['specific'])
                && $fieldtype !== null
                && $fieldtype !== ''
            ) {
                $specificConfig = self::normalizeMixedToArray(
                    $basedOnAttributeValue['specific']
                );
                if (isset($specificConfig[$fieldtype])) {
                    $fieldtypeMappings = self::normalizeMixedToArray(
                        $specificConfig[$fieldtype]
                    );
                    foreach ($fieldtypeMappings as $attributeName => $attributeValueMappings) {
                        $normalizedAttributeName = self::normalizeArrayKey($attributeName);
                        if ($normalizedAttributeName === null) {
                            continue;
                        }

                        // Get the value of the attribute from the field data
                        $attributeValue = $data[$normalizedAttributeName] ?? null;
                        $attributeValueMappingsArray = self::normalizeMixedToArray(
                            $attributeValueMappings
                        );

                        // Check for #not_set case
                        $notSetMappings = self::mappingValue(
                            $attributeValueMappingsArray,
                            '#not_set'
                        );
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($notSetMappings)
                            )
                            && ! self::arrayKeyExists(
                                $normalizedAttributeName,
                                $data
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($notSetMappings),
                                    $key
                                ),
                                $key
                            );
                        }

                        // Check for #not_empty case
                        $notEmptyMappings = self::mappingValue(
                            $attributeValueMappingsArray,
                            '#not_empty'
                        );
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($notEmptyMappings)
                            )
                            && (! in_array($attributeValue, [null, '', []], true))
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($notEmptyMappings),
                                    $key
                                ),
                                $key
                            );
                        }

                        // Check for #empty case
                        $emptyMappings = self::mappingValue(
                            $attributeValueMappingsArray,
                            '#empty'
                        );
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($emptyMappings)
                            )
                            && (in_array($attributeValue, [null, '', []], true))
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($emptyMappings),
                                    $key
                                ),
                                $key
                            );
                        }

                        // Check if this attribute value has specific transformations for the key
                        $attributeValueKey = self::normalizeArrayKey($attributeValue);
                        $attributeValueConfig = $attributeValueKey !== null
                            ? self::mappingValue(
                                $attributeValueMappingsArray,
                                $attributeValueKey
                            )
                            : null;
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($attributeValueConfig)
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($attributeValueConfig),
                                    $key
                                ),
                                $key
                            );
                        }
                    }
                }
            }

            // Check for default
            if (isset($basedOnAttributeValue['default'])) {
                $defaultConfig = self::normalizeMixedToArray(
                    $basedOnAttributeValue['default']
                );

                // First, check if the default config contains attribute values directly
                // This handles the case where default contains attribute values as keys
                foreach ($data as $attrName => $attrValue) {
                    $defaultNotEmptyConfig = self::mappingValue(
                        $defaultConfig,
                        '#not_empty'
                    );
                    // Check for special conditions first
                    if (
                        self::arrayKeyExists(
                            '#not_empty',
                            $defaultConfig
                        )
                        && (! in_array($attrValue, [null, '', []], true))
                    ) {
                        // Check if the key is the transformation type
                        if ($key === 'based_on_attribute_value') {
                            return self::normalizeTransformationConfig(
                                self::asArray($defaultNotEmptyConfig),
                                $key
                            );
                        }

                        // Check if the transformation config contains the key
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($defaultNotEmptyConfig)
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($defaultNotEmptyConfig),
                                    $key
                                ),
                                $key
                            );
                        }
                    }

                    $defaultNotSetConfig = self::mappingValue(
                        $defaultConfig,
                        '#not_set'
                    );
                    if (
                        self::arrayKeyExists(
                            '#not_set',
                            $defaultConfig
                        )
                        && ! self::arrayKeyExists(
                            $attrName,
                            $data
                        )
                    ) {
                        // Check if the key is the transformation type
                        if ($key === 'based_on_attribute_value') {
                            return self::normalizeTransformationConfig(
                                self::asArray($defaultNotSetConfig),
                                $key
                            );
                        }

                        // Check if the transformation config contains the key
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($defaultNotSetConfig)
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($defaultNotSetConfig),
                                    $key
                                ),
                                $key
                            );
                        }
                    }

                    $defaultEmptyConfig = self::mappingValue(
                        $defaultConfig,
                        '#empty'
                    );
                    if (
                        self::arrayKeyExists(
                            '#empty',
                            $defaultConfig
                        )
                        && (in_array($attrValue, [null, '', []], true))
                    ) {
                        // Check if the key is the transformation type
                        if ($key === 'based_on_attribute_value') {
                            return self::normalizeTransformationConfig(
                                self::asArray($defaultEmptyConfig),
                                $key
                            );
                        }

                        // Check if the transformation config contains the key
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($defaultEmptyConfig)
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($defaultEmptyConfig),
                                    $key
                                ),
                                $key
                            );
                        }
                    }

                    // Then check for specific attribute values (only for scalar values)
                    if (\is_scalar($attrValue)) {
                        $attrValueKey = self::mappingLookupKey($attrValue);
                        $attrValueConfig = self::mappingValue(
                            $defaultConfig,
                            $attrValueKey
                        );
                        if ($attrValueConfig !== null) {
                            // Check if the key is the transformation type
                            if ($key === 'based_on_attribute_value') {
                                return self::normalizeTransformationConfig(
                                    self::asArray($attrValueConfig),
                                    $key
                                );
                            }

                            // Check if the transformation config contains the key
                            if (
                                self::arrayKeyExists(
                                    $key,
                                    self::asArray($attrValueConfig)
                                )
                            ) {
                                return self::normalizeTransformationConfig(
                                    self::mappingValue(
                                        self::asArray($attrValueConfig),
                                        $key
                                    ),
                                    $key
                                );
                            }
                        }
                    }
                }

                // Then, check the traditional structure where default contains attribute names as keys
                foreach ($defaultConfig as $attributeName => $attributeValueMappings) {
                    $normalizedAttributeName = self::normalizeArrayKey($attributeName);
                    if ($normalizedAttributeName === null) {
                        continue;
                    }

                    // Get the value of the attribute from the field data
                    $attributeValue = $data[$normalizedAttributeName] ?? null;
                    $attributeValueMappingsArray = self::normalizeMixedToArray(
                        $attributeValueMappings
                    );

                    // Check for special conditions first
                    // Check for #not_set case
                    $notSetMappings = self::mappingValue(
                        $attributeValueMappingsArray,
                        '#not_set'
                    );
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($notSetMappings)
                        )
                        && ! self::arrayKeyExists(
                            $normalizedAttributeName,
                            $data
                        )
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($notSetMappings),
                                $key
                            ),
                            $key
                        );
                    }

                    // Check for #not_empty case
                    $notEmptyMappings = self::mappingValue(
                        $attributeValueMappingsArray,
                        '#not_empty'
                    );
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($notEmptyMappings)
                        )
                        && (! in_array($attributeValue, [null, '', []], true))
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($notEmptyMappings),
                                $key
                            ),
                            $key
                        );
                    }

                    // Check for #empty case
                    $emptyMappings = self::mappingValue(
                        $attributeValueMappingsArray,
                        '#empty'
                    );
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($emptyMappings)
                        )
                        && (in_array($attributeValue, [null, '', []], true))
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($emptyMappings),
                                $key
                            ),
                            $key
                        );
                    }

                    // Check if this attribute value has transformations for the key
                    $attributeValueKey = self::normalizeArrayKey($attributeValue);
                    $attributeValueConfig = $attributeValueKey !== null
                        ? self::mappingValue(
                            $attributeValueMappingsArray,
                            $attributeValueKey
                        )
                        : null;
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($attributeValueConfig)
                        )
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($attributeValueConfig),
                                $key
                            ),
                            $key
                        );
                    }

                    // Check if the key is the transformation type and we have a direct mapping for the attribute value
                    if ($key === 'based_on_attribute_value') {
                        $directMapping = $attributeValueKey !== null
                            ? self::tryMappingValue(
                                $attributeValueMappingsArray,
                                $attributeValueKey
                            )
                            : null;
                        if ($directMapping !== null) {
                            return self::normalizeTransformationConfig(
                                $directMapping,
                                $key
                            );
                        }
                    }
                }
            }
        }

        // Check for based_on_other_attribute_value
        if (isset($valueTransformations['based_on_other_attribute_value'])) {
            $basedOnOtherAttributeValue = self::normalizeMixedToArray(
                $valueTransformations['based_on_other_attribute_value']
            );
            foreach ($basedOnOtherAttributeValue as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey($otherAttrName);
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;
                $valueMappingsArray = self::normalizeMixedToArray($valueMappings);
                $otherAttrValueKey = self::normalizeArrayKey($otherAttrValue);
                $otherAttrValueConfig = $otherAttrValueKey !== null
                    ? self::mappingValue(
                        $valueMappingsArray,
                        $otherAttrValueKey
                    )
                    : null;
                if (
                    self::arrayKeyExists(
                        $key,
                        self::asArray($otherAttrValueConfig)
                    )
                ) {
                    return self::normalizeTransformationConfig(
                        self::mappingValue(
                            self::asArray($otherAttrValueConfig),
                            $key
                        ),
                        $key
                    );
                }
            }
        }

        // Check for fieldtype-specific transformations
        if (
            isset($valueTransformations['specific'])
            && $fieldtype !== null
            && $fieldtype !== ''
        ) {
            $specificConfig = self::normalizeMixedToArray(
                $valueTransformations['specific']
            );
            $fieldtypeConfig = self::mappingValue(
                $specificConfig,
                $fieldtype
            );
            if (
                self::arrayKeyExists(
                    $key,
                    self::asArray($fieldtypeConfig)
                )
            ) {
                return self::normalizeTransformationConfig(
                    self::mappingValue(
                        self::asArray($fieldtypeConfig),
                        $key
                    ),
                    $key
                );
            }
        }

        // Check for default transformations
        $defaultTransformations = self::asArray(
            self::mappingValue($valueTransformations, 'default')
        );
        if (self::arrayKeyExists($key, $defaultTransformations)) {
            return self::normalizeTransformationConfig(
                self::mappingValue(
                    $defaultTransformations,
                    $key
                ),
                $key
            );
        }

        // Check for simple structure (legacy)
        if (self::arrayKeyExists($key, $valueTransformations)) {
            return self::normalizeTransformationConfig(
                self::mappingValue(
                    $valueTransformations,
                    $key
                ),
                $key
            );
        }

        return null;
    }

    /**
     * Apply a value transformation to a given value.
     *
     * @param  mixed  $value  The value to transform
     * @param  array<int|string, mixed>  $transformationConfig  The transformation configuration
     * @param  array<int|string, mixed>|null  $data  Optional data array for generate_value transformations
     * @param  ?int  $count  Optional count for generate_value transformations
     * @return mixed The transformed value
     */
    protected static function applyValueTransformation(
        mixed $value,
        array $transformationConfig,
        ?array $data = null,
        ?int $count = null
    ): mixed {
        // Apply dollar amount conversion if configured
        if (
            isset($transformationConfig['convert_dollar_amount'])
            && $transformationConfig['convert_dollar_amount'] === true
            && \is_string($value)
        ) {
            $value = (float) \str_replace(
                ['$', ','],
                '',
                $value
            );
        }

        // Set value to null if configured
        if (
            isset($transformationConfig['set_to_null'])
            && $transformationConfig['set_to_null'] === true
        ) {
            $value = null;
        }

        // Generate value if configured
        if (
            isset($transformationConfig['generate_value'])
            && \is_array($transformationConfig['generate_value'])
        ) {
            $value = self::generateValue(
                $transformationConfig['generate_value'],
                $data ?? [],
                $count ?? 1
            );
        }

        // Transform value to ISO 8601 date format if configured
        if (
            isset($transformationConfig['as_date_iso_8601'])
            && $transformationConfig['as_date_iso_8601'] === true
        ) {
            $value = self::transformToIso8601Date($value);
        }

        // Transform value to complex array if configured
        if (
            isset($transformationConfig['as_complex_array'])
            && \is_array($transformationConfig['as_complex_array'])
        ) {
            $value = self::transformToComplexArray(
                $value,
                $transformationConfig['as_complex_array'],
                $data ?? [],
                $count ?? 1
            );
        }

        // Transform numeric values to numeric strings if configured
        if (
            isset($transformationConfig['as_numeric_string'])
            && $transformationConfig['as_numeric_string'] === true
        ) {
            return self::transformToNumericString($value);
        }

        return $value;
    }

    /**
     * Generate a value based on the generate_value configuration.
     *
     * @param  array<int|string, mixed>  $generateConfig  Array of generation rules
     * @param  array<int|string, mixed>  $data  The data array to extract values from
     * @param  int  $count  The current count/index
     * @return string The generated value
     */
    protected static function generateValue(
        array $generateConfig,
        array $data,
        int $count
    ): string {
        $parts = [];

        foreach ($generateConfig as $rule) {
            if (
                ! \is_array($rule)
                || \count($rule) !== 1
            ) {
                continue; // Skip invalid rules
            }

            $key = array_key_first($rule);
            $normalizedKey = self::normalizeArrayKey($key);
            if ($normalizedKey === null) {
                continue;
            }

            $value = $rule[$normalizedKey];

            switch ($normalizedKey) {
                case 'value':
                    if ($value === '#field_handle') {
                        // Get the field handle from the data
                        $fieldName = self::normalizeMixedToString(
                            static::getFieldNameFromField($data)
                        );
                        $fieldId = self::normalizeMixedToString(
                            $data['id'] ?? ''
                        );
                        $parts[] = static::convertFieldNameToHandle(
                            $fieldName,
                            $fieldId
                        );
                    } elseif ($value === '#count') {
                        $parts[] = (string) $count;
                    } else { // Otherwise
                        $parts[] = self::normalizeMixedToString($value);
                    }

                    break;

                case 'attribute_value_sluggified':
                    $attributeValue = self::getNestedAttributeValue(
                        $data,
                        self::normalizeMixedToString($value)
                    );
                    $parts[] = self::sluggifyValue($attributeValue);
                    break;

                case 'attribute_value_snake_case':
                    $attributeValue = self::getNestedAttributeValue(
                        $data,
                        self::normalizeMixedToString($value)
                    );
                    $parts[] = self::snakeCaseValue($attributeValue);
                    break;
            }
        }

        return \implode(
            '_',
            $parts
        );
    }
}
