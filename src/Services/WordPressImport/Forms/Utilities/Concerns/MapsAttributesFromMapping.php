<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Maps a field's or subfield's attributes from a mapping configuration: the first pass over
 * the attributes the input data carries, which resolves each one's value and writes it to
 * its Statamic attribute.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait MapsAttributesFromMapping
{
    /**
     * Core attribute mapping logic shared by field and subfield mapping.
     *
     * If the mapping's statamic_attribute contains a '*', it will be replaced
     * with the provided $handle (e.g., '*_default' => 'address_line_1_default').
     *
     * Supports 'alternate_statamic_attribute' for edge cases:
     *   - If set, and the current source fieldtype (from $excludeFieldtype) is
     *     present in the array, map the attribute to the alternate attribute for that
     *     fieldtype and do NOT also map it to the default statamic_attribute for that
     *     fieldtype.
     *
     * Supports comprehensive mapping structures with priority-based resolution:
     *   - 'statamic_attribute.based_on_attribute_value.default/specific': Maps to
     *     different Statamic attributes based on the attribute value
     *   - 'statamic_attribute.based_on_other_attribute_value.default/specific': Maps
     *     to different Statamic attributes based on other attribute values
     *   - 'statamic_attribute.default/specific': Fieldtype-specific or default
     *     attribute mappings
     *   - 'statamic_other_field_value_based_on_attribute_value.default/specific':
     *     Sets other field values based on the attribute value
     *   - 'statamic_other_field_value_based_on_nested_attribute_value.default/specific':
     *     Sets other field values based on nested attribute patterns
     *   - 'statamic_other_field_value': Sets other field values when this attribute
     *     is non-empty
     *   - 'choices_as_fields.based_on_attribute_value.default/specific': Converts
     *     choices to fields based on attribute value
     *   - 'choices_as_fields.based_on_other_attribute_value.default/specific':
     *     Converts choices to fields based on other attribute values
     *   - 'choices_as_fields.default/specific': Fieldtype-specific or default c
     *     hoices-to-fields conversion
     *   - 'expected_keys.based_on_attribute_value.default/specific': Sets expected
     *     keys based on attribute value
     *   - 'expected_keys.based_on_other_attribute_value.default/specific': Sets
     *     expected keys based on other attribute values
     *   - 'expected_keys.default/specific': Fieldtype-specific or default expected
     *     keys
     *   - 'get_selected_choice.based_on_attribute_value.default/specific': Processes
     *     selected choice based on attribute value
     *   - 'get_selected_choice.based_on_other_attribute_value.default/specific':
     *     Processes selected choice based on other attribute values
     *   - 'get_selected_choice.default/specific': Fieldtype-specific or default
     *     selected choice processing
     *   - 'key_translation.based_on_attribute_value.default/specific': Translates
     *     keys based on attribute value
     *   - 'key_translation.based_on_other_attribute_value.default/specific':
     *     Translates keys based on other attribute values
     *   - 'key_translation.default/specific': Fieldtype-specific or default key
     *     translation
     *   - 'preserve_newlines.based_on_attribute_value.default/specific': Preserves
     *     newlines based on attribute value
     *   - 'preserve_newlines.based_on_other_attribute_value.default/specific':
     *     Preserves newlines based on other attribute values
     *   - 'preserve_newlines.default/specific': Fieldtype-specific or default newline
     *     preservation
     *   - 'separate_value_field_option.based_on_attribute_value.default/specific':
     *     Separates value field option based on attribute value
     *   - 'separate_value_field_option.based_on_other_attribute_value.default/specific':
     *     Separates value field option based on other attribute values
     *   - 'separate_value_field_option.default/specific': Fieldtype-specific or
     *     default value field option separation
     *   - 'value_translation.default/specific': Fieldtype-specific or default value
     *     translation
     *   - 'extract_array_value': Extracts specific key values from array of arrays
     *     (e.g., 'text' from [{'text': 'value1'}, {'text': 'value2'}])
     *
     * @param  array<int|string, mixed>  $data  The input data (field or subfield)
     * @param  array<int|string, mixed>  $mapping  The mapping array (attribute name => mapping config)
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field reference translation
     * @param  ?string  $handle  Optional handle for wildcard replacement
     * @param  ?string  $excludeFieldtype  Optional source fieldtype for exclude_fieldtypes/alternate_statamic_attribute
     *                                     logic
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for #not_set checks in field options
     * @param  ?string  $siteHandle  Optional site handle for [#site_handle] in mapped values
     * @return array<int|string, mixed> Mapped Statamic attributes
     */
    protected static function mapAttributesFromMapping(
        array $data,
        array $mapping,
        array $allFields = [],
        ?string $handle = null,
        ?string $excludeFieldtype = null,
        ?array $originalFieldData = null,
        ?string $siteHandle = null
    ): array {
        $mappedData = [];

        // First, process attributes that exist in the data
        foreach ($data as $attrName => $attrValue) {
            // Skip if this attribute is not in our mapping
            if (! isset($mapping[$attrName])) {
                continue;
            }

            // Skip array field options for fieldtypes with subfields (e.g., placeholder configurations)
            if (
                static::shouldSkipArrayFieldOption(
                    $attrName,
                    $attrValue,
                    $excludeFieldtype
                )
            ) {
                continue;
            }

            $attrConfig = $mapping[$attrName];
            $attrConfigMap = self::mappingConfig($attrConfig);

            // Normalize the attribute name to a string for downstream helpers
            // and offset lookups that expect string keys. Numeric string keys
            // are normalized back to integers automatically on array access.
            $attrName = (string) $attrName;

            // Get the appropriate configuration based on fieldtype
            $config = static::getFieldtypeSpecificConfig(
                $attrConfigMap,
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

            // Skip if there is no Statamic attribute to map to and no other field value mappings
            if (
                ! isset($config['statamic_attribute'])
                && ! isset($config['statamic_other_field_value'])
                && ! isset($config['statamic_other_field_value_based_on_attribute_value'])
                && ! isset($config['statamic_other_field_value_based_on_nested_attribute_value'])
                && ! isset($config['statamic_type_based_on_attribute_value']
                )
            ) {
                continue; // Skip this attribute if no mappings are available
            }

            // Handle statamic_attribute.based_on_other_attribute_value
            $statamicAttr = $config['statamic_attribute'] ?? null;

            // Initialize generic match found flag
            $matchFound = false;

            // Initialize mappedValue
            $mappedValue = null;

            // Only process statamic_attribute if it's set and has a value
            if (
                $statamicAttr !== null
                && $statamicAttr !== ''
            ) {
                // Process array-based configurations with correct hierarchy order
                if (\is_array($statamicAttr)) {
                    $statamicAttrConfig = self::mappingConfig($statamicAttr);

                    // 1. Check for based_on_attribute_value.specific (highest priority)
                    $basedOnAttributeValue = self::mappingConfig(
                        $statamicAttrConfig['based_on_attribute_value'] ?? null
                    );
                    if (isset($basedOnAttributeValue['specific'])) {
                        $result = self::processBasedOnAttributeValue(
                            $basedOnAttributeValue,
                            $excludeFieldtype,
                            $attrName,
                            $attrValue,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // 2. Check for based_on_attribute_value.default (second priority)
                    if (
                        isset($basedOnAttributeValue['default'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnAttributeValue(
                            $basedOnAttributeValue,
                            $excludeFieldtype,
                            $attrName,
                            $attrValue,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // 3. Check for based_on_other_attribute_value.specific (third priority)
                    $basedOnOtherAttributeValue = self::mappingConfig(
                        $statamicAttrConfig['based_on_other_attribute_value'] ?? null
                    );
                    if (
                        isset($basedOnOtherAttributeValue['specific'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnOtherAttributeValue(
                            $basedOnOtherAttributeValue,
                            $excludeFieldtype,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // 4. Check for based_on_other_attribute_value.default (fourth priority)
                    if (
                        isset($basedOnOtherAttributeValue['default'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnOtherAttributeValue(
                            $basedOnOtherAttributeValue,
                            $excludeFieldtype,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // 5. Check for specific fieldtype configuration (fifth priority)
                    if (
                        isset($statamicAttrConfig['specific'])
                        && ! $matchFound
                    ) {
                        $specificConfig = self::mappingConfig(
                            $statamicAttrConfig['specific']
                        );
                        if (
                            $excludeFieldtype !== null
                            && $excludeFieldtype !== ''
                            && self::arrayKeyExists(
                                $excludeFieldtype,
                                $specificConfig
                            )
                        ) {
                            $statamicAttr = $specificConfig[$excludeFieldtype];
                            $matchFound = true;
                        }
                    }

                    // 6. Check for default configuration (lowest priority)
                    if (
                        isset($statamicAttrConfig['default'])
                        && ! $matchFound
                    ) {
                        $statamicAttr = $statamicAttrConfig['default'];
                        $matchFound = true;
                    }

                    // Legacy support for old structures
                    // Check for specific_based_on_attribute_value (legacy)
                    $specificBasedOnConfig = self::mappingConfig(
                        $statamicAttrConfig['specific_based_on_attribute_value'] ?? null
                    );
                    if (
                        isset($statamicAttrConfig['specific_based_on_attribute_value'])
                        && ! $matchFound
                        // A table keyed by this fieldtype is resolved once, by the
                        // statamic_attribute.specific_based_on_attribute_value block
                        // below, which replaces a `*` in the target and handles
                        // `#not_set` and `#not_empty` (#455)
                        && ! (
                            $excludeFieldtype !== null
                            && $excludeFieldtype !== ''
                            && self::arrayKeyExists(
                                $excludeFieldtype,
                                $specificBasedOnConfig
                            )
                        )
                    ) {
                        $result = self::processBasedOnAttributeValue(
                            $specificBasedOnConfig,
                            $excludeFieldtype,
                            $attrName,
                            $attrValue,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // Check for based_on_attribute_value (legacy)
                    if (
                        $basedOnAttributeValue !== []
                        && ! isset($basedOnAttributeValue['default'])
                        && ! isset($basedOnAttributeValue['specific'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnAttributeValue(
                            $basedOnAttributeValue,
                            $excludeFieldtype,
                            $attrName,
                            $attrValue,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // Check for based_on_other_attribute_value (legacy)
                    if (
                        $basedOnOtherAttributeValue !== []
                        && ! isset($basedOnOtherAttributeValue['default'])
                        && ! isset($basedOnOtherAttributeValue['specific'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnOtherAttributeValue(
                            $basedOnOtherAttributeValue,
                            $excludeFieldtype,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr] = $result;
                        }
                    }
                }

                // Only process statamic_attribute mapping if it's a valid string
                if (\is_string($statamicAttr)) {
                    if (
                        $handle !== null && $handle !== ''
                        && \str_contains(
                            $statamicAttr,
                            '*'
                        )
                    ) {
                        // Replace '*' with the actual handle for subfield mapping
                        $statamicAttr = \str_replace(
                            '*',
                            $handle,
                            $statamicAttr
                        );
                    }

                    // Set the mapped value to the original attribute value by default
                    $mappedValue = $attrValue;
                }
            }

            // Extract array value: extract specific key values from array of arrays
            // --------------------------------------------------------------------------------
            // The 'extract_array_value' configuration allows extracting specific key values
            // from an array of associative arrays. This is useful for processing complex
            // data structures like Gravity Forms survey field rows.
            //
            // Example:
            //   Input: [{'text': 'First row', 'value': 'id1'}, {'text': 'Second row', 'value': 'id2'}]
            //   Config: 'extract_array_value' => 'text'
            //   Output: ['First row', 'Second row']
            //
            // The configuration expects a string value representing the key to extract
            // from each array element. Only array elements that contain the specified
            // key will have their values extracted.
            // --------------------------------------------------------------------------------
            if (
                isset($config['extract_array_value'])
                && \is_string($config['extract_array_value'])
                && \is_array($mappedValue)
            ) {
                $extractKey = $config['extract_array_value'];
                $extractedValues = [];

                // Process each array element in the mapped value
                foreach ($mappedValue as $arrayElement) {
                    if (
                        \is_array($arrayElement)
                        && isset($arrayElement[$extractKey])
                    ) {
                        $extractedValues[] = $arrayElement[$extractKey];
                    }
                }

                // Replace the mapped value with the extracted values
                $mappedValue = $extractedValues;
            }

            // Extract object values: collect a JSON object's values into a
            // de-duplicated, translated list.
            // --------------------------------------------------------------------------------
            // The 'extract_object_values' configuration handles attributes whose source
            // value is a JSON object (decoded to an associative array) where only the
            // values are meaningful — e.g. Formidable Forms `ftypes`, which maps extension
            // groups to MIME types ({"jpg|jpeg|jpe":"image/jpeg", ...}) and feeds the
            // Statamic assets `mime_types` list.
            //
            // The object's values are collected, run through the attribute's existing
            // `value_translation` rules (e.g. application/x-gzip => application/gzip), and
            // de-duplicated while preserving order. The shared string pipeline below
            // (explodeString, trim, etc.) is not object-aware, so this step is
            // self-contained and short-circuits the remaining processing for the attribute.
            //
            // Example:
            //   Input: {"jpg|jpeg|jpe":"image/jpeg","png":"image/png"}
            //   Config: 'extract_object_values' => true
            //   Output: ['image/jpeg', 'image/png']
            // --------------------------------------------------------------------------------
            if (
                isset($config['extract_object_values'])
                && $config['extract_object_values'] === true
                && \is_array($mappedValue)
                && $statamicAttr !== ''
            ) {
                // Collect the object's values, dropping the (extension-group) keys.
                $objectValues = \array_values($mappedValue);

                // Run the attribute's existing per-value translation rules.
                $objectValues = static::applyValueTranslation(
                    $objectValues,
                    $config,
                    $attrName,
                    $data,
                    $excludeFieldtype,
                    $originalFieldData,
                    $statamicAttr
                );

                // Keep only non-empty strings, then de-duplicate while preserving
                // order. A well-formed source object maps each key to a string, but a
                // malformed one (nested values, numbers, null) would otherwise leak
                // arrays/nulls into the Statamic blueprint as list items.
                $objectValues = \array_values(
                    \array_unique(
                        \array_filter(
                            \is_array($objectValues) ? $objectValues : [],
                            static fn (mixed $value): bool => \is_string($value) && $value !== ''
                        ),
                        SORT_REGULAR
                    )
                );

                // Emit the attribute only when a value survived; an empty list is dead
                // config the string-oriented pipeline below would never have produced.
                if ($objectValues !== []) {
                    $mappedData[$statamicAttr] = $objectValues;
                }

                // The attribute is fully handled here; the string-oriented pipeline
                // below would otherwise discard the array, so skip to the next attribute.
                continue;
            }

            // Key translation for array values (like choices)
            $mappedValue = static::applyKeyTranslation(
                $mappedValue,
                $config,
                $data,
                $attrName,
                $attrValue,
                $excludeFieldtype,
                $originalFieldData
            );

            // Combine value with other attribute values
            $mappedValue = static::processCombineValueWithOtherAttributeValue(
                $mappedValue,
                $config,
                $data
            );

            // Explode string (must be applied before value_translation). Only
            // string and array values are eligible; other types pass through
            // unchanged just as the helper would return them.
            $explodedFromString = \is_string($mappedValue);
            if (
                \is_string($mappedValue)
                || \is_array($mappedValue)
            ) {
                $mappedValue = static::explodeString(
                    $mappedValue,
                    $config
                );
            }

            // Trim whitespace (applied after explode_string to ensure proper trimming of individual elements)
            $mappedValue = static::trimValue(
                $mappedValue,
                $config
            );

            // Trim specific characters (applied after trim to ensure proper
            // character trimming). Only string, array, and null values are
            // eligible; other types pass through unchanged.
            if (
                $mappedValue === null
                || \is_string($mappedValue)
                || \is_array($mappedValue)
            ) {
                $mappedValue = static::trimCharacters(
                    $mappedValue,
                    $config
                );
            }

            // Drop the empty pieces a stray separator leaves in an exploded
            // string (a trailing comma, or two in a row), so an extension
            // list never yields an empty MIME type
            if (
                $explodedFromString
                && \is_array($mappedValue)
            ) {
                $mappedValue = \array_values(
                    \array_filter(
                        $mappedValue,
                        fn (mixed $piece): bool => $piece !== ''
                    )
                );
            }

            // Apply value translation if configured
            $mappedValue = static::applyValueTranslation(
                $mappedValue,
                $config,
                $attrName,
                $data,
                $excludeFieldtype,
                $originalFieldData,
                $statamicAttr
            );

            // Value substitution regex: apply regex patterns to transform values
            if (
                isset($config['value_substitution_regex'])
                && \is_array($config['value_substitution_regex'])
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = self::applyValueSubstitutionRegex(
                    $mappedValue,
                    $config['value_substitution_regex']
                );
            }

            // Value calculation: apply mathematical expressions to transform values
            if (isset($config['value_calculation'])) {
                $calculationConfig = $config['value_calculation'];

                // Handle different value_calculation structures
                if (\is_string($calculationConfig)) {
                    // Simple string expression - only apply if value is numeric
                    if (
                        $mappedValue !== null
                        && \is_numeric($mappedValue)
                    ) {
                        $mappedValue = self::applyValueCalculation(
                            $mappedValue,
                            $calculationConfig
                        );
                    }
                } elseif (\is_array($calculationConfig)) {
                    // Complex structure with default, specific, based_on_other_attribute_value, etc.
                    $expression = null;

                    // Check for specific_based_on_attribute_value first (most specific)
                    if (
                        isset($calculationConfig['specific_based_on_attribute_value'])
                        && $excludeFieldtype !== ''
                    ) {
                        $specificMappings = self::asArray(
                            $calculationConfig['specific_based_on_attribute_value']
                        );
                        $fieldtypeMappings = self::asArray(
                            self::mappingValue($specificMappings, $excludeFieldtype)
                        );
                        foreach ($fieldtypeMappings as $pattern => $calcExpression) {
                            // Check for '#not_set' special case first
                            if ($pattern === '#not_set') {
                                // If we have original field data, check if the attribute is not set in field options
                                if ($originalFieldData !== null) {
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        $expression = $calcExpression;
                                        break;
                                    }
                                } else { // Otherwise
                                    // Fallback to checking if the attribute is not set in the current data
                                    if (
                                        self::isAttributeNotSet(
                                            $attrName,
                                            $data
                                        )
                                    ) {
                                        $expression = $calcExpression;
                                        break;
                                    }
                                }
                            } else { // Otherwise
                                // Check against the original value before translation
                                if (
                                    self::matchesPattern(
                                        $attrValue,
                                        $pattern
                                    )
                                ) {
                                    $expression = $calcExpression;
                                    break;
                                }
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
                            $defaultMappings = self::asArray(
                                $basedOnConfig['default'] ?? null
                            );
                            $specificMappings = self::asArray(
                                $basedOnConfig['specific'] ?? null
                            );

                            // Process default mappings first
                            foreach ($defaultMappings as $otherAttrName => $valueMappings) {
                                $valueMappings = self::asArray($valueMappings);
                                $otherAttrValue = $data[$otherAttrName] ?? null;
                                if (
                                    self::arrayKeyExists(
                                        $otherAttrValue,
                                        $valueMappings
                                    )
                                ) {
                                    $expression = self::mappingValue(
                                        $valueMappings,
                                        $otherAttrValue
                                    );
                                    break;
                                }

                                // Check for #not_set case
                                if (
                                    isset($valueMappings['#not_set'])
                                    && $otherAttrValue === null
                                ) {
                                    $expression = $valueMappings['#not_set'];
                                    break;
                                }
                            }

                            // Process fieldtype-specific mappings if no default match found
                            if (
                                $expression === null
                                && $excludeFieldtype !== ''
                            ) {
                                $fieldtypeMappings = self::asArray(
                                    self::mappingValue(
                                        $specificMappings,
                                        $excludeFieldtype
                                    )
                                );
                                foreach ($fieldtypeMappings as $otherAttrName => $valueMappings) {
                                    $valueMappings = self::asArray($valueMappings);
                                    $otherAttrValue = $data[$otherAttrName] ?? null;
                                    if (
                                        self::arrayKeyExists(
                                            $otherAttrValue,
                                            $valueMappings
                                        )
                                    ) {
                                        $expression = self::mappingValue(
                                            $valueMappings,
                                            $otherAttrValue
                                        );
                                        break;
                                    }

                                    // Check for #not_set case
                                    if (
                                        isset($valueMappings['#not_set'])
                                        && $otherAttrValue === null
                                    ) {
                                        $expression = $valueMappings['#not_set'];
                                        break;
                                    }
                                }
                            }
                        } else { // Otherwise
                            // Legacy structure: direct attr => valueMappings mapping
                            foreach (self::asArray($basedOnConfig) as $otherAttrName => $valueMappings) {
                                $valueMappings = self::asArray($valueMappings);
                                $otherAttrValue = $data[$otherAttrName] ?? null;
                                if (
                                    self::arrayKeyExists(
                                        $otherAttrValue,
                                        $valueMappings
                                    )
                                ) {
                                    $expression = self::mappingValue(
                                        $valueMappings,
                                        $otherAttrValue
                                    );
                                    break;
                                }

                                // Check for #not_set case
                                if (
                                    isset($valueMappings['#not_set'])
                                    && $otherAttrValue === null
                                ) {
                                    $expression = $valueMappings['#not_set'];
                                    break;
                                }
                            }
                        }
                    }

                    // Check for specific fieldtype override if no other match found
                    if (
                        $expression === null
                        && isset($calculationConfig['specific'])
                        && $excludeFieldtype !== ''
                    ) {
                        $specificMappings = self::asArray(
                            $calculationConfig['specific']
                        );
                        $specificExpression = self::mappingValue(
                            $specificMappings,
                            $excludeFieldtype
                        );
                        if ($specificExpression !== null) {
                            $expression = $specificExpression;
                        }
                    }

                    // Use default if no other match found
                    if (
                        $expression === null
                        && isset($calculationConfig['default'])
                    ) {
                        $expression = $calculationConfig['default'];
                    }

                    // Apply the calculation if we found an expression and the value is numeric
                    if (
                        $expression !== null
                        && \is_string($expression)
                        && $mappedValue !== null
                        && \is_numeric($mappedValue)
                    ) {
                        $mappedValue = self::applyValueCalculation(
                            $mappedValue,
                            $expression
                        );
                    }
                }
            }

            // Translate field references in string values
            if (
                isset($config['translate_field_references'])
                && $config['translate_field_references'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = self::translateFieldReferences(
                    $mappedValue,
                    $allFields,
                    $data
                );
            }

            // Translate calculation field references in string values
            if (
                isset($config['translate_field_references_calculation'])
                && $config['translate_field_references_calculation'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = self::translateCalculationFieldReferences(
                    $mappedValue,
                    $allFields,
                    $data
                );
            }

            // Process markdown fields to preserve newlines
            if (
                isset($config['preserve_newlines'])
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $preserveNewlinesConfig = $config['preserve_newlines'];

                // Use the DRY helper method to process modifier patterns
                $modifierResult = self::processModifierPatterns(
                    $preserveNewlinesConfig,
                    $data,
                    null,
                    $excludeFieldtype,
                    $attrName,
                    $attrValue
                );
                $shouldPreserveNewlines = $modifierResult['value'];
                $matchFound = $modifierResult['matchFound'];

                // Legacy boolean check
                if (
                    ! $matchFound
                    && $preserveNewlinesConfig === true
                ) {
                    $shouldPreserveNewlines = true;
                }

                // Apply newline preservation if needed
                if ($shouldPreserveNewlines === true) {
                    // Convert escaped newlines back to actual newlines for markdown fields
                    $mappedValue = \str_replace(
                        '\\n',
                        "\n",
                        $mappedValue
                    );
                }
            }

            // Unescape forward slashes in HTML content
            if (
                isset($config['unescape_slashes'])
                && $config['unescape_slashes'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                // Convert escaped forward slashes back to actual forward slashes
                $mappedValue = \str_replace(
                    '\/',
                    '/',
                    $mappedValue
                );
            }

            // Convert empty strings to null after all processing
            if ($mappedValue === '') {
                $mappedValue = null;
            }

            // Value prepend
            if (
                isset($config['value_prepend'])
                && \is_string($config['value_prepend'])
                && $mappedValue !== null
                && $mappedValue !== []
                && \is_scalar($mappedValue)
            ) {
                // Coerce the scalar mapped value to string before prepending
                $mappedValue = $config['value_prepend'].$mappedValue;
            }

            // Collapse whitespace
            if (
                isset($config['collapse_whitespace'])
                && $config['collapse_whitespace'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = \preg_replace(
                    '/\s+/',
                    '',
                    $mappedValue
                );
            }

            // Convert file size
            if (
                isset($config['convert_file_size'])
                && \is_string($config['convert_file_size'])
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = self::convertFileSize(
                    $mappedValue,
                    $config['convert_file_size']
                );
            }

            // Convert to integer
            if (
                isset($config['as_integer'])
                && $config['as_integer'] === true
                && $mappedValue !== null
                && \is_numeric($mappedValue)
            ) {
                $mappedValue = (int) $mappedValue;
            }

            // Process boolean conversion if configured
            if (
                isset($config['as_boolean'])
                && $config['as_boolean'] === true
            ) {
                $mappedValue = static::convertToBoolean($mappedValue);

                // Apply boolean inversion if configured
                if (
                    isset($config['invert_boolean'])
                    && $config['invert_boolean'] === true
                ) {
                    $mappedValue = ! $mappedValue;
                }
            }

            // Convert dollar amount to float
            if (
                isset($config['convert_dollar_amount'])
                && $config['convert_dollar_amount'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = (float) \str_replace(
                    ['$', ','],
                    '',
                    $mappedValue
                );
            }

            // Transform JSON to array for options fields
            if (
                isset($config['transform_json_to_array'])
                && $config['transform_json_to_array'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = static::transformOptionsJsonToArray(
                    $mappedValue,
                    $data,
                    $config,
                    $excludeFieldtype
                );

                // Check if other_label was extracted during options processing
                if (static::$extractedOtherLabel !== null) {
                    $mappedData['other_label'] = static::$extractedOtherLabel;
                    static::$extractedOtherLabel = null; // Reset for next use
                }
            }

            // Handle choices_as_fields logic for array values (like Gravity Forms choices)
            if (
                isset($config['choices_as_fields'])
                && \is_array($mappedValue)
            ) {
                $choicesAsFieldsConfig = $config['choices_as_fields'];

                // Use the DRY helper method to process modifier patterns
                $modifierResult = self::processModifierPatterns(
                    $choicesAsFieldsConfig,
                    $data,
                    null,
                    $excludeFieldtype,
                    $attrName,
                    $attrValue
                );
                $shouldConvertToFields = $modifierResult['value'];

                // Convert choices to fields format if needed
                if ($shouldConvertToFields === true) {
                    $mappedValue = self::convertChoicesToFields(
                        $mappedValue,
                        $data
                    );

                    // Update the statamic attribute to 'fields' when converting choices to fields
                    if ($attrName === 'choices') {
                        $statamicAttr = 'fields';
                    }
                }
            }

            // Check against validation list
            if (
                isset($config['check_against_list'])
                && \is_string($config['check_against_list'])
            ) {
                // Skip validation if the value is null (null values are handled by the calling logic)
                if (
                    $mappedValue !== null
                    && ! self::validateValueAgainstList(
                        self::stringValue($mappedValue) ?? '',
                        $config['check_against_list']
                    )
                ) {
                    continue; // Skip this attribute if validation fails
                }
            }

            // Alert for unsupported attributes
            if (
                isset($config['alert_unsupported'])
                && $config['alert_unsupported'] === true
                && (! in_array($attrValue, [null, '', []], true))
            ) {
                // This will be handled by the calling method to generate warnings
                // We could add a flag here to indicate unsupported attributes were found
            }

            // Handle statamic_attribute.specific_based_on_attribute_value
            $statamicAttributeConfig = self::mappingConfig(
                $config['statamic_attribute'] ?? null
            );
            if (isset($statamicAttributeConfig['specific_based_on_attribute_value'])) {
                $specificMappings = self::mappingConfig(
                    $statamicAttributeConfig['specific_based_on_attribute_value']
                );

                // Handle fieldtype-specific structure
                if (
                    $excludeFieldtype !== null
                    && $excludeFieldtype !== ''
                    && self::arrayKeyExists(
                        $excludeFieldtype,
                        $specificMappings
                    )
                ) {
                    $fieldtypeMappings = self::asArray(
                        $specificMappings[$excludeFieldtype]
                    );
                    foreach ($fieldtypeMappings as $pattern => $targetAttribute) {
                        // Check for '#not_set' special case first
                        if ($pattern === '#not_set') {
                            // If we have original field data, check if the attribute is not set in field options
                            if ($originalFieldData !== null) {
                                if (
                                    self::isFieldOptionNotSet(
                                        $attrName,
                                        $originalFieldData
                                    )
                                ) {
                                    // Replace wildcards in the target attribute
                                    $resolvedTargetAttribute = self::resolveWildcards(
                                        self::stringValue($targetAttribute) ?? '',
                                        '',
                                        $pattern
                                    );
                                    // Apply value_substitution_regex to the attribute value before assigning
                                    $finalValue = '';
                                    if (
                                        isset($config['value_substitution_regex'])
                                        && \is_array($config['value_substitution_regex'])
                                    ) {
                                        $finalValue = self::applyValueSubstitutionRegex(
                                            '',
                                            $config['value_substitution_regex']
                                        );
                                    }

                                    $mappedData[$resolvedTargetAttribute] = $finalValue;
                                    // Set mappedValue to ensure statamic_other_field_value gets applied
                                    $mappedValue = $finalValue;
                                    break; // Use the first matching pattern
                                }
                            } else { // Otherwise
                                // Fallback to checking if the attribute is not set in the current data
                                if (
                                    self::isAttributeNotSet(
                                        $attrName,
                                        $data
                                    )
                                ) {
                                    // Replace wildcards in the target attribute
                                    $resolvedTargetAttribute = self::resolveWildcards(
                                        self::stringValue($targetAttribute) ?? '',
                                        '',
                                        $pattern
                                    );
                                    // Apply value_substitution_regex to the attribute value before assigning
                                    $finalValue = '';
                                    if (
                                        isset($config['value_substitution_regex'])
                                        && \is_array($config['value_substitution_regex'])
                                    ) {
                                        $finalValue = self::applyValueSubstitutionRegex(
                                            '',
                                            $config['value_substitution_regex']
                                        );
                                    }

                                    $mappedData[$resolvedTargetAttribute] = $finalValue;
                                    // Set mappedValue to ensure statamic_other_field_value gets applied
                                    $mappedValue = $finalValue;
                                    break; // Use the first matching pattern
                                }
                            }
                        } elseif ($pattern === '#not_empty') { // Check for '#not_empty' special case
                            if (
                                self::isAttributeNotEmptyWithFallback(
                                    $attrName,
                                    $data,
                                    $originalFieldData
                                )
                            ) {
                                // Replace wildcards in the target attribute
                                $resolvedTargetAttribute = self::resolveWildcards(
                                    self::stringValue($targetAttribute) ?? '',
                                    self::stringValue($attrValue) ?? '',
                                    $pattern
                                );
                                // Apply value_substitution_regex to the attribute value before assigning
                                $finalValue = $attrValue;
                                if (
                                    isset($config['value_substitution_regex'])
                                    && \is_array($config['value_substitution_regex'])
                                ) {
                                    $finalValue = self::applyValueSubstitutionRegex(
                                        self::stringValue($attrValue) ?? '',
                                        $config['value_substitution_regex']
                                    );
                                }

                                $mappedData[$resolvedTargetAttribute] = $finalValue;
                                // Set mappedValue to ensure statamic_other_field_value gets applied
                                $mappedValue = $finalValue;
                                break; // Use the first matching pattern
                            }
                        } else { // Otherwise
                            // Check against the original value before translation
                            if (
                                self::matchesPattern(
                                    $attrValue,
                                    $pattern
                                )
                            ) {
                                // Replace wildcards in the target attribute
                                $resolvedTargetAttribute = self::resolveWildcards(
                                    self::stringValue($targetAttribute) ?? '',
                                    self::stringValue($attrValue) ?? '',
                                    (string) $pattern
                                );
                                // Apply value_substitution_regex to the attribute value before assigning
                                $finalValue = $attrValue;
                                if (
                                    isset($config['value_substitution_regex'])
                                    && \is_array($config['value_substitution_regex'])
                                ) {
                                    $finalValue = self::applyValueSubstitutionRegex(
                                        self::stringValue($attrValue) ?? '',
                                        $config['value_substitution_regex']
                                    );
                                }

                                $mappedData[$resolvedTargetAttribute] = $finalValue;
                                // Set mappedValue to ensure statamic_other_field_value gets applied
                                $mappedValue = $finalValue;
                                break; // Use the first matching pattern
                            }
                        }
                    }
                }
            }

            // Handle statamic_other_field_value_based_on_attribute_value
            if (isset($attrConfigMap['statamic_other_field_value_based_on_attribute_value'])) {
                $otherFieldMappings = self::mappingConfig(
                    $attrConfigMap['statamic_other_field_value_based_on_attribute_value']
                );

                // Handle the new structure with default and specific overrides
                if (
                    self::arrayKeyExists(
                        'default',
                        $otherFieldMappings
                    )
                ) {
                    // New structure: ['default' => [pattern => fieldValues], 'specific' => [fieldtype => [pattern => fieldValues]]]
                    $defaultMappings = self::asArray(
                        $otherFieldMappings['default'] ?? null
                    );
                    $specificMappings = self::asArray(
                        $otherFieldMappings['specific'] ?? null
                    );

                    // Process default mappings
                    foreach ($defaultMappings as $pattern => $fieldValues) {
                        $fieldValuesMap = self::asArray($fieldValues);
                        // Check for '#not_set' special case first
                        if ($pattern === '#not_set') {
                            // If we have original field data, check if the attribute is not set in field options
                            if ($originalFieldData !== null) {
                                if (
                                    self::isFieldOptionNotSet(
                                        $attrName,
                                        $originalFieldData
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            } else { // Otherwise
                                // Fallback to checking if the attribute is not set in the current data
                                if (
                                    self::isAttributeNotSet(
                                        $attrName,
                                        $data
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            }
                        } elseif ($pattern === '#not_empty') { // Check for '#not_empty' special case
                            if (
                                self::isAttributeNotEmptyWithFallback(
                                    $attrName,
                                    $data,
                                    $originalFieldData
                                )
                            ) {
                                foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }

                                break; // Use the first matching pattern
                            }
                        } else { // Otherwise
                            // Check against the original value before translation
                            if (
                                self::matchesPattern(
                                    $attrValue,
                                    $pattern
                                )
                            ) {
                                foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }

                                break; // Use the first matching pattern
                            }
                        }
                    }

                    // Process fieldtype-specific mappings
                    if (
                        $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                        && self::arrayKeyExists(
                            $excludeFieldtype,
                            $specificMappings
                        )
                    ) {
                        $fieldtypeMappings = self::asArray(
                            $specificMappings[$excludeFieldtype]
                        );
                        foreach ($fieldtypeMappings as $pattern => $fieldValues) {
                            $fieldValuesMap = self::asArray($fieldValues);
                            // Check for '#not_set' special case first
                            if ($pattern === '#not_set') {
                                // If we have original field data, check if the attribute is not set in field options
                                if ($originalFieldData !== null) {
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                            $mappedData[$fieldName] = $fieldValue;
                                        }

                                        break; // Use the first matching pattern
                                    }
                                } else { // Otherwise
                                    // Fallback to checking if the attribute is not set in the current data
                                    if (
                                        self::isAttributeNotSet(
                                            $attrName,
                                            $data
                                        )
                                    ) {
                                        foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                            $mappedData[$fieldName] = $fieldValue;
                                        }

                                        break; // Use the first matching pattern
                                    }
                                }
                            } elseif ($pattern === '#not_empty') { // Check for '#not_empty' special case
                                if (
                                    self::isAttributeNotEmptyWithFallback(
                                        $attrName,
                                        $data,
                                        $originalFieldData
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            } else { // Otherwise
                                // Check against the original value before translation
                                if (
                                    self::matchesPattern(
                                        $attrValue,
                                        $pattern
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            }
                        }
                    }
                } else { // Otherwise
                    // Legacy structure: direct pattern => fieldValues mapping
                    foreach ($otherFieldMappings as $pattern => $fieldValues) {
                        $fieldValuesMap = self::asArray($fieldValues);
                        // Check for '#not_set' special case first
                        if ($pattern === '#not_set') {
                            // If we have original field data, check if the attribute is not set in field options
                            if ($originalFieldData !== null) {
                                if (
                                    self::isFieldOptionNotSet(
                                        $attrName,
                                        $originalFieldData
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            } else { // Otherwise
                                // Fallback to checking if the attribute is not set in the current data
                                if (
                                    self::isAttributeNotSet(
                                        $attrName,
                                        $data
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            }
                        } else { // Otherwise
                            // Check against the original value before translation
                            if (
                                self::matchesPattern(
                                    $attrValue,
                                    $pattern
                                )
                            ) {
                                foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }

                                break; // Use the first matching pattern
                            }
                        }
                    }
                }
            }

            // Handle statamic_other_field_value
            if (
                isset($attrConfigMap['statamic_other_field_value'])
                && (! in_array($mappedValue, [null, '', []], true))
            ) {
                $otherFieldValues = self::mappingConfig(
                    $attrConfigMap['statamic_other_field_value']
                );

                // Handle default values
                if (
                    isset($otherFieldValues['default'])
                ) {
                    $defaultOtherFieldValues = self::asArray(
                        $otherFieldValues['default']
                    );
                    foreach ($defaultOtherFieldValues as $fieldName => $fieldValue) {
                        $mappedData[$fieldName] = $fieldValue;
                    }
                }

                // Handle fieldtype-specific values
                if (
                    isset($otherFieldValues['specific'])
                    && $excludeFieldtype !== null
                    && $excludeFieldtype !== ''
                ) {
                    $specificOtherFieldValues = self::asArray(
                        $otherFieldValues['specific']
                    );
                    if (
                        self::arrayKeyExists(
                            $excludeFieldtype,
                            $specificOtherFieldValues
                        )
                    ) {
                        $fieldtypeOtherValues = self::asArray(
                            $specificOtherFieldValues[$excludeFieldtype]
                        );
                        foreach ($fieldtypeOtherValues as $fieldName => $fieldValue) {
                            $mappedData[$fieldName] = $fieldValue;
                        }
                    }
                }
            }

            // Handle statamic_other_field_value_based_on_nested_attribute_value
            if (isset($attrConfigMap['statamic_other_field_value_based_on_nested_attribute_value'])) {
                $nestedMappings = self::mappingConfig(
                    $attrConfigMap['statamic_other_field_value_based_on_nested_attribute_value']
                );

                // Handle the structure with default and specific overrides
                if (
                    self::arrayKeyExists(
                        'default',
                        $nestedMappings
                    )
                ) {
                    $defaultMappings = self::asArray($nestedMappings['default'] ?? null);
                    $specificMappings = self::asArray($nestedMappings['specific'] ?? null);

                    // Process default mappings
                    foreach ($defaultMappings as $mappingConfig) {
                        $mappingConfigArray = self::mappingConfig($mappingConfig);
                        if (
                            self::matchesNestedAttributePattern(
                                $attrValue,
                                $mappingConfigArray
                            )
                        ) {
                            $otherAttributes = self::asArray(
                                $mappingConfigArray['other_attribute'] ?? null
                            );
                            foreach ($otherAttributes as $fieldName => $fieldValue) {
                                $mappedData[$fieldName] = $fieldValue;
                            }

                            break; // Use the first matching pattern
                        }
                    }

                    // Process fieldtype-specific mappings
                    if (
                        $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                        && self::arrayKeyExists(
                            $excludeFieldtype,
                            $specificMappings
                        )
                    ) {
                        $fieldtypeNestedMappings = self::asArray(
                            $specificMappings[$excludeFieldtype]
                        );
                        foreach ($fieldtypeNestedMappings as $mappingConfig) {
                            $mappingConfigArray = self::mappingConfig($mappingConfig);
                            if (
                                self::matchesNestedAttributePattern(
                                    $attrValue,
                                    $mappingConfigArray
                                )
                            ) {
                                $otherAttributes = self::asArray(
                                    $mappingConfigArray['other_attribute'] ?? null
                                );
                                foreach ($otherAttributes as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }

                                break; // Use the first matching pattern
                            }
                        }
                    }
                }
            }

            // Handle statamic_type_based_on_attribute_value
            if (isset($attrConfigMap['statamic_type_based_on_attribute_value'])) {
                $typeMappings = self::asArray(
                    $attrConfigMap['statamic_type_based_on_attribute_value']
                );
                foreach ($typeMappings as $pattern => $newType) {
                    // Check for '#not_set' special case first
                    if ($pattern === '#not_set') {
                        // If we have original field data, check if the attribute is not set in field options
                        if ($originalFieldData !== null) {
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                $mappedData['type'] = $newType;
                                break; // Use the first matching pattern
                            }
                        } else { // Otherwise
                            // Fallback to checking if the attribute is not set in the current data
                            if (
                                self::isAttributeNotSet(
                                    $attrName,
                                    $data
                                )
                            ) {
                                $mappedData['type'] = $newType;
                                break; // Use the first matching pattern
                            }
                        }
                    } else { // Otherwise
                        // Check against the original value before translation
                        if (
                            self::matchesPattern(
                                $attrValue,
                                $pattern
                            )
                        ) {
                            $mappedData['type'] = $newType;
                            break; // Use the first matching pattern
                        }
                    }
                }
            }

            // Handle statamic_form_config
            if (
                isset($attrConfigMap['statamic_form_config'])
                && (! in_array($mappedValue, [null, '', []], true))
            ) {
                $formConfigKey = self::stringValue(
                    $attrConfigMap['statamic_form_config']
                );
                if ($formConfigKey !== null) {
                    $mappedData['form_config_'.$formConfigKey] = $mappedValue;
                }
            }

            // Handle get_selected_choice logic for defaultValue mapping
            if (
                isset($config['get_selected_choice'])
                && $statamicAttr === 'default'
                && $mappedValue === null
            ) {
                $getSelectedChoiceConfig = $config['get_selected_choice'];

                // Use the DRY helper method to process modifier patterns
                $modifierResult = self::processModifierPatterns(
                    $getSelectedChoiceConfig,
                    $data,
                    null,
                    $excludeFieldtype,
                    $attrName,
                    $attrValue
                );
                $shouldProcessSelectedChoice = $modifierResult['value'];

                // Process the selected choice if we should
                if ($shouldProcessSelectedChoice === true) {
                    // Check if we have choices data and expected_keys equals ['key', 'value']
                    if (
                        isset($data['choices'])
                        && \is_array($data['choices'])
                    ) {
                        $expectedKeys = static::getExpectedKeys(
                            $data,
                            $attrValue,
                            $excludeFieldtype,
                            $attrName
                        );

                        // Only process if expected_keys equals ['key', 'value']
                        if (
                            $expectedKeys === [
                                'key',
                                'value',
                            ]
                        ) {
                            $selectedChoice = array_find(self::asArray($data['choices']), fn ($choice): bool => \is_array($choice)
                            && isset($choice['isSelected'])
                            && $choice['isSelected'] === true);
                            // If we found a selected choice, get the value that maps to 'key'
                            if ($selectedChoice !== null) {
                                // Get the choices configuration to determine key mapping
                                $choicesConfig = static::$fieldAttributeMapping['choices'] ?? null;
                                if (
                                    $choicesConfig !== null
                                    && $choicesConfig !== []
                                    && isset($choicesConfig['key_translation'])
                                ) {
                                    $keyTranslationConfig = $choicesConfig['key_translation'];

                                    // Get the appropriate key translation configuration
                                    $keyTranslationArray = null;
                                    if (
                                        \is_array($keyTranslationConfig)
                                        && self::arrayKeyExists(
                                            'default',
                                            $keyTranslationConfig
                                        )
                                    ) {
                                        $defaultTranslations = self::asArray($keyTranslationConfig['default'] ?? null);
                                        $specificTranslations = self::asArray($keyTranslationConfig['specific'] ?? null);
                                        $basedOnOtherAttributeValue = $keyTranslationConfig['based_on_other_attribute_value'] ?? [];

                                        // Check for based_on_other_attribute_value first (most specific)
                                        if (($basedOnOtherAttributeValue !== '' && $basedOnOtherAttributeValue !== [])) {
                                            foreach (self::asArray($basedOnOtherAttributeValue) as $otherAttrName => $valueMappings) {
                                                $otherAttrValue = $data[$otherAttrName] ?? null;
                                                // Normalize the value mappings and lookup key for safe offset access
                                                $valueMappingsArr = self::asArray($valueMappings);
                                                $normalizedOtherValue = self::normalizeArrayKey($otherAttrValue);
                                                if (
                                                    $normalizedOtherValue !== null
                                                    && self::arrayKeyExists(
                                                        $normalizedOtherValue,
                                                        $valueMappingsArr
                                                    )
                                                ) {
                                                    $keyTranslationArray = $valueMappingsArr[$normalizedOtherValue];
                                                    break;
                                                }
                                            }
                                        } elseif ($excludeFieldtype !== null && $excludeFieldtype !== '' && isset($specificTranslations[$excludeFieldtype])) { // Check for fieldtype-specific translations
                                            $keyTranslationArray = $specificTranslations[$excludeFieldtype];
                                        } else { // Fall back to default translations
                                            $keyTranslationArray = $defaultTranslations;
                                        }
                                    } else { // Otherwise
                                        // Simple array structure
                                        $keyTranslationArray = $keyTranslationConfig;
                                    }

                                    // Find the attribute that maps to 'key' in the key translation
                                    $keyAttribute = null;
                                    if ($keyTranslationArray !== null) {
                                        foreach (self::asArray($keyTranslationArray) as $sourceAttr => $targetAttr) {
                                            if ($targetAttr === 'key') {
                                                $keyAttribute = $sourceAttr;
                                                break;
                                            }
                                        }
                                    }

                                    // Get the value from the selected choice that maps to 'key'
                                    if (
                                        $keyAttribute !== null
                                        && isset($selectedChoice[$keyAttribute])
                                    ) {
                                        $mappedValue = $selectedChoice[$keyAttribute];
                                    } else { // Otherwise
                                        // Fallback: if we can't find the key mapping, use the 'text' value
                                        // This handles cases where the key translation might not be properly configured
                                        if (isset($selectedChoice['text'])) {
                                            $mappedValue = $selectedChoice['text'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Now assign the transformed value to the statamic attribute
            if (
                \is_string($statamicAttr)
                && ($statamicAttr !== '' && $statamicAttr !== '')
            ) {
                if (
                    isset($config['as_array'])
                    && $config['as_array'] === true
                ) {
                    // Handle as array
                    if ($mappedValue !== null) {
                        // Ensure the existing value is an array before appending
                        $existingValues = self::asArray(
                            $mappedData[$statamicAttr] ?? []
                        );
                        $existingValues[] = $mappedValue;
                        $mappedData[$statamicAttr] = $existingValues;
                    }
                } else { // Otherwise
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
                        $valueTransformations = $config['value_transformations'];

                        // Check if this is already a processed transformation config (flat structure)
                        // or if it needs to be resolved through resolveValueTransformations
                        $transformationConfig = null;

                        // If the valueTransformations is already a flat transformation config (like {"as_numeric_string":true}),
                        // use it directly. Otherwise, resolve it through the resolveValueTransformations method.
                        // A flat transformation config should only contain transformation keys (like as_numeric_string, as_date_iso_8601, etc.)
                        // and should not contain structural keys like 'default', 'specific', 'based_on_attribute_value', etc.
                        if (\is_array($valueTransformations)) {
                            $isFlatConfig = ! isset($valueTransformations['default'])
                                && ! isset($valueTransformations['specific'])
                                && ! isset($valueTransformations['based_on_attribute_value'])
                                && ! isset($valueTransformations['based_on_other_attribute_value'])
                                && ! isset($valueTransformations['specific_based_on_attribute_value']);

                            if ($isFlatConfig) {
                                // This is already a processed transformation config
                                $transformationConfig = $valueTransformations;
                            } else {
                                // This needs to be resolved through resolveValueTransformations
                                // Try different keys to find the right transformation config
                                $transformationConfig = static::resolveValueTransformations(
                                    $valueTransformations,
                                    $data,
                                    'based_on_attribute_value',
                                    $excludeFieldtype
                                );
                                if ($transformationConfig === null || $transformationConfig === []) {
                                    $transformationConfig = static::resolveValueTransformations(
                                        $valueTransformations,
                                        $data,
                                        'default',
                                        $excludeFieldtype
                                    );
                                }
                            }
                        }

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
                    if ($mappedValue !== null) {
                        $mappedData[$statamicAttr] = $mappedValue;
                    }
                }
            }
        }

        // Now, process #not_set cases for attributes that don't exist in the data
        // Process missing attributes that have #not_set value translations, even when originalFieldData is null
        $mappedData = static::mapNotSetAttributes(
            $data,
            $mapping,
            $mappedData,
            $handle,
            $excludeFieldtype,
            $originalFieldData
        );

        // Second pass: process attributes defined in mapping but not present in data (for #not_set cases)
        $mappedData = static::mapNotSetValueTranslations(
            $data,
            $mapping,
            $mappedData,
            $excludeFieldtype,
            $originalFieldData
        );

        // Filter out null values, empty arrays, and arrays with only empty values from mapped attributes
        $mappedData = \array_filter(
            $mappedData,
            function ($value): bool {
                if ($value === null) {
                    return false;
                }

                if (\is_array($value)) {
                    // Filter out empty arrays
                    if ($value === []) {
                        return false;
                    }

                    // Filter out arrays that contain only empty strings or null values
                    $nonEmptyValues = \array_filter(
                        $value,
                        fn ($item): bool => $item !== null && $item !== ''
                    );

                    return $nonEmptyValues !== [];
                }

                return true;
            }
        );

        // Resolve [#site_handle] in mapped strings (e.g., folder on assets fields).
        if ($siteHandle !== null) {
            $withPlaceholdersResolved = self::applySiteHandlePlaceholderToMappedStructure(
                $mappedData,
                $siteHandle
            );
            if (\is_array($withPlaceholdersResolved)) {
                $mappedData = $withPlaceholdersResolved;
            }
        }

        return $mappedData;
    }
}
