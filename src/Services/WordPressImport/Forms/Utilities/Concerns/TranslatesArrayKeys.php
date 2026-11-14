<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Translates the keys of array values such as choices, normalizes the structure of
 * options, and converts choices to fields.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait TranslatesArrayKeys
{
    /**
     * Apply an attribute's `key_translation` configuration to an array value (like choices).
     *
     * Part of mapAttributesFromMapping()'s first pass, moved into its own method (#556) so
     * that the file holding that method creates few enough mutants to finish inside the CI
     * mutation job's limit. The value passes through unchanged unless the attribute
     * configures `key_translation` and the value is an array.
     *
     * @param  mixed  $mappedValue  The attribute's value as mapped so far
     * @param  array<int|string, mixed>  $config  The attribute's fieldtype-specific mapping configuration
     * @param  array<int|string, mixed>  $data  The input data (field or subfield)
     * @param  string  $attrName  The attribute name
     * @param  mixed  $attrValue  The attribute's original value in the input data
     * @param  ?string  $excludeFieldtype  Optional source fieldtype for fieldtype-specific configuration
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for #not_set checks
     * @return mixed The value with its keys translated
     */
    protected static function applyKeyTranslation(
        mixed $mappedValue,
        array $config,
        array $data,
        string $attrName,
        mixed $attrValue,
        ?string $excludeFieldtype,
        ?array $originalFieldData
    ): mixed {
        if (
            isset($config['key_translation'])
            && \is_array($config['key_translation'])
            && \is_array($mappedValue)
        ) {
            $keyTranslationConfig = self::mappingConfig(
                $config['key_translation']
            );
            $keyTranslationArray = null;

            // Handle different key_translation structures
            if (
                self::arrayKeyExists(
                    'default',
                    $keyTranslationConfig
                )
                || self::arrayKeyExists(
                    'specific',
                    $keyTranslationConfig
                )
                || self::arrayKeyExists(
                    'based_on_other_attribute_value',
                    $keyTranslationConfig
                )
                || self::arrayKeyExists(
                    'specific_based_on_attribute_value',
                    $keyTranslationConfig
                )
            ) {
                // Complex structure with 'default', 'specific', 'based_on_other_attribute_value', etc.
                $defaultTranslations = self::asArray(
                    $keyTranslationConfig['default'] ?? null
                );
                $specificTranslations = self::asArray(
                    $keyTranslationConfig['specific'] ?? null
                );
                $basedOnOtherAttributeValue = self::asArray(
                    $keyTranslationConfig['based_on_other_attribute_value'] ?? null
                );
                $specificBasedOnAttributeValue = self::asArray(
                    $keyTranslationConfig['specific_based_on_attribute_value'] ?? null
                );

                // Check for specific_based_on_attribute_value first (most specific)
                if (
                    $excludeFieldtype !== null
                    && $excludeFieldtype !== ''
                    && self::arrayKeyExists(
                        $excludeFieldtype,
                        $specificBasedOnAttributeValue
                    )
                ) {
                    $fieldtypeMappings = self::asArray(
                        $specificBasedOnAttributeValue[$excludeFieldtype]
                    );
                    foreach ($fieldtypeMappings as $attributeName => $attributeValueMappings) {
                        $normalizedAttributeName = self::normalizeArrayKey(
                            $attributeName
                        );
                        if ($normalizedAttributeName === null) {
                            continue;
                        }

                        $attributeValueMappings = self::asArray(
                            $attributeValueMappings
                        );

                        // Get the value of the attribute from the field data
                        $attributeValue = $data[$normalizedAttributeName] ?? null;

                        // Check for special cases first
                        if (isset($attributeValueMappings['#not_set'])) {
                            if ($originalFieldData !== null) {
                                if (
                                    self::isFieldOptionNotSet(
                                        $normalizedAttributeName,
                                        $originalFieldData
                                    )
                                ) {
                                    $keyTranslationArray = self::asArray(
                                        $attributeValueMappings['#not_set']
                                    );
                                    break;
                                }
                            } else {
                                if (
                                    self::isAttributeNotSet(
                                        $normalizedAttributeName,
                                        $data
                                    )
                                ) {
                                    $keyTranslationArray = self::asArray(
                                        $attributeValueMappings['#not_set']
                                    );
                                    break;
                                }
                            }
                        } elseif (isset($attributeValueMappings['#not_empty'])) { // Check for '#not_empty' special case
                            if (
                                self::isAttributeNotEmptyWithFallback(
                                    $normalizedAttributeName,
                                    $data,
                                    $originalFieldData
                                )
                            ) {
                                $keyTranslationArray = self::asArray(
                                    $attributeValueMappings['#not_empty']
                                );
                                break;
                            }
                        } elseif (
                            self::arrayKeyExists(
                                $attributeValue,
                                $attributeValueMappings
                            )
                        ) { // Check if this attribute value has specific mappings
                            $keyTranslationArray = self::asArray(
                                self::mappingValue(
                                    $attributeValueMappings,
                                    $attributeValue
                                )
                            );
                            break;
                        }
                    }
                } elseif ($basedOnOtherAttributeValue !== []) { // Check for based_on_other_attribute_value
                    // Check if based_on_other_attribute_value has 'default' and 'specific' structure
                    if (
                        self::arrayKeyExists(
                            'default',
                            $basedOnOtherAttributeValue
                        )
                        || self::arrayKeyExists(
                            'specific',
                            $basedOnOtherAttributeValue
                        )
                    ) {
                        $defaultBasedOnOtherAttributeValue = self::asArray(
                            $basedOnOtherAttributeValue['default'] ?? null
                        );
                        $specificBasedOnOtherAttributeValue = self::asArray(
                            $basedOnOtherAttributeValue['specific'] ?? null
                        );

                        // Use fieldtype-specific configuration if available
                        $configToUse = $defaultBasedOnOtherAttributeValue;
                        if (
                            $excludeFieldtype !== null
                            && $excludeFieldtype !== ''
                            && self::arrayKeyExists(
                                $excludeFieldtype,
                                $specificBasedOnOtherAttributeValue
                            )
                        ) {
                            $configToUse = self::asArray(
                                $specificBasedOnOtherAttributeValue[$excludeFieldtype]
                            );
                        }

                        foreach ($configToUse as $otherAttrName => $valueMappings) {
                            $normalizedOtherAttrName = self::normalizeArrayKey(
                                $otherAttrName
                            );
                            if ($normalizedOtherAttrName === null) {
                                continue;
                            }

                            $valueMappings = self::asArray($valueMappings);
                            $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;

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
                                        $normalizedOtherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::asArray(
                                        $valueMappings['#not_set'] ?? null
                                    );
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '#not_empty',
                                    $valueMappings
                                )
                            ) { // Check for '#not_empty' special case
                                if (
                                    self::isAttributeNotEmpty(
                                        $normalizedOtherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::asArray(
                                        $valueMappings['#not_empty'] ?? null
                                    );
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '#empty',
                                    $valueMappings
                                )
                            ) { // Check for '#empty' special case
                                if (
                                    self::isAttributeEmpty(
                                        $normalizedOtherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::asArray(
                                        $valueMappings['#empty'] ?? null
                                    );
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    $otherAttrValue,
                                    $valueMappings
                                )
                            ) { // Check for exact value match
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        $otherAttrValue
                                    )
                                );
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
                                            $keyTranslationArray = self::asArray(
                                                $currentValueMappings['#not_set'] ?? null
                                            );
                                            break;
                                        }
                                    } else {
                                        if (
                                            self::isAttributeNotSet(
                                                $attrName,
                                                $data
                                            )
                                        ) {
                                            $keyTranslationArray = self::asArray(
                                                $currentValueMappings['#not_set'] ?? null
                                            );
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
                                        $keyTranslationArray = self::asArray(
                                            $currentValueMappings['#not_empty'] ?? null
                                        );
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
                                        $keyTranslationArray = self::asArray(
                                            $currentValueMappings['#empty'] ?? null
                                        );
                                        break;
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        self::mappingLookupKey($mappedValue),
                                        $currentValueMappings
                                    )
                                ) {
                                    $keyTranslationArray = self::asArray(
                                        $currentValueMappings[self::mappingLookupKey($mappedValue)]
                                    );
                                    break;
                                }
                            }
                        }
                    } else {
                        // Legacy structure
                        foreach ($basedOnOtherAttributeValue as $otherAttrName => $valueMappings) {
                            $normalizedOtherAttrName = self::normalizeArrayKey(
                                $otherAttrName
                            );
                            if ($normalizedOtherAttrName === null) {
                                continue;
                            }

                            $valueMappings = self::asArray($valueMappings);
                            $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;

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
                                        $normalizedOtherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::asArray(
                                        $valueMappings['#not_set'] ?? null
                                    );
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '#not_empty',
                                    $valueMappings
                                )
                            ) { // Check for '#not_empty' special case
                                if (
                                    self::isAttributeNotEmpty(
                                        $normalizedOtherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::asArray(
                                        $valueMappings['#not_empty'] ?? null
                                    );
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '#empty',
                                    $valueMappings
                                )
                            ) { // Check for '#empty' special case
                                if (
                                    self::isAttributeEmpty(
                                        $normalizedOtherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::asArray(
                                        $valueMappings['#empty'] ?? null
                                    );
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    $otherAttrValue,
                                    $valueMappings
                                )
                            ) { // Check for exact value match
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        $otherAttrValue
                                    )
                                );
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
                                            $keyTranslationArray = self::asArray(
                                                $currentValueMappings['#not_set'] ?? null
                                            );
                                            break;
                                        }
                                    } else {
                                        if (
                                            self::isAttributeNotSet(
                                                $attrName,
                                                $data
                                            )
                                        ) {
                                            $keyTranslationArray = self::asArray(
                                                $currentValueMappings['#not_set'] ?? null
                                            );
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
                                        $keyTranslationArray = self::asArray(
                                            $currentValueMappings['#not_empty'] ?? null
                                        );
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
                                        $keyTranslationArray = self::asArray(
                                            $currentValueMappings['#empty'] ?? null
                                        );
                                        break;
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        self::mappingLookupKey($mappedValue),
                                        $currentValueMappings
                                    )
                                ) {
                                    $keyTranslationArray = self::asArray(
                                        $currentValueMappings[self::mappingLookupKey($mappedValue)]
                                    );
                                    break;
                                }
                            }
                        }
                    }
                } elseif (
                    $excludeFieldtype !== null
                    && $excludeFieldtype !== ''
                    && self::arrayKeyExists(
                        $excludeFieldtype,
                        $specificTranslations
                    )
                ) { // Check for fieldtype-specific translations
                    $keyTranslationArray = self::asArray(
                        $specificTranslations[$excludeFieldtype]
                    );
                } else { // Fall back to default translations
                    $keyTranslationArray = $defaultTranslations;
                }
            } else {
                // Simple array structure
                $keyTranslationArray = self::asArray($keyTranslationConfig);
            }

            // Apply key translations if we have a valid translation array
            if ($keyTranslationArray !== null) {
                // Normalize value transformations to an array or null so the
                // translation helper receives the expected nullable array.
                $valueTransformationsConfig = $config['value_transformations'] ?? null;
                $valueTransformations = \is_array($valueTransformationsConfig)
                    ? $valueTransformationsConfig
                    : null;
                $mappedValue = self::mapArrayWithKeyTranslation(
                    $mappedValue,
                    $keyTranslationArray,
                    $valueTransformations,
                    $excludeFieldtype,
                    $data
                );
            }

            // Handle separate_value_field_option logic for array values (like Gravity Forms choices)
            if (
                isset($config['separate_value_field_option'])

            ) {
                $separateValueFieldOptionConfig = $config['separate_value_field_option'];

                // Use the DRY helper method to process modifier patterns
                $modifierResult = self::processModifierPatterns(
                    $separateValueFieldOptionConfig,
                    $data,
                    null,
                    $excludeFieldtype,
                    $attrName,
                    $attrValue
                );
                $separateValue = $modifierResult['value'];

                // $separateValue is NOT the field's runtime enableChoiceValue here:
                // processModifierPatterns() resolves the config
                // ['default' => 'enableChoiceValue', 'specific' => ['survey' => null]]
                // to a constant — (bool) 'enableChoiceValue' === true for every
                // non-survey fieldtype, and false only for survey. The real
                // enableChoiceValue-driven label nulling happens upstream in
                // mapArrayWithKeyTranslation() (value_transformations). So this branch
                // only nulls the option value for SURVEY fields, where a separate
                // choice value is not applicable. The prior `!== null && !== ''`
                // condition also fired on the constant `true`, erroneously nulling
                // every non-survey option label; `=== false` restricts it to survey.
                // See wordpress-importer-archive#463.
                if ($separateValue === false) {
                    foreach ($mappedValue as &$option) {
                        if (
                            \is_array($option)
                            && isset($option['value'])
                        ) {
                            $option['value'] = null;
                        }
                    }

                    // Clean up variables to free up memory
                    unset($option);
                }
            }

            // Ensure all options have the expected structure based on fieldtype
            if (isset($config['expected_keys'])) {
                self::normalizeOptionsStructure(
                    $mappedValue,
                    $data,
                    $attrValue,
                    $excludeFieldtype,
                    $attrName
                );
            }
        }

        return $mappedValue;
    }

    /**
     * Map array values using the provided key translation array.
     *
     * This method is specifically for handling complex array transformations
     * like choices in select fields, where the key of the option might need
     * to be translated.
     *
     * @param  array<int|string, mixed>  $array  The original array
     * @param  array<int|string, mixed>  $keyTranslation  The key translation array
     * @param  array<int|string, mixed>|null  $valueTransformations  Optional array of value transformations to apply to specific keys
     * @param  array<int|string, mixed>|null  $data  Optional field data for context
     * @return array<int|string, mixed> The mapped array
     */
    protected static function mapArrayWithKeyTranslation(
        array $array,
        array $keyTranslation,
        ?array $valueTransformations = null,
        ?string $fieldtype = null,
        ?array $data = null
    ): array {
        // Initialize array to store mapped array elements
        $mappedArray = [];

        // Check if this is an array of objects (like choices array)
        $isArrayOfObjects = $array !== [] &&
            \is_array(reset($array)) &&
            \array_keys(reset($array)) !== range(
                0,
                \count(reset($array)) - 1
            );

        // If this is an array of objects (e.g., array of choices), process each object individually
        if ($isArrayOfObjects) {
            // This is an array of objects, transform each object
            // Iterate through each object in the array and apply key translation
            foreach ($array as $index => $object) {
                // Count this object by its own index, so a value generated for
                // a missing key never takes the count of an earlier object
                $indexCount = self::numericChoiceCount($index);

                // Initialize a new mapped object for the translated keys/values
                $mappedObject = [];
                $objectArray = self::asArray($object);

                // Transform each key in the object
                foreach ($objectArray as $key => $value) {
                    // Check if the key exists in the translation array
                    if (
                        self::arrayKeyExists(
                            $key,
                            $keyTranslation
                        )
                    ) {
                        // If the translation is null, skip this key entirely
                        $translatedKey = self::mappingValue(
                            $keyTranslation,
                            $key
                        );
                        if ($translatedKey === null) {
                            // Skip this key entirely
                            continue;
                        }

                        // Apply value transformations if configured
                        $transformedValue = $value;
                        $normalizedKey = self::normalizeArrayKey($key);
                        if (
                            $valueTransformations !== null
                            && $valueTransformations !== []
                            && $normalizedKey !== null
                        ) {
                            $transformationConfig = static::resolveValueTransformations(
                                $valueTransformations,
                                $data ?? [],
                                self::normalizeTransformationKey($normalizedKey),
                                $fieldtype
                            );
                            if ($transformationConfig !== null && $transformationConfig !== []) {
                                $transformedValue = static::applyValueTransformation(
                                    $value,
                                    $transformationConfig,
                                    $data,
                                    $indexCount
                                );
                            }
                        }

                        // Add the translated key and transformed value to the new object
                        $mappedObject[self::normalizeArrayKeyForAssignment($translatedKey)] = $transformedValue;
                    } else { // Otherwise
                        // Apply value transformations if configured
                        $transformedValue = $value;
                        $normalizedKey = self::normalizeArrayKey($key);
                        if (
                            $valueTransformations !== null
                            && $valueTransformations !== []
                            && $normalizedKey !== null
                        ) {
                            $transformationConfig = static::resolveValueTransformations(
                                $valueTransformations,
                                $data ?? [],
                                self::normalizeTransformationKey($normalizedKey),
                                $fieldtype
                            );
                            if ($transformationConfig !== null && $transformationConfig !== []) {
                                $transformedValue = static::applyValueTransformation(
                                    $value,
                                    $transformationConfig,
                                    $data,
                                    $indexCount
                                );
                            }
                        }

                        // Add the original key and transformed value to the new object
                        $mappedObject[self::normalizeArrayKeyForAssignment($key)] = $transformedValue;
                    }
                }

                // Check for generate_value transformations for missing keys
                if ($valueTransformations !== null && $valueTransformations !== []) {
                    // Get the expected keys for this fieldtype to check what keys should be generated
                    $expectedKeys = static::getExpectedKeys(
                        $data ?? [],
                        null,
                        $fieldtype
                    );
                    foreach ($expectedKeys as $expectedKey) {
                        $normalizedExpectedKey = self::normalizeArrayKey($expectedKey);
                        if ($normalizedExpectedKey === null) {
                            continue;
                        }

                        if (
                            ! self::arrayKeyExists(
                                $normalizedExpectedKey,
                                $mappedObject
                            )
                        ) {
                            // Try to resolve transformation config for this key
                            $transformationConfig = static::resolveValueTransformations(
                                $valueTransformations,
                                $data ?? [],
                                self::normalizeTransformationKey($normalizedExpectedKey),
                                $fieldtype
                            );
                            $generateConfig = self::asArray(
                                self::mappingValue(
                                    self::asArray($transformationConfig),
                                    'generate_value'
                                )
                            );
                            if ($generateConfig !== []) {
                                // Merge field data with current choice item data for context
                                $contextData = \array_merge(
                                    $data ?? [],
                                    $objectArray
                                );
                                $generatedValue = static::generateValue(
                                    $generateConfig,
                                    $contextData,
                                    $indexCount ?? 1
                                );
                                $mappedObject[$normalizedExpectedKey] = $generatedValue;
                            }
                        }
                    }
                }

                // Add the transformed object to the mapped array
                $mappedArray[] = $mappedObject;
            }
        } else { // Otherwise
            // This is a simple associative array, transform keys directly
            // Iterate through each element in the original array
            foreach ($array as $key => $value) {
                // Check if the key exists in the translation array
                $translatedKey = self::mappingValue(
                    $keyTranslation,
                    $key
                );
                if ($translatedKey !== null) {
                    // Add the translated key and original value to the new array
                    $mappedArray[self::normalizeArrayKeyForAssignment($translatedKey)] = $value;
                } else { // Otherwise
                    // Add the original key and value to the new array
                    $mappedArray[self::normalizeArrayKeyForAssignment($key)] = $value;
                }
            }
        }

        // Return the mapped array
        return $mappedArray;
    }

    /**
     * Convert Gravity Forms choices array to Statamic fields format.
     *
     * This method transforms a Gravity Forms choices array into the format expected
     * by Statamic grid fields, where each choice becomes a separate field with
     * its own handle and field configuration.
     *
     * @param  array<int|string, mixed>  $choices  The Gravity Forms choices array
     * @param  array<int|string, mixed>  $fieldData  The complete field data for context
     * @return array<int|string, mixed> The converted fields array
     */
    protected static function convertChoicesToFields(
        array $choices,
        array $fieldData
    ): array {
        $fields = [];
        $choiceCount = 1;

        // Use original choices from fieldData if available, otherwise fall back to processed choices
        $originalChoices = self::asArray(
            self::mappingValue(
                $fieldData,
                'choices'
            ) ?? $choices
        );

        // Get the parent field name and ID for handle generation
        $parentFieldName = static::getFieldNameFromField($fieldData);
        $parentFieldId = self::normalizeMixedToString(
            self::mappingValue(
                $fieldData,
                'id'
            ) ?? ''
        );

        // Generate the parent field handle using the existing method
        $parentHandle = static::convertFieldNameToHandle(
            $parentFieldName ?? '',
            $parentFieldId
        );

        foreach ($originalChoices as $choice) {
            $choiceData = self::asArray($choice);
            // Extract the choice text/value for the field display name
            $choiceText = self::stringValue(
                self::mappingValue(
                    $choiceData,
                    'text'
                )
            ) ?? self::stringValue(
                self::mappingValue(
                    $choiceData,
                    'value'
                )
            ) ?? '';

            // Generate a snake_case handle for this choice
            $choiceHandle = self::convertToSnakeCase($choiceText);

            // Create the field handle: <parent_handle>_<snake_case_choice_value>_<choice_count>
            $fieldHandle = $parentHandle.'_'.$choiceHandle.'_'.$choiceCount;

            // Create the field configuration
            $fieldConfig = [
                'handle' => $fieldHandle,
                'field' => [
                    'type' => 'text',
                    'display' => $choiceText,
                ],
            ];

            $fields[] = $fieldConfig;
            $choiceCount++;
        }

        return $fields;
    }

    /**
     * Get the expected keys for options arrays for a given fieldtype.
     *
     * This method looks up the expected_keys configuration from the choices mapping
     * to determine which keys should be present in the options array for each
     * fieldtype.
     *
     * @param  array<int|string, mixed>  $data  The field data to check for based_on_other_attribute_value conditions
     * @param  mixed  $attrValue  The attribute value for based_on_attribute_value checks
     * @param  ?string  $fieldtype  The Statamic fieldtype
     * @param  ?string  $attrName  The attribute name for based_on_attribute_value checks
     * @return array<string> The expected keys for the fieldtype
     */
    public static function getExpectedKeys(
        array $data = [],
        mixed $attrValue = null,
        ?string $fieldtype = null,
        int|string|null $attrName = null
    ): array {
        // Get the choices configuration from the implementing class
        $choicesConfig = static::$fieldAttributeMapping['choices'] ?? null;
        if (
            $choicesConfig === null
            || $choicesConfig === []
            || ! isset($choicesConfig['expected_keys'])
        ) {
            // Fallback to default keys if no configuration exists
            return ['key', 'value'];
        }

        $expectedKeysConfig = self::asArray(
            self::mappingValue(
                self::mappingConfig($choicesConfig),
                'expected_keys'
            )
        );
        $defaultExpectedKeys = self::asArray(
            self::mappingValue(
                $expectedKeysConfig,
                'default'
            ) ?? ['key', 'value']
        );

        // Use the DRY helper method to process modifier patterns
        $resolvedKeys = self::processModifierPatternsWithReturn(
            $expectedKeysConfig,
            $attrValue,
            $data,
            $defaultExpectedKeys,
            null,
            $fieldtype,
            $attrName
        );

        return \array_values(
            \array_map(
                self::normalizeMixedToString(...),
                self::asArray($resolvedKeys)
            )
        );
    }

    /**
     * Ensure all options in an array have the expected structure based on fieldtype.
     *
     * This method adds missing keys to each option array based on the expected_keys
     * configuration for the given fieldtype.
     *
     * @param  array<int|string, mixed>  &$options  The options array to normalize (passed by reference)
     * @param  array<int|string, mixed>  $data  The field data to check for based_on_other_attribute_value conditions
     * @param  mixed  $attrValue  The attribute value for based_on_attribute_value checks
     * @param  ?string  $fieldtype  The Statamic fieldtype
     * @param  ?string  $attrName  The attribute name for based_on_attribute_value checks
     */
    protected static function normalizeOptionsStructure(
        array &$options,
        array $data = [],
        mixed $attrValue = null,
        ?string $fieldtype = null,
        int|string|null $attrName = null
    ): void {
        if ($options === []) {
            return;
        }

        $expectedKeys = self::getExpectedKeys(
            $data,
            $attrValue,
            $fieldtype,
            $attrName
        );

        // Add missing keys to each option
        foreach ($options as &$option) {
            if (\is_array($option)) {
                foreach ($expectedKeys as $expectedKey) {
                    if (
                        ! self::arrayKeyExists(
                            $expectedKey,
                            $option
                        )
                    ) {
                        // Add missing key with null value
                        $option[$expectedKey] = null;
                    }
                }
            }
        }

        // Clean up variables to free up memory
        unset($option);
    }

    /**
     * Convert a string to snake_case.
     *
     * @param  string  $string  The string to convert
     * @return string The snake_case version
     */
    protected static function convertToSnakeCase(
        string $string
    ): string {
        // Convert to lowercase and trim whitespace
        $handle = \strtolower(\trim($string));

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

        return $handle;
    }
}
