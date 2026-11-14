<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SafeMathEvaluator;

/**
 * Translates attribute values: value translations and their index-array form, regex
 * substitution, calculations, field and calculation references, and the empty-value
 * mapping.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait TranslatesAttributeValues
{
    /**
     * Translate field references in text values to Statamic field handles.
     *
     * This method replaces Formidable Forms field references like [field_name] or
     * [329] with the appropriate Statamic field handles wrapped in square brackets.
     *
     * @param  string  $text  The text containing field references
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @param  array<int|string, mixed>|null  $currentField  Optional current field data for [field_name] replacement
     * @return string The text with field references translated
     */
    protected static function translateFieldReferences(
        string $text,
        array $allFields = [],
        ?array $currentField = null
    ): string {
        // Pattern to match field references: [field_name] or [329]
        $pattern = '/\[([^\]]+)\]/';

        $translatedText = \preg_replace_callback(
            $pattern,
            function ($matches) use ($allFields, $currentField): string {
                $reference = $matches[1];

                // Check if it's a numeric field ID
                if (\is_numeric($reference)) {
                    // Find the field by ID
                    foreach ($allFields as $field) {
                        $fieldData = self::asArray($field);
                        $candidateFieldId = self::mappingValue($fieldData, 'id');
                        if (
                            $candidateFieldId !== null
                            && self::normalizeMixedToString($candidateFieldId) === $reference
                        ) {
                            // Get the field name/label using the implementing class's method
                            $fieldName = static::getFieldNameFromField($fieldData);
                            if ($fieldName !== null && $fieldName !== '') {
                                // Convert to Statamic handle format and wrap in square brackets
                                return '['.static::convertFieldNameToHandle(
                                    $fieldName,
                                    $reference
                                ).']';
                            }
                        }
                    }
                } else { // Otherwise
                    // Check if it's a field name reference (like [field_name])
                    if ($reference === 'field_name') {
                        // Replace with the current field's name if available
                        if ($currentField !== null && $currentField !== []) {
                            $fieldName = static::getFieldNameFromField($currentField);
                            if ($fieldName !== null && $fieldName !== '') {
                                $fieldId = self::normalizeMixedToString(
                                    self::mappingValue(
                                        $currentField,
                                        'id'
                                    ) ?? 'unknown'
                                );

                                return '['.static::convertFieldNameToHandle(
                                    $fieldName,
                                    $fieldId
                                ).']';
                            }
                        }

                        // Fallback to a generic placeholder if no current field
                        return '[current_field_name]';
                    }

                    // Try to find the field by name/label
                    foreach ($allFields as $field) {
                        $fieldData = self::asArray($field);
                        $fieldName = static::getFieldNameFromField($fieldData);
                        if ($fieldName === $reference) {
                            // Convert to Statamic handle format and wrap in square brackets
                            $fieldId = self::normalizeMixedToString(
                                self::mappingValue(
                                    $fieldData,
                                    'id'
                                ) ?? 'unknown'
                            );

                            return '['.static::convertFieldNameToHandle(
                                $fieldName,
                                $fieldId
                            ).']';
                        }
                    }
                }

                // If no match found, return the original reference
                return $matches[0];
            },
            $text
        );

        return $translatedText ?? $text;
    }

    /**
     * Apply regex substitution patterns to a value.
     *
     * This method applies a series of regex patterns to transform a value.
     * The patterns support variable-length expressions for lookbehind and lookahead
     * assertions, and the replacement values support referencing captured groups
     * using either \1 or $1 syntax.
     *
     * @param  string  $value  The value to transform
     * @param  array<int|string, mixed>  $patterns  Array of regex patterns where keys are patterns and values are replacements
     * @return string The transformed value
     */
    protected static function applyValueSubstitutionRegex(
        string $value,
        array $patterns
    ): string {
        $result = $value;

        foreach ($patterns as $pattern => $replacement) {
            if (
                ! \is_string($pattern)
                || ! \is_string($replacement)
            ) {
                continue;
            }

            // Apply the regex substitution
            $result = \preg_replace(
                $pattern,
                $replacement,
                $result
            ) ?? $result;
        }

        return $result;
    }

    /**
     * Apply mathematical calculations to a value.
     *
     * This method evaluates mathematical expressions, replacing [value] with the
     * original value. The expression is parsed by {@see SafeMathEvaluator}, whose
     * closed grammar covers the four operators, parentheses, decimals and
     * `round()`; anything outside it leaves the value untouched (#2).
     *
     * @param  mixed  $value  The value to apply calculations to
     * @param  string  $expression  The mathematical expression with [value] placeholder
     * @return mixed The calculated result or original value if calculation fails
     */
    protected static function applyValueCalculation(
        mixed $value,
        string $expression
    ): mixed {
        // Only process if the value is numeric
        if (! \is_numeric($value)) {
            return $value;
        }

        // Replace [value] with the actual value
        $expression = \str_replace(
            '[value]',
            (string) $value,
            $expression
        );

        // Parse and evaluate; a null result means the expression is not valid
        // arithmetic, in which case the original value stands
        $result = SafeMathEvaluator::evaluate($expression);

        if ($result === null) {
            return $value;
        }

        // Return a whole result as an int, so a calculation feeding an integer
        // field such as `character_limit` does not write `1198.0`
        return $result === \floor($result)
            && \abs($result) < (float) PHP_INT_MAX
            ? (int) $result
            : $result;
    }

    /**
     * Apply value translation to a given value.
     *
     * This method handles value translation using the value_translation
     * configuration. It supports both simple associative arrays and complex
     * structures with default, specific, and based_on_other_attribute_value
     * configurations.
     *
     * The method now supports two different value_translation structures:
     *
     *   1. **Associative Array Structure** (legacy):
     *      ```php
     *      'value_translation' => [
     *          'default' => [
     *              'foo' => 'bar',
     *              'baz' => 'qux',
     *          ],
     *      ],
     *      ```
     *
     *   2. **Index Array Structure** (new):
     *      ```php
     *      'value_translation' => [
     *          'default' => [
     *              [
     *                  'search' => ['foo', 'bar'],
     *                  'replace' => ['baz', 'qux'],
     *              ],
     *              [
     *                  'search' => 'quux',
     *                  'replace' => 'grault',
     *              ],
     *              [
     *                  'search' => 'waldo',
     *                  'replace' => null, // Excludes the value
     *              ],
     *          ],
     *      ],
     *      ```
     *
     * **Index Array Features:**
     *   - `search` can be a single value or an array of values to match
     *   - `replace` can be a single value, an array, or null (to exclude)
     *   - Supports wildcard patterns in search values (e.g., 'prefix_*')
     *   - Processes rules in order, using the first match found
     *   - Handles array values by translating each element individually
     *   - Supports boolean to string conversion for matching
     *
     * @param  mixed  $value  The value to translate
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'value_translation' key
     * @param  ?string  $attrName  The attribute name being processed
     * @param  array<int|string, mixed>  $data  The full data array for other attribute lookups
     * @param  ?string  $excludeFieldtype  The fieldtype to exclude
     * @param  array<int|string, mixed>|null  $originalFieldData  Original field data for fallback checks
     * @param  mixed  $statamicAttr  The statamic attribute value
     * @return mixed The translated value
     */
    protected static function applyValueTranslation(
        mixed $value,
        mixed $config,
        int|string|null $attrName = null,
        $data = [],
        $excludeFieldtype = null,
        $originalFieldData = null,
        $statamicAttr = null
    ) {
        $configArray = self::mappingConfig($config);
        $dataArray = self::asArray($data);
        $excludeFieldtypeString = self::stringValue($excludeFieldtype) ?? '';

        // Check if value_translation configuration exists
        if (
            ! self::arrayKeyExists(
                'value_translation',
                $configArray
            )
            || $statamicAttr === null
        ) {
            return $value;
        }

        $translationConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'value_translation'
            )
        );

        // Automatically inject #empty => null mapping if not present
        $translationConfig = self::injectEmptyValueMapping($translationConfig);

        // Check if there's a direct array replacement in value_translation first
        $arrayReplacement = self::checkForArrayReplacement(
            $value,
            $translationConfig
        );
        if ($arrayReplacement !== false) {
            return $arrayReplacement;
        }

        // Handle array values by processing each element individually
        if (\is_array($value)) {
            // Process each element in the array
            $translatedArray = [];
            foreach ($value as $item) {
                $translatedItem = self::applyValueTranslation(
                    $item,
                    $configArray,
                    $attrName,
                    $dataArray,
                    $excludeFieldtype,
                    $originalFieldData,
                    $statamicAttr
                );
                $translatedArray[] = $translatedItem;
            }

            return $translatedArray;
        }

        $translationArray = null;

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
            $defaultTranslations = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'default'
                )
            );
            $specificTranslations = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'specific'
                )
            );
            $basedOnOtherAttributeValue = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'based_on_other_attribute_value'
                )
            );

            // Check for based_on_other_attribute_value first (most specific)
            if ($basedOnOtherAttributeValue !== []) {
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
                        self::mappingValue(
                            $basedOnOtherAttributeValue,
                            'default'
                        )
                    );
                    $specificBasedOnOtherAttributeValue = self::asArray(
                        self::mappingValue(
                            $basedOnOtherAttributeValue,
                            'specific'
                        )
                    );

                    // Use fieldtype-specific configuration if available
                    $configToUse = $defaultBasedOnOtherAttributeValue;
                    if (
                        $excludeFieldtypeString !== ''
                        && self::arrayKeyExists(
                            $excludeFieldtypeString,
                            $specificBasedOnOtherAttributeValue
                        )
                    ) {
                        $configToUse = self::asArray(
                            self::mappingValue(
                                $specificBasedOnOtherAttributeValue,
                                $excludeFieldtypeString
                            )
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
                        $otherAttrValue = $dataArray[$normalizedOtherAttrName] ?? null;

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
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#not_set'
                                    )
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
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#not_empty'
                                    )
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
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#empty'
                                    )
                                );
                            }
                        } elseif (
                            self::arrayKeyExists(
                                $otherAttrValue,
                                $valueMappings
                            )
                        ) { // Check for exact value match (with boolean to string conversion)
                            $currentValueMappings = self::asArray(
                                self::mappingValue(
                                    $valueMappings,
                                    $otherAttrValue
                                )
                            );
                        } elseif (\is_bool($otherAttrValue)) { // Handle boolean to string conversion for comparison
                            $stringValue = $otherAttrValue ? 'true' : 'false';
                            if (
                                self::arrayKeyExists(
                                    $stringValue,
                                    $valueMappings
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        $stringValue
                                    )
                                );
                            }
                        }

                        // If we found mappings for the other attribute's value, apply them to this attribute
                        if ($currentValueMappings !== null) {
                            // Hand the resolved inner mapping to the shared
                            // general value-translation applicator below, which
                            // resolves #empty / #not_set /
                            // #not_empty / exact-value / search-replace lists and
                            // ASSIGNS the result to $value. The previous per-branch
                            // pre-resolution set matchFound and skipped that
                            // applicator, so the resolved value was computed and
                            // then discarded — max_files was never emitted and
                            // address default-state names were never abbreviated.
                            // See wordpress-importer-archive#463.
                            $translationArray = $currentValueMappings;
                            break;
                        }
                    }
                } else { // Otherwise
                    // Legacy structure
                    foreach ($basedOnOtherAttributeValue as $otherAttrName => $valueMappings) {
                        $normalizedOtherAttrName = self::normalizeArrayKey(
                            $otherAttrName
                        );
                        if ($normalizedOtherAttrName === null) {
                            continue;
                        }

                        $valueMappings = self::asArray($valueMappings);
                        $otherAttrValue = $dataArray[$normalizedOtherAttrName] ?? null;

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
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#not_set'
                                    )
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
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#not_empty'
                                    )
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
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#empty'
                                    )
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
                            // Hand the resolved inner mapping to the shared
                            // general value-translation applicator below, which
                            // resolves #empty / #not_set /
                            // #not_empty / exact-value / search-replace lists and
                            // ASSIGNS the result to $value. The previous per-branch
                            // pre-resolution set matchFound and skipped that
                            // applicator, so the resolved value was computed and
                            // then discarded — max_files was never emitted and
                            // address default-state names were never abbreviated.
                            // See wordpress-importer-archive#463.
                            $translationArray = $currentValueMappings;
                            break;
                        }
                    }
                }
            } elseif (
                $excludeFieldtypeString !== ''
                && self::arrayKeyExists(
                    $excludeFieldtypeString,
                    $specificTranslations
                )
            ) { // Check for fieldtype-specific translations
                $translationArray = self::asArray(
                    self::mappingValue(
                        $specificTranslations,
                        $excludeFieldtypeString
                    )
                );
            } else { // Fall back to default translations
                $translationArray = $defaultTranslations;
            }
        } else { // Otherwise
            // Simple array structure
            $translationArray = $translationConfig;
        }

        // Apply translations if we have a valid translation array
        // Skip general translation logic if based_on_other_attribute_value provided a value
        if (
            $translationArray !== null
        ) {
            // Check for '#not_set' special case first
            if (
                self::arrayKeyExists(
                    '#not_set',
                    $translationArray
                )
            ) {
                if (
                    self::isAttributeNotSetWithFallback(
                        $attrName,
                        $dataArray,
                        $originalFieldData
                    )
                ) {
                    $value = self::mappingValue(
                        $translationArray,
                        '#not_set'
                    );
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
                        $dataArray,
                        $originalFieldData
                    )
                ) {
                    $value = self::mappingValue(
                        $translationArray,
                        '#not_empty'
                    );
                }
            }

            // Check for '#empty' special case
            if (
                self::arrayKeyExists(
                    '#empty',
                    $translationArray
                )
            ) {
                // For array processing, only check if the current value is empty, not the original attribute
                // The original attribute might be empty because it's being populated by combining other attributes
                $isCurrentValueEmpty = ($value === '' || $value === null);

                if ($isCurrentValueEmpty) {
                    $value = self::mappingValue(
                        $translationArray,
                        '#empty'
                    );
                }
            }

            // Check if this is an index array structure with search/replace pairs
            if (self::isIndexArrayWithSearchReplace($translationArray)) {
                $value = self::applyIndexArrayTranslations(
                    $value,
                    $translationArray,
                    $attrName,
                    $dataArray,
                    $originalFieldData
                );
            } elseif (
                (
                    \is_string($value)
                    || \is_int($value)
                    || \is_bool($value)
                )
                && self::arrayKeyExists(
                    $value,
                    $translationArray
                )
            ) { // Check for exact value match first (associative array structure)
                $value = self::mappingValue(
                    $translationArray,
                    $value
                );
            } elseif (\is_string($value)) { // If no exact match, try pattern matching for wildcard patterns
                foreach ($translationArray as $pattern => $replacement) {
                    // Skip special cases that were already handled
                    if (
                        in_array($pattern, ['#not_set', '#not_empty', '#empty'], true)
                    ) {
                        continue;
                    }

                    // Normalize the pattern key to a string so wildcard
                    // detection and matching operate on string-only inputs.
                    $patternString = self::stringValue($pattern);
                    if ($patternString === null) {
                        continue;
                    }

                    // Check if this is a wildcard pattern (contains *)
                    if (
                        \str_contains(
                            $patternString,
                            '*'
                        )
                    ) {
                        if (
                            self::matchesPattern(
                                $value,
                                $patternString
                            )
                        ) {
                            // Resolve wildcards in the replacement
                            $replacementString = self::stringValue($replacement);
                            if ($replacementString === null) {
                                continue;
                            }

                            $value = self::resolveWildcards(
                                $replacementString,
                                $value,
                                $patternString
                            );
                            break; // Use the first matching pattern
                        }
                    }
                }
            }
        }

        return $value;
    }

    /**
     * Check if the translation array is an index array structure with search/replace
     * pairs.
     *
     * @param  array<int|string, mixed>  $translationArray  The translation array to check
     * @return bool True if this is an index array with search/replace structure
     */
    protected static function isIndexArrayWithSearchReplace(
        array $translationArray
    ): bool {
        // Must be a non-empty array
        if ($translationArray === []) {
            return false;
        }

        // Check if all elements are arrays with 'search' and 'replace' keys
        foreach ($translationArray as $item) {
            if (! \is_array($item)) {
                return false;
            }

            // Must have 'search' key
            if (
                ! self::arrayKeyExists(
                    'search',
                    $item
                )
            ) {
                return false;
            }

            // Must have 'replace' key
            if (
                ! self::arrayKeyExists(
                    'replace',
                    $item
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply translations using index array structure with search/replace pairs.
     *
     * @param  mixed  $value  The value to translate
     * @param  array<int|string, mixed>  $translationArray  The index array with search/replace pairs
     * @param  ?string  $attrName  The attribute name for placeholder context
     * @param  array<int|string, mixed>  $data  The data array for placeholder context
     * @param  array<int|string, mixed>|null  $originalFieldData  The original field data for placeholder context
     * @return mixed The translated value
     */
    protected static function applyIndexArrayTranslations(
        mixed $value,
        array $translationArray,
        int|string|null $attrName = null,
        array $data = [],
        ?array $originalFieldData = null
    ) {
        // Process each search/replace pair first (try to match the entire value)
        foreach ($translationArray as $translationRule) {
            $translationRuleArray = self::asArray($translationRule);
            $search = self::mappingValue(
                $translationRuleArray,
                'search'
            );
            $replace = self::mappingValue(
                $translationRuleArray,
                'replace'
            );

            if (
                self::valueMatches(
                    $value,
                    $search,
                    $attrName,
                    $data,
                    $originalFieldData
                )
            ) {
                // Apply the replacement
                return self::applyReplacement(
                    $replace,
                    $value,
                    $search
                );
            }
        }

        // No match found, return original value
        return $value;
    }

    /**
     * Check if a value matches a search pattern.
     *
     * @param  mixed  $value  The value to check
     * @param  mixed  $search  The search pattern
     * @param  ?string  $attrName  The attribute name for placeholder context
     * @param  array<int|string, mixed>  $data  The data array for placeholder context
     * @param  array<int|string, mixed>|null  $originalFieldData  The original field data for placeholder context
     * @return bool True if the value matches the search pattern
     */
    protected static function valueMatches(
        mixed $value,
        mixed $search,
        int|string|null $attrName = null,
        array $data = [],
        ?array $originalFieldData = null
    ): bool {
        // Handle special placeholders in search values
        if (\is_string($search)) {
            // Check for '#not_empty' placeholder
            if ($search === '#not_empty') {
                return self::isAttributeNotEmptyWithFallback(
                    $attrName,
                    $data,
                    $originalFieldData
                );
            }

            // Check for '#not_set' placeholder
            if ($search === '#not_set') {
                return self::isAttributeNotSetWithFallback(
                    $attrName,
                    $data,
                    $originalFieldData
                );
            }

            // Check for '#empty' placeholder
            if ($search === '#empty') {
                return self::isAttributeEmptyWithFallback(
                    $attrName,
                    $data,
                    $originalFieldData
                );
            }
        }

        // Exact match for scalar values
        if (\is_scalar($value) && \is_scalar($search)) {
            // Check for wildcard patterns in search string
            if (
                \is_string($search)
                && \is_string($value)
                && \str_contains(
                    $search,
                    '*'
                )
            ) {
                return self::matchesPattern(
                    $value,
                    $search
                );
            }

            return $value === $search;
        }

        // Handle array values by converting to string representation
        if (\is_array($value) && \is_scalar($search)) {
            return \json_encode($value) === $search;
        }

        // Handle array-to-array matching
        if (\is_array($value) && \is_array($search)) {
            // Check for exact array match (same elements, same order)
            return $value === $search;
        }

        return false;
    }

    /**
     * Apply a replacement value to a matched value.
     *
     * @param  mixed  $replace  The replacement value
     * @param  mixed  $originalValue  The original value that was matched
     * @param  mixed  $searchPattern  The search pattern that was matched
     * @return mixed The replacement value
     */
    protected static function applyReplacement(
        mixed $replace,
        mixed $originalValue,
        $searchPattern
    ) {
        // If replacement is null, return null (exclude the value)
        if ($replace === null) {
            return null;
        }

        // If replacement is an array, return it as-is
        if (\is_array($replace)) {
            return $replace;
        }

        // If replacement is a string and the search pattern contains wildcards,
        // resolve wildcards in the replacement
        if (
            \is_string($replace)
            && \is_string($searchPattern)
            && \str_contains(
                $searchPattern,
                '*'
            )
        ) {
            return self::resolveWildcards(
                $replace,
                self::normalizeMixedToString($originalValue),
                $searchPattern
            );
        }

        // Return the replacement value as-is
        return $replace;
    }

    /**
     * Translate calculation field references from Gravity Forms format to Statamic
     * format.
     *
     * Converts Gravity Forms calculation field references like:
     *   - {Math Calculation Target Field 1:1016} -> [math_calculation_target_field_1_1016]
     *   - {Field Label:123} -> [field_handle_123]
     *
     * @param  string  $text  The text containing field references
     * @param  array<int|string, mixed>  $allFields  Array of all form fields
     * @param  array<int|string, mixed>|null  $currentField  Current field data for context
     * @return string The text with translated field references
     */
    protected static function translateCalculationFieldReferences(
        string $text,
        array $allFields = [],
        ?array $currentField = null
    ): string {
        // Pattern to match Gravity Forms calculation field references: {descriptor:field_id}
        $pattern = '/\{([^}]+)\}/';

        $translatedText = \preg_replace_callback(
            $pattern,
            function ($matches) use ($allFields): string {
                $reference = $matches[1];

                // Parse the content to find the field ID
                $parts = \explode(
                    ':',
                    $reference
                );

                // If there's only one part, it's not a field reference
                if (\count($parts) < 2) {
                    return $matches[0]; // Return original unchanged
                }

                // Find the field ID: the first part that is a number (integer or decimal)
                $fieldId = array_find($parts, fn ($part): bool => \preg_match(
                    '/^\d+(?:\.\d+)?$/',
                    $part
                ) === 1);

                // If no field ID found, this is not a field reference
                if ($fieldId === null) {
                    return $matches[0]; // Return original unchanged
                }

                // Find the field handle for this field ID
                $fieldHandle = self::getFieldHandleById(
                    $fieldId,
                    $allFields
                );

                if ($fieldHandle !== null) {
                    return "[{$fieldHandle}]";
                }

                // If field handle not found, return original unchanged
                return $matches[0];
            },
            $text
        );

        return $translatedText ?? $text;
    }

    /**
     * Automatically inject #empty => null mapping to value_translation configurations
     * if they don't already have it. This prevents unexpected attributes from being
     * added when empty values are processed.
     *
     * @param  array<int|string, mixed>  $translationConfig  The value_translation configuration array
     * @return array<int|string, mixed> The modified configuration with #empty => null mapping added
     */
    protected static function injectEmptyValueMapping(
        array $translationConfig
    ): array {
        // Handle simple array structure
        if (
            ! self::arrayKeyExists(
                'default',
                $translationConfig
            )
            && ! self::arrayKeyExists(
                'specific',
                $translationConfig
            )
            && ! self::arrayKeyExists(
                'based_on_other_attribute_value',
                $translationConfig
            )
        ) {

            // Check if this is an index array structure with search/replace pairs
            if (self::isIndexArrayWithSearchReplace($translationConfig)) {
                // For index arrays, we don't inject #empty => null as they handle empty values differently
                // The empty value handling is done through the search/replace logic
                return $translationConfig;
            }

            // Simple associative structure - add #empty => null if not present
            if (
                ! self::arrayKeyExists(
                    '#empty',
                    $translationConfig
                )
            ) {
                $translationConfig['#empty'] = null;
            }

            return $translationConfig;
        }

        // Handle complex structure with 'default', 'specific', etc.

        // Add #empty => null to default translations if not present
        if (
            self::arrayKeyExists(
                'default',
                $translationConfig
            )
        ) {
            $defaultConfig = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'default'
                )
            );
            // Check if default is an index array structure
            if (self::isIndexArrayWithSearchReplace($defaultConfig)) {
                // For index arrays, we don't inject #empty => null
                // The empty value handling is done through the search/replace logic
            } else { // Otherwise
                // Associative structure - add #empty => null if not present
                if (
                    ! self::arrayKeyExists(
                        '#empty',
                        $defaultConfig
                    )
                ) {
                    $defaultConfig['#empty'] = null;
                }
            }

            $translationConfig['default'] = $defaultConfig;
        }

        // Add #empty => null to specific translations if not present
        if (
            self::arrayKeyExists(
                'specific',
                $translationConfig
            )
        ) {
            $specificConfig = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'specific'
                )
            );
            foreach ($specificConfig as $fieldtype => $translations) {
                $normalizedFieldtype = self::normalizeArrayKeyForAssignment(
                    $fieldtype
                );
                $translationsArray = self::asArray($translations);
                if ($translationsArray !== []) {
                    // Check if this fieldtype's translations is an index array structure
                    if (self::isIndexArrayWithSearchReplace($translationsArray)) {
                        // For index arrays, we don't inject #empty => null
                        // The empty value handling is done through the search/replace logic
                    } else { // Otherwise
                        // Associative structure - add #empty => null if not present
                        if (
                            ! self::arrayKeyExists(
                                '#empty',
                                $translationsArray
                            )
                        ) {
                            $translationsArray['#empty'] = null;
                        }
                    }
                }

                $specificConfig[$normalizedFieldtype] = $translationsArray;
            }

            $translationConfig['specific'] = $specificConfig;
        }

        // Don't inject #empty => null into based_on_other_attribute_value structures
        // as they have their own logic for handling empty values

        return $translationConfig;
    }
}
