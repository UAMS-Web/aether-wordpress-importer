<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\Concerns;

use Exception;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Formats mapped values: complex arrays and their placeholders, ISO 8601 dates, numeric
 * strings, slugs and snake case, exploded and trimmed strings, booleans, and options
 * decoded from JSON.
 *
 * Split out of {@see FieldtypeMappingTrait} (#556) so that a pull request changing
 * one part of the trait creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of the class that uses that trait: they
 * read its properties and call its other methods through self:: and static::.
 */
trait FormatsMappedValues
{
    /**
     * Transform a value to a complex array structure.
     *
     * @param  mixed  $value  The value to transform (string or array of strings)
     * @param  array<int|string, mixed>  $complexArrayConfig  The complex array configuration
     * @param  array<int|string, mixed>  $data  The data array for placeholder substitution
     * @param  int  $count  The current count/index
     * @return array<int|string, mixed> The transformed complex array
     */
    protected static function transformToComplexArray(
        mixed $value,
        array $complexArrayConfig,
        array $data,
        int $count
    ): array {
        // If value is null or empty, return empty array
        if ($value === null || $value === '') {
            return [];
        }

        // If value is a string, treat as single value and return single complex array
        if (\is_string($value)) {
            // Create a copy of the complex array config
            $complexArray = $complexArrayConfig;
            // Replace placeholders in the complex array structure
            $complexArray = self::replaceComplexArrayPlaceholders(
                $complexArray,
                $value,
                $data,
                $count
            );

            return $complexArray;
        }

        // If value is an array, process each element and return array of complex arrays
        if (\is_array($value)) {
            $result = [];
            $position = 0;
            foreach ($value as $stringValue) {
                // Count by position, so a string-keyed array numbers its
                // items the way a list does instead of adding to a key
                $position++;

                // Create a copy of the complex array config for this iteration
                $complexArray = $complexArrayConfig;

                // Replace placeholders in the complex array structure
                $complexArray = self::replaceComplexArrayPlaceholders(
                    $complexArray,
                    self::normalizeMixedToString($stringValue),
                    $data,
                    $position
                );

                $result[] = $complexArray;
            }

            return $result;
        }

        // Otherwise
        // Convert other types to string and treat as single value
        $complexArray = $complexArrayConfig;
        // Replace placeholders in the complex array structure
        $complexArray = self::replaceComplexArrayPlaceholders(
            $complexArray,
            self::normalizeMixedToString($value),
            $data,
            $count
        );

        return $complexArray;
    }

    /**
     * Replace placeholders in a complex array structure.
     *
     * @param  array<int|string, mixed>  $complexArray  The complex array structure
     * @param  string  $stringValue  The original string value
     * @param  array<int|string, mixed>  $data  The data array for context
     * @param  int  $count  The current count/index
     * @return array<int|string, mixed> The array with placeholders replaced
     */
    protected static function replaceComplexArrayPlaceholders(
        array $complexArray,
        string $stringValue,
        array $data,
        int $count
    ): array {
        $result = [];

        foreach ($complexArray as $key => $value) {
            // Replace placeholders in the key
            $newKey = self::replaceComplexArrayPlaceholder(
                $key,
                $stringValue,
                $data,
                $count
            );

            // Replace placeholders in the value
            if (\is_array($value)) {
                $newValue = self::replaceComplexArrayPlaceholders(
                    $value,
                    $stringValue,
                    $data,
                    $count
                );
            } else { // Otherwise
                $newValue = self::replaceComplexArrayPlaceholder(
                    $value,
                    $stringValue,
                    $data,
                    $count
                );
            }

            $result[self::normalizeArrayKeyForAssignment($newKey)] = $newValue;
        }

        return $result;
    }

    /**
     * Replace placeholders in a single value.
     *
     * @param  mixed  $value  The value to process
     * @param  string  $stringValue  The original string value
     * @param  array<int|string, mixed>  $data  The data array for context
     * @param  int  $count  The current count/index
     * @return mixed The value with placeholders replaced
     */
    protected static function replaceComplexArrayPlaceholder(
        mixed $value,
        string $stringValue,
        array $data,
        int $count
    ): mixed {
        if (! \is_string($value)) {
            return $value;
        }

        // [#value] is replaced last, so text in the imported value that looks
        // like a placeholder is kept as written instead of being expanded

        // Replace [#count] with the current count
        $value = \str_replace(
            '[#count]',
            (string) $count,
            $value
        );

        // Replace [#field_handle] with the field handle
        if (
            \str_contains(
                $value,
                '[#field_handle]'
            )
        ) {
            $fieldName = static::getFieldNameFromField($data);
            $fieldId = self::normalizeMixedToString($data['id'] ?? '');
            $fieldHandle = static::convertFieldNameToHandle(
                self::normalizeMixedToString($fieldName),
                $fieldId
            );
            $value = \str_replace(
                '[#field_handle]',
                $fieldHandle,
                $value
            );
        }

        // Replace [#value] with the original string value
        return \str_replace(
            '[#value]',
            $stringValue,
            $value
        );
    }

    /**
     * Get a nested attribute value from the data array.
     *
     * @param  array<int|string, mixed>  $data  The data array
     * @param  string  $path  The dot-separated path to the attribute
     * @return mixed The attribute value
     */
    protected static function getNestedAttributeValue(
        array $data,
        string $path
    ): mixed {
        // If path doesn't contain dots, it's a direct attribute
        if (
            ! str_contains(
                $path,
                '.'
            )
        ) {
            return $data[$path] ?? null;
        }

        // Handle dot-separated paths
        $parts = \explode(
            '.',
            $path
        );
        $current = $data;

        foreach ($parts as $part) {
            if (
                \is_array($current)
                && self::arrayKeyExists(
                    $part,
                    $current
                )
            ) {
                $current = $current[$part];
            } else { // Otherwise
                return null;
            }
        }

        return $current;
    }

    /**
     * Transform a value to ISO 8601 date format.
     *
     * This method handles both string and array values, converting date strings
     * in the formats convertDateStringToIso8601() reads (MM/DD/YYYY, YYYY/MM/DD,
     * MM-DD-YYYY, etc.) to ISO 8601 format (YYYY-MM-DD). An array item that is
     * not a string, int, float or bool becomes an empty string.
     *
     * @param  mixed  $value  The value to transform (string or array of strings)
     * @return mixed The transformed value in ISO 8601 format
     */
    protected static function transformToIso8601Date(
        $value
    ): mixed {
        // Handle null values
        if ($value === null) {
            return null;
        }

        // Handle array values
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                $result[] = self::convertDateStringToIso8601(
                    self::normalizeMixedToString($item)
                );
            }

            return $result;
        }

        // Handle string values
        if (\is_string($value)) {
            return self::convertDateStringToIso8601($value);
        }

        // Return unchanged for other types
        return $value;
    }

    /**
     * Convert a single date string to ISO 8601 format.
     *
     * Supports these date formats:
     *   - MM/DD/YYYY, YYYY/MM/DD
     *   - MM-DD-YYYY, YYYY-MM-DD
     *   - MM\/DD\/YYYY, YYYY\/MM\/DD (with escaped slashes)
     *
     * A date with the year last is always read month first, as Gravity Forms
     * stores it, so a day-first date such as 25/12/2024 comes back as
     * 2024-25-12. The numbers are not checked against the calendar. A string
     * in any other format is returned trimmed but otherwise unchanged.
     *
     * @param  string  $dateString  The date string to convert
     * @return string The date in ISO 8601 format (YYYY-MM-DD)
     */
    protected static function convertDateStringToIso8601(
        string $dateString
    ): string {
        // Trim whitespace
        $dateString = \trim($dateString);

        // Handle empty strings
        if ($dateString === '') {
            return $dateString;
        }

        // Define patterns to match various date formats
        $patterns = [
            // MM/DD/YYYY pattern
            '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/' => 'month_day_year',
            // YYYY/MM/DD pattern
            '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/' => 'year_month_day',
            // MM-DD-YYYY pattern
            '/^(\d{1,2})-(\d{1,2})-(\d{4})$/' => 'month_day_year',
            // YYYY-MM-DD pattern
            '/^(\d{4})-(\d{1,2})-(\d{1,2})$/' => 'year_month_day',
            // MM\/DD\/YYYY pattern (escaped slashes)
            '/^(\d{1,2})\\\\\/(\d{1,2})\\\\\/(\d{4})$/' => 'month_day_year',
            // YYYY\/MM\/DD pattern (escaped slashes)
            '/^(\d{4})\\\\\/(\d{1,2})\\\\\/(\d{1,2})$/' => 'year_month_day',
        ];

        foreach ($patterns as $pattern => $format) {
            if (
                \preg_match(
                    $pattern,
                    $dateString,
                    $matches
                ) === 1
            ) {
                switch ($format) {
                    case 'month_day_year':
                        $month = str_pad(
                            $matches[1],
                            2,
                            '0',
                            STR_PAD_LEFT
                        );
                        $day = str_pad(
                            $matches[2],
                            2,
                            '0',
                            STR_PAD_LEFT
                        );
                        $year = $matches[3];

                        return "{$year}-{$month}-{$day}";

                    case 'year_month_day':
                        $year = $matches[1];
                        $month = str_pad(
                            $matches[2],
                            2,
                            '0',
                            STR_PAD_LEFT
                        );
                        $day = str_pad(
                            $matches[3],
                            2,
                            '0',
                            STR_PAD_LEFT
                        );

                        return "{$year}-{$month}-{$day}";
                }
            }
        }

        // If no pattern matches, return the trimmed string
        return $dateString;
    }

    /**
     * Transform numeric values to numeric strings.
     *
     * This method converts numeric values (integers and floats) to their string
     * representations while leaving other value types unchanged. For arrays,
     * it recursively processes each element.
     *
     * Examples:
     *   - 1 → '1'
     *   - 1.23 → '1.23'
     *   - [1, 2, 3] → ['1', '2', '3']
     *   - [1.23, 4.56] → ['1.23', '4.56']
     *   - [1, '2', 3.45, '5.67', true, false, 'foo'] → ['1', '2', '3.45', '5.67', true, false, 'foo']
     *
     * @param  mixed  $value  The value to transform
     * @return mixed The transformed value with numeric values converted to strings
     */
    protected static function transformToNumericString(
        $value
    ): mixed {
        // Handle null values
        if ($value === null) {
            return null;
        }

        // Handle array values
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                $result[] = self::transformToNumericString($item);
            }

            return $result;
        }

        // Handle integer values
        if (\is_int($value)) {
            return (string) $value;
        }

        // Handle float values (but not strings that represent floats)
        if (\is_float($value)) {
            return (string) $value;
        }

        // Return unchanged for other types (strings, booleans, etc.)
        return $value;
    }

    /**
     * Sluggify a value by converting to lowercase and replacing non-alphanumeric
     * characters with hyphens.
     *
     * @param  mixed  $value  The value to sluggify
     * @return string The sluggified value
     */
    protected static function sluggifyValue(
        $value
    ): string {
        if ($value === null) {
            return '';
        }

        $string = self::normalizeMixedToString($value);

        // Convert to lowercase
        $string = \strtolower($string);

        // Replace non-alphanumeric characters with hyphens
        $string = \preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $string
        );
        if (! \is_string($string)) {
            $string = '';
        }

        // Remove leading and trailing hyphens
        $string = \trim(
            $string,
            '-'
        );

        return $string;
    }

    /**
     * Convert a value to snake_case by converting to lowercase and replacing
     * non-alphanumeric characters with underscores.
     *
     * @param  mixed  $value  The value to convert to snake_case
     * @return string The snake_case value
     */
    protected static function snakeCaseValue(
        $value
    ): string {
        if ($value === null) {
            return '';
        }

        $string = self::normalizeMixedToString($value);

        // Convert to lowercase
        $string = \strtolower($string);

        // Replace non-alphanumeric characters with underscores
        $string = \preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $string
        );
        if (! \is_string($string)) {
            $string = '';
        }

        // Remove leading and trailing underscores
        $string = \trim(
            $string,
            '_'
        );

        return $string;
    }

    /**
     * Transform Formidable Forms options JSON to Statamic array format.
     *
     * @param  string  $optionsJson  The options JSON string
     * @param  array<int|string, mixed>  $fieldData  The Formidable Forms field data
     * @param  array<int|string, mixed>  $config  The field configuration
     * @param  ?string  $fieldType  The field type to determine if other_label should be extracted
     * @return array<int|string, mixed> The transformed options array
     */
    protected static function transformOptionsJsonToArray(
        string $optionsJson,
        array $fieldData,
        array $config,
        ?string $fieldType = null
    ): array {
        $options = \json_decode(
            $optionsJson,
            true
        );
        if (! \is_array($options)) {
            return [];
        }

        // Check if separate_value is enabled
        $separateValue = false;

        // First check if the config specifies a separate_value_field_option
        if (
            self::arrayKeyExists(
                'separate_value_field_option',
                $config
            )
        ) {
            $separateValueFieldOption = self::normalizeArrayKeyForAssignment(
                self::mappingValue(
                    $config,
                    'separate_value_field_option'
                )
            );
            if (
                self::arrayKeyExists(
                    $separateValueFieldOption,
                    $fieldData
                )
            ) {
                $separateValue = (bool) self::mappingValue(
                    $fieldData,
                    $separateValueFieldOption
                );
            }
        }

        // Fallback to Formidable Forms specific field_options.separate_value
        if (
            ! $separateValue
            && isset($fieldData['field_options'])
            && \is_string($fieldData['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $fieldData['field_options'],
                true
            );
            if (
                \is_array($fieldOptions)
                && isset($fieldOptions['separate_value'])
            ) {
                $separateValue = (bool) $fieldOptions['separate_value'];
            }
        }

        $transformedOptions = [];
        $otherLabel = null;

        foreach ($options as $key => $option) {
            // Check if this is an other_* key
            if (
                \is_string($key)
                && str_starts_with($key, 'other_')
            ) {
                // Extract the other label from the value
                $otherLabel = \is_string($option) ? $option : null;

                continue; // Skip processing this as a regular option
            }

            if (
                ! \is_array($option)
                || ! isset($option['label'])
            ) {
                continue;
            }

            // Skip empty options (empty label and empty value)
            if (
                $option['label'] === ''
                && $option['value'] === ''
            ) {
                continue;
            }

            // Apply key translation if available
            if (
                isset($config['key_translation'])
                && \is_array($config['key_translation'])
            ) {
                $transformedOption = [];
                foreach (self::mappingConfig($config['key_translation']) as $sourceKey => $targetKey) {
                    $normalizedSourceKey = self::normalizeArrayKey($sourceKey);
                    $normalizedTargetKey = self::normalizeArrayKey($targetKey);
                    if (
                        $normalizedSourceKey === null
                        || $normalizedTargetKey === null
                    ) {
                        continue; // Skip excluded fields
                    }

                    if (
                        self::arrayKeyExists(
                            $normalizedSourceKey,
                            $option
                        )
                    ) {
                        $transformedOption[$normalizedTargetKey] = self::mappingValue(
                            $option,
                            $normalizedSourceKey
                        );
                    }
                }
            } else { // Otherwise
                // Fallback to manual transformation, in the label-key,
                // value-value shape a key translation produces, so the
                // separate_value swap below orients both alike
                $transformedOption = [
                    'key' => $option['label'],
                    'value' => $option['value'] ?? null,
                ];
            }

            // Handle separate_value logic after key translation
            if (
                $separateValue
                && isset($transformedOption['key'])
                && isset($transformedOption['value'])
            ) {
                // Swap key and value when separate_value is enabled
                $tempKey = $transformedOption['key'];
                $transformedOption['key'] = $transformedOption['value'];
                $transformedOption['value'] = $tempKey;
            } elseif (! $separateValue && isset($transformedOption['key'])) {
                // Set value to null when separate_value is disabled
                $transformedOption['value'] = null;
            }

            $transformedOptions[] = $transformedOption;
        }

        // Ensure all options have the expected structure based on fieldtype
        if (
            $transformedOptions !== []
            && isset($config['expected_keys'])
        ) {
            self::normalizeOptionsStructure(
                $transformedOptions,
                [],
                null,
                $fieldType
            );
        }

        // Store the other_label in a static property for later access
        // Only extract other_label for field types that support it (radio and checkboxes)
        if (
            $otherLabel !== null
            && \in_array(
                $fieldType,
                [
                    'radio',
                    'checkbox',
                ],
                true
            )
        ) {
            static::$extractedOtherLabel = \trim($otherLabel);
        }

        return $transformedOptions;
    }

    /**
     * Convert a value to boolean.
     *
     * @param  mixed  $value  The value to convert
     * @return bool The boolean value
     */
    protected static function convertToBoolean(
        $value
    ) {
        if (\is_string($value)) {
            return filter_var(
                $value,
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (\is_numeric($value)) {
            return (bool) $value;
        }

        if (\is_array($value)) {
            return $value !== [];
        }

        if (\is_object($value)) {
            return true;
        }

        if (\is_null($value)) {
            return false;
        }

        if (\is_bool($value)) {
            return $value;
        }

        // Otherwise
        throw new Exception('Unsupported value type for boolean conversion: '.gettype($value));
    }

    /**
     * Explode a string into an array using a delimiter.
     *
     * @param  mixed  $value  The string to explode, an array to return as-is, or any other value (returns '')
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'explode_string' key
     * @return string|array<int|string, mixed> The exploded list, the original string, or the array unchanged
     */
    protected static function explodeString(
        mixed $value,
        array $config
    ): string|array {
        // An array value is already structured (e.g. Gravity Forms `choices`
        // mapped to blueprint `options`, or a decoded list) and has nothing to
        // split; pass it through unchanged. Only a string is a candidate for
        // explode_string splitting. Returning '' here previously discarded every
        // array-valued attribute — options, list `fields`, date `days_of_week`,
        // etc. — before it reached the blueprint. See wordpress-importer-archive#463.
        if (\is_array($value)) {
            return $value;
        }

        if (! \is_string($value)) {
            return '';
        }

        // Check if explode_string configuration exists and is valid
        if (
            ! isset($config['explode_string'])
            || ! \is_string($config['explode_string'])
            || $config['explode_string'] === ''
        ) {
            return $value;
        }

        return \explode(
            $config['explode_string'],
            $value
        );
    }

    /**
     * Trim whitespace from a string or array of strings.
     *
     * @param  mixed  $value  The value to trim
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'trim' key
     * @return mixed The trimmed value
     */
    protected static function trimValue(
        mixed $value,
        array $config
    ): mixed {
        // Check if trim configuration exists and is enabled
        if (
            ! isset($config['trim'])
            || $config['trim'] !== true

        ) {
            return $value;
        }

        if (\is_array($value)) {
            // If value is an array (from explode_string), trim each element
            return \array_map(
                fn (mixed $item): string => \is_string($item)
                    ? \trim($item)
                    : self::normalizeMixedToString($item),
                $value
            );
        }

        return \is_string($value) ? \trim($value) : $value;
    }

    /**
     * Trim specific characters from the beginning and end of a string or array of
     * strings.
     *
     * @param  string|array<int|string, mixed>|null  $value  The string or array of strings to trim
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'trim_characters' key
     * @return string|array<int|string, mixed>|null The trimmed string or array of trimmed strings
     */
    protected static function trimCharacters(
        string|array|null $value,
        array $config
    ): mixed {
        // Check if trim_characters configuration exists
        if (! isset($config['trim_characters'])) {
            return $value;
        }

        $characters = self::asArray(
            self::mappingValue(
                $config,
                'trim_characters'
            )
        );
        $characterStrings = \array_values(
            \array_filter(
                \array_map(
                    self::stringValue(...),
                    $characters
                ),
                fn (?string $character): bool => $character !== null
            )
        );

        // Validate input parameters
        if (
            $value === null
            || $characterStrings === []
            || $value === []
        ) {
            return $value;
        }

        if (\is_array($value)) {
            // If value is an array (from explode_string), trim each element
            return \array_map(
                fn (mixed $item): mixed => (
                    \is_string($item)
                    || \is_array($item)
                    || $item === null
                )
                    ? static::trimCharacters(
                        $item,
                        $config
                    )
                    : self::normalizeMixedToString($item),
                $value
            );
        }

        // First trim whitespace, then trim the specific characters
        $value = \trim($value);
        // Create a character mask for \trim() by joining all characters
        $characterMask = \implode(
            '',
            $characterStrings
        );

        // Use \trim() with the character mask to remove characters from both ends
        return \trim(
            $value,
            $characterMask
        );
    }
}
