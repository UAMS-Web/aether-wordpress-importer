<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Maps the #not_set cases: attributes in the mapping that the input data does not carry,
 * and the value translations configured for them.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait MapsNotSetAttributes
{
    /**
     * Map the #not_set cases for attributes in the mapping that the input data does not carry.
     *
     * mapAttributesFromMapping()'s second loop, moved into its own method (#556) so that the
     * file holding that method creates few enough mutants to finish inside the CI mutation
     * job's limit. Missing attributes with #not_set value translations are processed even
     * when $originalFieldData is null.
     *
     * @param  array<int|string, mixed>  $data  The input data (field or subfield)
     * @param  array<int|string, mixed>  $mapping  The mapping array (attribute name => mapping config)
     * @param  array<int|string, mixed>  $mappedData  The attributes mapped so far
     * @param  ?string  $handle  Optional handle for wildcard replacement
     * @param  ?string  $excludeFieldtype  Optional source fieldtype for exclude_fieldtypes logic
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for #not_set checks
     * @return array<int|string, mixed> The mapped attributes with the #not_set cases added
     */
    protected static function mapNotSetAttributes(
        array $data,
        array $mapping,
        array $mappedData,
        ?string $handle,
        ?string $excludeFieldtype,
        ?array $originalFieldData
    ): array {
        foreach ($mapping as $attrName => $attrConfig) {
            // Skip if this attribute already exists in the data (already processed above)
            if (
                self::arrayKeyExists(
                    $attrName,
                    $data
                )
            ) {
                continue;
            }

            // Get the appropriate configuration based on fieldtype
            $config = static::getFieldtypeSpecificConfig(
                self::asArray($attrConfig),
                $excludeFieldtype
            );

            // Check exclude_fieldtypes logic
            if (
                static::shouldExcludeFieldtype(
                    $config,
                    $excludeFieldtype
                )
            ) {
                continue;
            }

            // Check if this attribute has a statamic_attribute mapping
            $statamicAttr = $config['statamic_attribute'] ?? null;
            $statamicAttrName = null;

            // Handle both string and array statamic_attribute configurations
            if (\is_string($statamicAttr)) {
                $statamicAttrName = $statamicAttr;
            } elseif (\is_array($statamicAttr)) {
                // Normalize the specific overrides to an array for safe offset access
                $specificStatamicAttr = self::asArray($statamicAttr['specific'] ?? null);
                // Check for specific fieldtype mapping first
                if ($excludeFieldtype !== null && isset($specificStatamicAttr[$excludeFieldtype])) {
                    $statamicAttrName = $specificStatamicAttr[$excludeFieldtype];
                } elseif (isset($statamicAttr['default'])) {
                    $statamicAttrName = $statamicAttr['default'];
                }
            }

            // Only process if we have a statamic attribute name and it's not null
            if ($statamicAttrName !== null && $statamicAttrName !== '') {
                // Check for value_translation with #not_set
                if (isset($config['value_translation'])) {
                    $valueTranslationConfig = $config['value_translation'];
                    $transformationConfig = static::getFieldtypeSpecificConfig(
                        self::asArray($valueTranslationConfig),
                        $excludeFieldtype
                    );

                    // Normalize the value translation config for safe offset access
                    $valueTranslationArr = self::asArray($valueTranslationConfig);
                    $specificTranslation = self::asArray(
                        $valueTranslationArr['specific'] ?? null
                    );

                    // If getFieldtypeSpecificConfig didn't work, try direct access to specific fieldtype
                    if (
                        $transformationConfig === []
                        && $excludeFieldtype !== null
                        && isset($specificTranslation[$excludeFieldtype])
                    ) {
                        $transformationConfig = self::asArray(
                            $specificTranslation[$excludeFieldtype]
                        );
                    }

                    // If still empty, the valueTranslationConfig might already be the fieldtype-specific config
                    $firstTranslation = self::asArray($valueTranslationArr[0] ?? null);
                    if (
                        $transformationConfig === []
                        && \is_array($valueTranslationConfig)
                        && isset($firstTranslation['search'])
                    ) {
                        $transformationConfig = $valueTranslationConfig;
                    }

                    if ($transformationConfig !== []) {
                        // Look for #not_set translation
                        foreach (self::asArray($transformationConfig) as $translation) {
                            // Narrow each translation entry to an array
                            $translationEntry = self::asArray($translation);
                            if (isset($translationEntry['search']) && $translationEntry['search'] === '#not_set') {
                                // Normalize the attribute name before using it as an array key
                                $statamicAttrKey = self::normalizeArrayKey($statamicAttrName);
                                if ($statamicAttrKey !== null) {
                                    $mappedData[$statamicAttrKey] = $translationEntry['replace'];
                                }

                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($originalFieldData !== null) {
            foreach ($mapping as $attrName => $attrConfig) {
                // Skip if this attribute already exists in the data (already processed above)
                if (
                    self::arrayKeyExists(
                        $attrName,
                        $data
                    )
                ) {
                    continue;
                }

                // Normalize the attribute config to an array for safe offset access
                $attrConfigArr = self::asArray($attrConfig);

                // Get the appropriate configuration based on fieldtype
                $config = static::getFieldtypeSpecificConfig(
                    $attrConfigArr,
                    $excludeFieldtype
                );

                // Check exclude_fieldtypes logic
                if (
                    static::shouldExcludeFieldtype(
                        $config,
                        $excludeFieldtype
                    )
                ) {
                    continue;
                }

                // Skip if there is no Statamic attribute to map to and no other field value mappings
                if (
                    ! isset($config['statamic_attribute'])
                    && ! isset($attrConfigArr['statamic_other_field_value'])
                    && ! isset($attrConfigArr['statamic_other_field_value_based_on_attribute_value'])
                    && ! isset($attrConfigArr['statamic_other_field_value_based_on_nested_attribute_value'])
                    && ! isset($attrConfigArr['statamic_type_based_on_attribute_value'])
                ) {
                    continue;
                }

                // Support '*' as a placeholder for the subfield handle in statamic_attribute
                $statamicAttr = $config['statamic_attribute'] ?? null;

                // Ensure statamicAttr is a valid string (only if we need it for value_translation)
                if (
                    isset($config['value_translation'])
                    && (
                        ! \is_string($statamicAttr)
                        || $statamicAttr === ''
                    )
                ) {
                    continue;
                }

                if ($handle !== null && $handle !== '' && \is_string($statamicAttr)) {
                    $statamicAttr = self::replacePlaceholders(
                        $statamicAttr,
                        $handle
                    );
                    // For array statamicAttr (complex structures), placeholder replacement
                    // is handled in the processSubfieldStatamicAttribute method
                }

                // Check for #not_set in value_translation
                if (
                    isset($config['value_translation'])
                    && \is_array($config['value_translation'])
                    && self::arrayKeyExists(
                        '#not_set',
                        $config['value_translation']
                    )
                ) {
                    // Check if the attribute is not set in field options
                    if (
                        self::isFieldOptionNotSet(
                            $attrName,
                            $originalFieldData
                        )
                    ) {
                        $mappedValue = $config['value_translation']['#not_set'];

                        // Convert empty strings to null after all processing
                        if ($mappedValue === '') {
                            $mappedValue = null;
                        }

                        // Apply deduplication if configured
                        if (
                            isset($config['deduplicate_values'])
                            && $config['deduplicate_values'] === true
                            && \is_array($mappedValue)
                        ) {
                            $mappedValue = \array_values(array_unique($mappedValue, SORT_REGULAR));
                        }

                        // Apply value transformations if configured
                        if (isset($config['value_transformations']) && $mappedValue !== null) {
                            // Normalize the transformations config to an array
                            $valueTransformations = self::asArray(
                                $config['value_transformations']
                            );

                            // Apply value transformations using the resolveValueTransformations method
                            // This method handles all the different transformation structures
                            $transformationConfig = static::resolveValueTransformations(
                                $valueTransformations,
                                $data,
                                'based_on_attribute_value',
                                $excludeFieldtype
                            );
                            if ($transformationConfig !== null && $transformationConfig !== []) {
                                $mappedValue = static::applyValueTransformation(
                                    $mappedValue,
                                    $transformationConfig,
                                    $data,
                                    1
                                );
                            }
                        }

                        // Set the value (null values will be filtered out later)
                        $statamicAttrKey = self::normalizeArrayKey($statamicAttr);
                        if ($statamicAttrKey !== null) {
                            $mappedData[$statamicAttrKey] = $mappedValue;
                        }
                    }
                }

                // Check for #not_set in statamic_other_field_value_based_on_attribute_value
                if (isset($attrConfigArr['statamic_other_field_value_based_on_attribute_value'])) {
                    $otherFieldMappings = $attrConfigArr['statamic_other_field_value_based_on_attribute_value'];

                    // Handle the new structure with default and specific overrides
                    if (
                        \is_array($otherFieldMappings)
                        && self::arrayKeyExists(
                            'default',
                            $otherFieldMappings
                        )
                    ) {
                        // New structure: ['default' => [pattern => fieldValues], 'specific' => [fieldtype => [pattern => fieldValues]]]
                        $defaultMappings = self::asArray($otherFieldMappings['default'] ?? null);
                        $specificMappings = self::asArray($otherFieldMappings['specific'] ?? null);

                        // Process default mappings for #not_set
                        if (isset($defaultMappings['#not_set'])) {
                            // Check if the attribute is not set in field options
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                foreach (self::asArray($defaultMappings['#not_set']) as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }
                            }
                        }

                        // Process fieldtype-specific mappings for #not_set
                        $specificFieldtypeMappings = self::asArray(
                            $excludeFieldtype !== null ? ($specificMappings[$excludeFieldtype] ?? null) : null
                        );
                        if (
                            $excludeFieldtype !== ''
                            && isset($specificFieldtypeMappings['#not_set'])
                        ) {
                            // Check if the attribute is not set in field options
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                foreach (self::asArray($specificFieldtypeMappings['#not_set']) as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }
                            }
                        }
                    } else { // Otherwise
                        // Legacy structure: direct pattern => fieldValues mapping
                        $otherFieldMappingsArr = self::asArray($otherFieldMappings);
                        if (isset($otherFieldMappingsArr['#not_set'])) {
                            // Check if the attribute is not set in field options
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                foreach (self::asArray($otherFieldMappingsArr['#not_set']) as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }
                            }
                        }
                    }
                }

                // Check for #not_set in statamic_attribute.specific_based_on_attribute_value
                $statamicAttributeArr = self::asArray($attrConfigArr['statamic_attribute'] ?? null);
                if (isset($statamicAttributeArr['specific_based_on_attribute_value'])) {
                    $specificMappings = self::asArray($statamicAttributeArr['specific_based_on_attribute_value']);

                    // Handle fieldtype-specific structure
                    $specificFieldtypeMappings = self::asArray(
                        $excludeFieldtype !== null ? ($specificMappings[$excludeFieldtype] ?? null) : null
                    );
                    if (
                        $excludeFieldtype !== ''
                        && isset($specificFieldtypeMappings['#not_set'])
                    ) {
                        // Check if the attribute is not set in field options
                        if (
                            self::isFieldOptionNotSet(
                                $attrName,
                                $originalFieldData
                            )
                        ) {
                            $targetAttribute = $specificFieldtypeMappings['#not_set'];
                            // Replace wildcards in the target attribute
                            $resolvedTargetAttribute = self::resolveWildcards(
                                self::stringValue($targetAttribute) ?? '',
                                '',
                                '#not_set'
                            );
                            $mappedData[$resolvedTargetAttribute] = '';
                        }
                    }
                }

                // Check for #not_set in statamic_type_based_on_attribute_value
                $statamicTypeMappings = self::asArray(
                    $attrConfigArr['statamic_type_based_on_attribute_value'] ?? null
                );
                if (isset($statamicTypeMappings['#not_set'])) {
                    // Check if the attribute is not set in field options
                    if (
                        self::isFieldOptionNotSet(
                            $attrName,
                            $originalFieldData
                        )
                    ) {
                        $mappedData['type'] = $statamicTypeMappings['#not_set'];
                    }
                }

                // Check for #not_set in value_calculation
                if (
                    isset($attrConfigArr['value_calculation'])
                    && \is_array($attrConfigArr['value_calculation'])
                ) {
                    $calculationConfig = $attrConfigArr['value_calculation'];
                    $expression = null;

                    // Check for specific_based_on_attribute_value first (most specific)
                    if (
                        isset($calculationConfig['specific_based_on_attribute_value'])
                        && $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                    ) {
                        $specificMappings = self::asArray(
                            $calculationConfig['specific_based_on_attribute_value']
                        );
                        $specificFieldtypeMappings = self::asArray(
                            $specificMappings[$excludeFieldtype] ?? null
                        );
                        if (isset($specificFieldtypeMappings['#not_set'])) {
                            // Check if the attribute is not set in field options
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                $expression = $specificFieldtypeMappings['#not_set'];
                            }
                        }
                    }

                    // Check for based_on_other_attribute_value if no specific match found
                    if (
                        $expression === null
                        && isset($calculationConfig['based_on_other_attribute_value'])
                    ) {
                        $basedOnConfig = $calculationConfig['based_on_other_attribute_value'];

                        // Handle the new structure with default and specific overrides
                        if (
                            \is_array($basedOnConfig)
                            && (
                                self::arrayKeyExists(
                                    'default',
                                    $basedOnConfig
                                )
                                || self::arrayKeyExists(
                                    'specific',
                                    $basedOnConfig
                                )
                            )
                        ) {
                            // New structure: ['default' => [attr => valueMappings], 'specific' => [fieldtype => [attr => valueMappings]]]
                            $defaultMappings = self::asArray($basedOnConfig['default'] ?? null);
                            $specificMappings = self::asArray($basedOnConfig['specific'] ?? null);

                            // Process default mappings first
                            foreach ($defaultMappings as $otherAttrName => $valueMappings) {
                                $otherAttrValue = $originalFieldData[$otherAttrName] ?? null;
                                // Narrow the value mappings to an array before reading offsets
                                $valueMappingsArr = self::asArray($valueMappings);
                                if (
                                    isset($valueMappingsArr['#not_set'])
                                    && $otherAttrValue === null
                                ) {
                                    // Check if the attribute is not set in field options
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        $expression = $valueMappingsArr['#not_set'];
                                        break;
                                    }
                                }
                            }

                            // Process fieldtype-specific mappings if no default match found
                            if (
                                $expression === null
                                && $excludeFieldtype !== null
                                && $excludeFieldtype !== ''
                                && isset($specificMappings[$excludeFieldtype])
                            ) {
                                $fieldtypeMappings = self::asArray($specificMappings[$excludeFieldtype]);
                                foreach ($fieldtypeMappings as $otherAttrName => $valueMappings) {
                                    $otherAttrValue = $originalFieldData[$otherAttrName] ?? null;
                                    // Narrow the value mappings to an array before reading offsets
                                    $valueMappingsArr = self::asArray($valueMappings);
                                    if (
                                        isset($valueMappingsArr['#not_set'])
                                        && $otherAttrValue === null
                                    ) {
                                        // Check if the attribute is not set in field options
                                        if (
                                            self::isFieldOptionNotSet(
                                                $attrName,
                                                $originalFieldData
                                            )
                                        ) {
                                            $expression = $valueMappingsArr['#not_set'];
                                            break;
                                        }
                                    }
                                }
                            }
                        } else { // Otherwise
                            // Legacy structure: direct attr => valueMappings mapping
                            foreach (self::asArray($basedOnConfig) as $otherAttrName => $valueMappings) {
                                $otherAttrValue = $originalFieldData[$otherAttrName] ?? null;
                                // Narrow the value mappings to an array before reading offsets
                                $valueMappingsArr = self::asArray($valueMappings);
                                if (
                                    isset($valueMappingsArr['#not_set'])
                                    && $otherAttrValue === null
                                ) {
                                    // Check if the attribute is not set in field options
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        $expression = $valueMappingsArr['#not_set'];
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    // Use default if no other match found
                    if (
                        $expression === null
                        && isset($calculationConfig['default'])
                    ) {
                        // Check if the attribute is not set in field options
                        if (
                            self::isFieldOptionNotSet(
                                $attrName,
                                $originalFieldData
                            )
                        ) {
                            $expression = $calculationConfig['default'];
                        }
                    }

                    // Apply the calculation if we found an expression and have a statamic attribute
                    if (
                        $expression !== null
                        && \is_string($expression)
                        && isset($config['statamic_attribute'])
                        && \is_string($config['statamic_attribute'])
                    ) {
                        // For missing attributes, we need to provide a default value to calculate with
                        // We'll use 0 as the default value for calculations
                        $calculatedValue = self::applyValueCalculation(
                            0,
                            $expression
                        );

                        // Only add the attribute if the calculated value is not null and not 0
                        // This prevents adding attributes with meaningless default values
                        if (
                            $calculatedValue !== null
                            && $calculatedValue !== 0
                        ) {
                            $mappedData[$config['statamic_attribute']] = $calculatedValue;
                        }
                    }
                }
            }
        }

        return $mappedData;
    }

    /**
     * Apply #not_set value translations for attributes in the mapping that the input data does not carry.
     *
     * mapAttributesFromMapping()'s third loop, moved into its own method (#556) so that the
     * file holding that method creates few enough mutants to finish inside the CI mutation
     * job's limit.
     *
     * @param  array<int|string, mixed>  $data  The input data (field or subfield)
     * @param  array<int|string, mixed>  $mapping  The mapping array (attribute name => mapping config)
     * @param  array<int|string, mixed>  $mappedData  The attributes mapped so far
     * @param  ?string  $excludeFieldtype  Optional source fieldtype for exclude_fieldtypes logic
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for #not_set checks
     * @return array<int|string, mixed> The mapped attributes with the #not_set translations added
     */
    protected static function mapNotSetValueTranslations(
        array $data,
        array $mapping,
        array $mappedData,
        ?string $excludeFieldtype,
        ?array $originalFieldData
    ): array {
        foreach ($mapping as $attrName => $attrConfig) {
            // Skip if this attribute already exists in the data (was processed in first pass)
            if (
                self::arrayKeyExists(
                    $attrName,
                    $data
                )
            ) {
                continue;
            }

            // Get the appropriate configuration based on fieldtype
            $config = static::getFieldtypeSpecificConfig(
                self::asArray($attrConfig),
                $excludeFieldtype,
                $attrName
            );

            // Check exclude_fieldtypes logic
            if (
                static::shouldExcludeFieldtype(
                    $config,
                    $excludeFieldtype
                )
            ) {
                continue;
            }

            // Skip if there is no Statamic attribute to map to
            if (
                ! isset($config['statamic_attribute'])
            ) {
                continue;
            }

            // Only process attributes that have #not_set or #not_empty cases in value_translation
            $hasNotSetCases = false;
            if (
                isset($config['value_translation'])
                && \is_array($config['value_translation'])
            ) {
                $translationConfig = $config['value_translation'];

                // Check for simple structure with #not_set or #not_empty
                if (
                    self::arrayKeyExists(
                        '#not_set',
                        $translationConfig
                    )
                    || self::arrayKeyExists(
                        '#not_empty',
                        $translationConfig
                    )
                ) {
                    $hasNotSetCases = true;
                } elseif (
                    self::arrayKeyExists(
                        'default',
                        $translationConfig
                    )
                    || self::arrayKeyExists(
                        'specific',
                        $translationConfig
                    )
                    || self::arrayKeyExists(
                        'based_on_other_attribute_value',
                        $translationConfig
                    )
                ) { // Check for complex structure with #not_set or #not_empty

                    $defaultTranslations = self::asArray($translationConfig['default'] ?? null);
                    $specificTranslations = self::asArray($translationConfig['specific'] ?? null);
                    $basedOnOtherAttributeValue = self::asArray($translationConfig['based_on_other_attribute_value'] ?? null);

                    // Check default translations
                    if (
                        self::arrayKeyExists(
                            '#not_set',
                            $defaultTranslations
                        )
                        || self::arrayKeyExists(
                            '#not_empty',
                            $defaultTranslations
                        )
                    ) {
                        $hasNotSetCases = true;
                    } elseif ($excludeFieldtype !== null && $excludeFieldtype !== '' && isset($specificTranslations[$excludeFieldtype])) { // Check specific translations
                        $fieldtypeTranslations = $specificTranslations[$excludeFieldtype];
                        if (
                            self::arrayKeyExists(
                                '#not_set',
                                $fieldtypeTranslations
                            )
                            || self::arrayKeyExists(
                                '#not_empty',
                                $fieldtypeTranslations
                            )
                        ) {
                            $hasNotSetCases = true;
                        }
                    } elseif ($basedOnOtherAttributeValue !== []) { // Check based_on_other_attribute_value
                        foreach ($basedOnOtherAttributeValue as $valueMappings) {
                            if (
                                self::arrayKeyExists(
                                    '#not_set',
                                    $valueMappings
                                )
                                || self::arrayKeyExists(
                                    '#not_empty',
                                    $valueMappings
                                )
                                || self::arrayKeyExists(
                                    '#empty',
                                    $valueMappings
                                )
                            ) {
                                $hasNotSetCases = true;
                                break;
                            }
                        }
                    }
                }
            }

            // Skip if no #not_set or #not_empty cases found
            if (! $hasNotSetCases) {
                continue;
            }

            // Handle value translation for #not_set case
            $translationConfig = $config['value_translation'];
            $translationArray = null;

            // Whether the translation is a plain table rather than one split by fieldtype or attribute
            $isPlainTranslation = false;

            // Handle different value_translation structures
            if (
                self::arrayKeyExists(
                    'default',
                    $translationConfig
                )
                || self::arrayKeyExists(
                    'specific',
                    $translationConfig
                )
                || self::arrayKeyExists(
                    'based_on_other_attribute_value',
                    $translationConfig
                )
            ) {

                // Complex structure with 'default', 'specific', 'based_on_other_attribute_value', etc.
                $defaultTranslations = self::asArray($translationConfig['default'] ?? null);
                $specificTranslations = self::asArray($translationConfig['specific'] ?? null);
                $basedOnOtherAttributeValue = self::asArray($translationConfig['based_on_other_attribute_value'] ?? null);

                // Check for based_on_other_attribute_value first (most specific)
                if ($basedOnOtherAttributeValue !== []) {
                    foreach ($basedOnOtherAttributeValue as $otherAttrName => $valueMappings) {
                        $otherAttrValue = $data[$otherAttrName] ?? null;

                        // Find the appropriate mapping based on the other attribute's value
                        $currentValueMappings = null;

                        // Check for '#not_set' special case first
                        if (
                            self::arrayKeyExists(
                                '#not_set',
                                $valueMappings
                            )
                        ) {
                            if (
                                self::isAttributeNotSet(
                                    $otherAttrName,
                                    $data
                                )
                            ) {
                                $currentValueMappings = self::mappingValue($valueMappings, '#not_set');
                            }
                        } elseif (
                            self::arrayKeyExists(
                                '#not_empty',
                                $valueMappings
                            )
                        ) { // Check for '#not_empty' special case
                            if (
                                self::isAttributeNotEmpty(
                                    $otherAttrName,
                                    $data
                                )
                            ) {
                                $currentValueMappings = self::mappingValue($valueMappings, '#not_empty');
                            }
                        } elseif (
                            self::arrayKeyExists(
                                '#empty',
                                $valueMappings
                            )
                        ) { // Check for '#empty' special case
                            if (
                                self::isAttributeEmpty(
                                    $otherAttrName,
                                    $data
                                )
                            ) {
                                $currentValueMappings = self::mappingValue($valueMappings, '#empty');
                            }
                        } elseif (
                            self::arrayKeyExists(
                                $otherAttrValue,
                                $valueMappings
                            )
                        ) { // Check for exact value match
                            $currentValueMappings = self::mappingValue($valueMappings, $otherAttrValue);
                        }

                        // If we found mappings for the other attribute's value, apply them to this attribute
                        if ($currentValueMappings !== null) {
                            // Check for '#not_set' special case first
                            if (
                                self::arrayKeyExists(
                                    '#not_set',
                                    $currentValueMappings
                                )
                            ) {
                                if ($originalFieldData !== null) {
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        $translationArray = self::mappingValue($currentValueMappings, '#not_set');
                                        break;
                                    }
                                } else { // Otherwise
                                    if (
                                        self::isAttributeNotSet(
                                            $attrName,
                                            $data
                                        )
                                    ) {
                                        $translationArray = self::mappingValue($currentValueMappings, '#not_set');
                                        break;
                                    }
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '#not_empty',
                                    $currentValueMappings
                                )
                            ) { // Check for '#not_empty' special case
                                if (
                                    self::isAttributeNotEmptyWithFallback(
                                        $attrName,
                                        $data,
                                        $originalFieldData
                                    )
                                ) {
                                    $translationArray = self::mappingValue($currentValueMappings, '#not_empty');
                                    break;
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '#empty',
                                    $currentValueMappings
                                )
                            ) { // Check for '#empty' special case
                                if (
                                    self::isAttributeEmptyWithFallback(
                                        $attrName,
                                        $data,
                                        $originalFieldData
                                    )
                                ) {
                                    $translationArray = self::mappingValue($currentValueMappings, '#empty');
                                    break;
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '',
                                    $currentValueMappings
                                )
                            ) { // Check for exact value match (shouldn't happen for missing attributes)
                                $translationArray = self::mappingValue($currentValueMappings, '');
                                break;
                            }
                        }
                    }
                } elseif (
                    $excludeFieldtype !== null
                    && $excludeFieldtype !== ''
                    && isset($specificTranslations[$excludeFieldtype])
                ) { // Check for fieldtype-specific translations
                    $translationArray = $specificTranslations[$excludeFieldtype];
                } else { // Fall back to default translations
                    $translationArray = $defaultTranslations;
                }
            } else { // Otherwise
                // Simple array structure
                $translationArray = $translationConfig;
                $isPlainTranslation = true;
            }

            // Apply translations if we have a valid translation array
            if ($translationArray !== null) {
                // Ensure $translationArray is an array before using array_key_exists
                if (\is_array($translationArray)) {
                    // Check for '#not_set' special case first, writing nothing for an empty
                    // target, as the earlier passes over missing attributes do
                    if (
                        self::arrayKeyExists(
                            '#not_set',
                            $translationArray
                        )
                        && $config['statamic_attribute'] !== ''
                    ) {
                        if ($originalFieldData !== null) {
                            // A plain table with a string target was handled by the pass over
                            // the original field data, which writes the translation with the
                            // subfield handle, deduplication and transformations applied.
                            // Writing it again here would replace that value, or add a second
                            // key that still holds the `*`.
                            $writtenFromOriginalFieldData = $isPlainTranslation
                                && \is_string($config['statamic_attribute']);

                            if (
                                ! $writtenFromOriginalFieldData
                                && self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                // Resolve the statamic_attribute before using it as an array key
                                $resolvedMapping = self::processAttributeMapping(
                                    (string) $attrName,
                                    null,
                                    $config,
                                    $data,
                                    null,
                                    null
                                );
                                if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                    $mappedData = \array_merge(
                                        $mappedData,
                                        $resolvedMapping
                                    );
                                }
                            }
                        } else { // Otherwise
                            if (
                                self::isAttributeNotSet(
                                    $attrName,
                                    $data
                                )
                            ) {
                                // Resolve the statamic_attribute before using it as an array key
                                $resolvedMapping = self::processAttributeMapping(
                                    (string) $attrName,
                                    null,
                                    $config,
                                    $data,
                                    null,
                                    null
                                );
                                if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                    $mappedData = \array_merge(
                                        $mappedData,
                                        $resolvedMapping
                                    );
                                }
                            }
                        }
                    }

                    // Check for '#not_empty' special case
                    if (
                        self::arrayKeyExists(
                            '#not_empty',
                            $translationArray
                        )
                    ) {
                        if (
                            self::isAttributeNotEmptyWithFallback(
                                $attrName,
                                $data,
                                $originalFieldData
                            )
                        ) {
                            // Resolve the statamic_attribute before using it as an array key
                            $resolvedMapping = self::processAttributeMapping(
                                (string) $attrName,
                                null,
                                $config,
                                $data,
                                null,
                                null
                            );
                            if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                $mappedData = \array_merge(
                                    $mappedData,
                                    $resolvedMapping
                                );
                            }
                        }
                    }

                    // Check for '#empty' special case
                    if (
                        self::arrayKeyExists(
                            '#empty',
                            $translationArray
                        )
                    ) {
                        if (
                            self::isAttributeEmptyWithFallback(
                                $attrName,
                                $data,
                                $originalFieldData
                            )
                        ) {
                            // Resolve the statamic_attribute before using it as an array key
                            $resolvedMapping = self::processAttributeMapping(
                                (string) $attrName,
                                null,
                                $config,
                                $data,
                                null,
                                null
                            );
                            if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                $mappedData = \array_merge(
                                    $mappedData,
                                    $resolvedMapping
                                );
                            }
                        }
                    }

                    // Check for null value (for missing attributes)
                    if (
                        self::arrayKeyExists(
                            'null',
                            $translationArray
                        )
                    ) {
                        // Resolve the statamic_attribute before using it as an array key
                        $resolvedMapping = self::processAttributeMapping(
                            (string) $attrName,
                            null,
                            $config,
                            $data,
                            null,
                            null
                        );
                        if ($resolvedMapping !== null && $resolvedMapping !== []) {
                            $mappedData = \array_merge(
                                $mappedData,
                                $resolvedMapping
                            );
                        }
                    }
                } else { // Otherwise
                    // If $translationArray is not an array (e.g., it's a string), use it directly
                    $statamicAttributeKey = self::normalizeArrayKey(
                        $config['statamic_attribute']
                    );
                    if ($statamicAttributeKey !== null) {
                        $mappedData[$statamicAttributeKey] = $translationArray;
                    }
                }
            }
        }

        return $mappedData;
    }
}
