<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Matches attribute values against the patterns a mapping configures: nested attributes,
 * associative arrays, arrays of arrays and wildcards.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait MatchesAttributePatterns
{
    /**
     * Check if a value matches a pattern with wildcards.
     *
     * @param  mixed  $value  The value to check
     * @param  mixed  $pattern  The pattern to match against (supports * wildcards)
     * @return bool True if the value matches the pattern
     */
    protected static function matchesPattern(
        mixed $value,
        mixed $pattern
    ): bool {
        // If value is null, it can't match any pattern
        if ($value === null) {
            return false;
        }

        // Handle boolean values - convert to string for comparison
        if (\is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        if (\is_bool($pattern)) {
            $pattern = $pattern ? 'true' : 'false';
        } elseif (! \is_string($pattern)) {
            $pattern = self::normalizeMixedToString($pattern);
        }

        // Handle array values by converting to JSON string
        if (\is_array($value)) {
            $encodedValue = \json_encode($value);
            if (! \is_string($encodedValue)) {
                return false;
            }

            $value = $encodedValue;
        } elseif (! \is_string($value)) {
            $value = self::normalizeMixedToString($value);
        }

        // Simple wildcard matching - convert pattern to regex
        // Escape regex special characters except for * which we want to treat as wildcard
        $regexPattern = preg_quote(
            $pattern,
            '/'
        );
        // Replace the escaped * with .* for wildcard matching
        $regexPattern = \str_replace(
            '\*',
            '.*',
            $regexPattern
        );
        $regex = "/^{$regexPattern}$/";

        return \preg_match(
            $regex,
            $value
        ) === 1;
    }

    /**
     * Check if a nested attribute value matches a pattern configuration.
     *
     * This method handles complex nested attribute matching for the
     * 'statamic_other_field_value_based_on_nested_attribute_value' functionality.
     *
     * @param  mixed  $attrValue  The attribute value to check (can be array or scalar)
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration containing:
     *                                                   - 'nested_attribute': The pattern to match against
     *                                                   - 'numeric_value_equivalent': Whether to treat numeric strings as equivalent
     *                                                   to numbers
     *                                                   - 'case_sensitive_key': Whether key matching should be case-sensitive
     *                                                   - 'case_sensitive_value': Whether value matching should be case-sensitive
     * @return bool True if the attribute value matches the pattern
     */
    protected static function matchesNestedAttributePattern(
        mixed $attrValue,
        array $mappingConfig
    ): bool {
        $nestedAttributeRaw = self::mappingValue(
            $mappingConfig,
            'nested_attribute'
        ) ?? [];
        $nestedAttribute = \is_string($nestedAttributeRaw)
            ? $nestedAttributeRaw
            : self::asArray($nestedAttributeRaw);
        $numericValueEquivalentRaw = self::mappingValue(
            $mappingConfig,
            'numeric_value_equivalent'
        );
        $numericValueEquivalent = \is_bool($numericValueEquivalentRaw)
            ? $numericValueEquivalentRaw
            : true;
        $caseSensitiveKeyRaw = self::mappingValue(
            $mappingConfig,
            'case_sensitive_key'
        );
        $caseSensitiveKey = \is_bool($caseSensitiveKeyRaw) && $caseSensitiveKeyRaw;
        $caseSensitiveValueRaw = self::mappingValue(
            $mappingConfig,
            'case_sensitive_value'
        );
        $caseSensitiveValue = \is_bool($caseSensitiveValueRaw) && $caseSensitiveValueRaw;

        // If nested_attribute is empty, no match
        if ($nestedAttribute === '') {
            return false;
        }

        // Handle case where nested_attribute contains key/value pairs
        if (\is_array($nestedAttribute)
        && self::isAssociativeArray($nestedAttribute)) {
            // If attrValue is also associative array, check if all key/value pairs match
            if (\is_array($attrValue)
            && self::isAssociativeArray($attrValue)) {
                return self::matchesAssociativeArrays(
                    $attrValue,
                    $nestedAttribute,
                    $numericValueEquivalent,
                    $caseSensitiveKey,
                    $caseSensitiveValue
                );
            }

            // If attrValue is also associative array, check if all key/value pairs match
            if (\is_array($attrValue) && ! self::isAssociativeArray($attrValue)) {
                // If attrValue is array of arrays, check if each array contains the key/value pairs
                return self::matchesArrayOfArrays(
                    $attrValue,
                    $nestedAttribute,
                    $numericValueEquivalent,
                    $caseSensitiveKey,
                    $caseSensitiveValue
                );
            }

            // If attrValue is scalar, no match
            return false;
        }

        // Handle case where nested_attribute contains key/value pairs
        if (\is_array($nestedAttribute)) {
            // Handle case where nested_attribute is array of arrays
            // If attrValue is also array of arrays, check positional matching
            if (
                \is_array($attrValue)
                && ! self::isAssociativeArray($attrValue)
            ) {
                return self::matchesArrayOfArraysPositional(
                    $attrValue,
                    $nestedAttribute,
                    $numericValueEquivalent,
                    $caseSensitiveKey,
                    $caseSensitiveValue
                );
            }

            // If attrValue is associative array or scalar, no match
            return false;
        }

        return false;
    }

    /**
     * Check if an array is associative (its keys are not 0, 1, 2 ... in order).
     *
     * An empty array is a list, not an associative array, so an empty
     * `nested_attribute` pattern cannot take the key/value branch of
     * matchesNestedAttributePattern() and match every array vacuously.
     *
     * @param  array<int|string, mixed>  $array  The array to check
     * @return bool True if the array is associative
     */
    protected static function isAssociativeArray(
        array $array
    ): bool {
        return ! \array_is_list($array);
    }

    /**
     * Check if two associative arrays match based on the specified criteria.
     *
     * @param  array<int|string, mixed>  $attrValue  The attribute value (associative array)
     * @param  array<int|string, mixed>  $nestedAttribute  The nested attribute pattern (associative array)
     * @param  bool  $numericValueEquivalent  Whether to treat numeric strings as equivalent to numbers
     * @param  bool  $caseSensitiveKey  Whether key matching should be case-sensitive
     * @param  bool  $caseSensitiveValue  Whether value matching should be case-sensitive
     * @return bool True if the arrays match
     */
    protected static function matchesAssociativeArrays(
        array $attrValue,
        array $nestedAttribute,
        bool $numericValueEquivalent,
        bool $caseSensitiveKey,
        bool $caseSensitiveValue
    ): bool {
        foreach ($nestedAttribute as $key => $value) {
            $attrKey = $caseSensitiveKey
                ? (string) $key
                : \strtolower((string) $key);
            $found = false;

            foreach ($attrValue as $attrK => $attrV) {
                $attrKNormalized = $caseSensitiveKey
                    ? (string) $attrK
                    : \strtolower((string) $attrK);
                if ($attrKNormalized === $attrKey) {
                    if (
                        self::valuesMatch(
                            $attrV,
                            $value,
                            $numericValueEquivalent,
                            $caseSensitiveValue
                        )
                    ) {
                        $found = true;
                        break;
                    }
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an array of arrays contains the specified key/value pairs.
     *
     * @param  array<int|string, mixed>  $attrValue  The attribute value (array of arrays)
     * @param  array<int|string, mixed>  $nestedAttribute  The nested attribute pattern (associative array)
     * @param  bool  $numericValueEquivalent  Whether to treat numeric strings as equivalent to numbers
     * @param  bool  $caseSensitiveKey  Whether key matching should be case-sensitive
     * @param  bool  $caseSensitiveValue  Whether value matching should be case-sensitive
     * @return bool True if the arrays match
     */
    protected static function matchesArrayOfArrays(
        array $attrValue,
        array $nestedAttribute,
        bool $numericValueEquivalent,
        bool $caseSensitiveKey,
        bool $caseSensitiveValue
    ): bool {
        foreach ($nestedAttribute as $key => $value) {
            $attrKey = $caseSensitiveKey
                ? (string) $key
                : \strtolower((string) $key);
            $found = false;

            foreach ($attrValue as $arrayItem) {
                if (\is_array($arrayItem)) {
                    foreach ($arrayItem as $itemKey => $itemValue) {
                        $itemKeyNormalized = $caseSensitiveKey
                            ? (string) $itemKey
                            : \strtolower((string) $itemKey);
                        if ($itemKeyNormalized === $attrKey) {
                            if (
                                self::valuesMatch(
                                    $itemValue,
                                    $value,
                                    $numericValueEquivalent,
                                    $caseSensitiveValue
                                )
                            ) {
                                $found = true;
                                break 2;
                            }
                        }
                    }
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if two arrays of arrays match positionally.
     *
     * @param  array<int|string, mixed>  $attrValue  The attribute value (array of arrays)
     * @param  array<int|string, mixed>  $nestedAttribute  The nested attribute pattern (array of arrays)
     * @param  bool  $numericValueEquivalent  Whether to treat numeric strings as equivalent to numbers
     * @param  bool  $caseSensitiveKey  Whether key matching should be case-sensitive
     * @param  bool  $caseSensitiveValue  Whether value matching should be case-sensitive
     * @return bool True if the arrays match
     */
    protected static function matchesArrayOfArraysPositional(
        array $attrValue,
        array $nestedAttribute,
        bool $numericValueEquivalent,
        bool $caseSensitiveKey,
        bool $caseSensitiveValue
    ): bool {
        // Must have the same number of arrays
        if (\count($attrValue) !== \count($nestedAttribute)) {
            return false;
        }

        foreach ($nestedAttribute as $index => $patternArray) {
            if (
                ! \is_array($patternArray)
                || ! isset($attrValue[$index])
                || ! \is_array($attrValue[$index])
            ) {
                return false;
            }

            foreach ($patternArray as $key => $value) {
                // Cast keys as the other matchers do, so an item that is a
                // plain list (integer keys) fails to match instead of throwing
                $attrKey = $caseSensitiveKey
                    ? (string) $key
                    : \strtolower((string) $key);
                $found = false;

                foreach ($attrValue[$index] as $itemKey => $itemValue) {
                    $itemKeyNormalized = $caseSensitiveKey
                        ? (string) $itemKey
                        : \strtolower((string) $itemKey);
                    if ($itemKeyNormalized === $attrKey) {
                        if (
                            self::valuesMatch(
                                $itemValue,
                                $value,
                                $numericValueEquivalent,
                                $caseSensitiveValue
                            )
                        ) {
                            $found = true;
                            break;
                        }
                    }
                }

                if (! $found) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if two values match based on the specified criteria.
     *
     * @param  mixed  $attrValue  The attribute value
     * @param  mixed  $patternValue  The pattern value
     * @param  bool  $numericValueEquivalent  Whether to treat numeric strings as equivalent to numbers
     * @param  bool  $caseSensitiveValue  Whether value matching should be case-sensitive
     * @return bool True if the values match
     */
    protected static function valuesMatch(
        mixed $attrValue,
        mixed $patternValue,
        bool $numericValueEquivalent,
        bool $caseSensitiveValue
    ): bool {
        // Handle numeric equivalence
        if ($numericValueEquivalent) {
            // Convert both values to numbers if they are numeric strings
            $attrNumeric = \is_numeric($attrValue) ? (float) $attrValue : $attrValue;
            $patternNumeric = \is_numeric($patternValue) ? (float) $patternValue : $patternValue;

            if (
                \is_numeric($attrValue)
                && \is_numeric($patternValue)
            ) {
                return $attrNumeric === $patternNumeric;
            }
        }

        // Handle case sensitivity for strings
        if (
            \is_string($attrValue)
            && \is_string($patternValue)
        ) {
            return $caseSensitiveValue ? $attrValue === $patternValue : \strtolower($attrValue) === \strtolower($patternValue);
        }

        // Default comparison
        return $attrValue === $patternValue;
    }

    /**
     * Resolve wildcards in a target attribute based on the matched value and pattern.
     *
     * @param  string  $targetAttribute  The target attribute with potential wildcards
     * @param  string  $matchedValue  The value that matched the pattern
     * @param  string  $pattern  The original pattern that was matched
     * @return string The resolved target attribute
     */
    protected static function resolveWildcards(
        string $targetAttribute,
        string $matchedValue,
        string $pattern
    ): string {
        // If the pattern contains a wildcard, extract the matched part
        if (
            \str_contains(
                $pattern,
                '*'
            )
        ) {
            // Convert pattern to regex using the same logic as matchesPattern
            $regexPattern = preg_quote(
                $pattern,
                '/'
            );
            $regexPattern = \str_replace(
                '\*',
                '(.*)',
                $regexPattern
            );
            $regex = '/^'.$regexPattern.'$/';

            // Extract the captured group
            if (
                \preg_match(
                    $regex,
                    $matchedValue,
                    $matches
                ) === 1
            ) {
                $wildcardValue = $matches[1];

                // Replace * in target attribute with the captured value
                return \str_replace(
                    '*',
                    $wildcardValue,
                    $targetAttribute
                );
            }
        }

        return $targetAttribute;
    }

    /**
     * Check if the current fieldtype should be excluded based on configuration.
     *
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'exclude_fieldtypes' key
     * @param  ?string  $excludeFieldtype  The fieldtype to check
     * @return bool True if the fieldtype should be excluded, false otherwise
     */
    protected static function shouldExcludeFieldtype(
        mixed $config,
        $excludeFieldtype
    ): bool {
        if (
            ! isset($config['exclude_fieldtypes'])
            || ! \is_array($config['exclude_fieldtypes'])
        ) {
            return false;
        }

        // Get the current Statamic fieldtype (if available)
        $currentFieldtype = $excludeFieldtype; // For now, use the source fieldtype

        // Return true if the current fieldtype is in the exclude list
        return \in_array($currentFieldtype, $config['exclude_fieldtypes'], true);
    }
}
