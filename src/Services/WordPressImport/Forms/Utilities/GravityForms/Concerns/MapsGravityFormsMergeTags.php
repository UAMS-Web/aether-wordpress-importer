<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Maps merge tags in Gravity Forms notification and confirmation values: the today, quiz
 * and survey formats, current-user and entry-author tags, field and subfield references,
 * and the variable mappings.
 *
 * Split out of {@see FormConfigurationManager} (#551) so that a pull request changing
 * one part of the class creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of that class: they read its constants
 * and call its other methods, and a subclass can still override the protected ones.
 */
trait MapsGravityFormsMergeTags
{
    /**
     * Process complex today format mappings that require regex patterns.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    private function processTodayFormatMappings(
        string $value
    ): string {
        // Handle {today:format:format_string} patterns
        $processedValue = \preg_replace(
            '/\{today:format:([^}]+)\}/',
            '{{ today format="$1" }}',
            $value
        );

        return $processedValue ?? $value;
    }

    /**
     * Process complex quiz format mappings that require regex patterns.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    private function processQuizFormatMappings(
        string $value
    ): string {
        // Handle {quiz:id=handle} patterns
        $processedValue = \preg_replace(
            '/\{quiz:id=([^}]+)\}/',
            '{{ quiz handle="$1" }}',
            $value
        );

        $value = $processedValue ?? $value;

        // Handle {quiz_score:id=handle} patterns
        $processedValue = \preg_replace(
            '/\{quiz_score:id=([^}]+)\}/',
            '{{ quiz:score handle="$1" }}',
            $value
        );

        return $processedValue ?? $value;
    }

    /**
     * Process complex survey format mappings that require regex patterns.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    private function processSurveyFormatMappings(
        string $value
    ): string {
        // Handle {score:id=handle} patterns
        $processedValue = \preg_replace(
            '/\{score:id=([^}]+)\}/',
            '{{ survey:score handle="$1" }}',
            $value
        );

        return $processedValue ?? $value;
    }

    /**
     * Process complex format mappings that require regex patterns.
     *
     * This method handles all the complex format mappings specific to Gravity Forms,
     * including today format, quiz format, and survey format mappings.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    protected function processComplexFormatMappings(
        string $value
    ): string {
        // Handle {today:format:format_string} patterns
        $value = $this->processTodayFormatMappings($value);

        // Handle {quiz:id=handle} and {quiz_score:id=handle} patterns
        $value = $this->processQuizFormatMappings($value);

        // Handle {score:id=handle} patterns
        $value = $this->processSurveyFormatMappings($value);

        return $value;
    }

    /**
     * Strip current-user and entry-author merge tags from every string in a
     * notification.
     *
     * @param  array<int|string, mixed>  $notification  The Gravity Forms notification
     * @return array<int|string, mixed> The notification with the tags removed
     */
    protected function neutralizeCurrentUserMergeTags(
        array $notification
    ): array {
        foreach ($notification as $key => $value) {
            if (\is_string($value)) {
                $notification[$key] = $this->processCurrentUserMergeTags($value);

                continue;
            }

            // `routing` carries per-rule recipients as nested arrays.
            if (\is_array($value)) {
                $notification[$key] = $this->neutralizeCurrentUserMergeTags($value);
            }
        }

        return $notification;
    }

    /**
     * Neutralize Gravity Forms merge tags that resolve to a WordPress user.
     *
     * Two families, same problem at different scope:
     *
     *   - `{user:display_name}` / `{user:user_email}` resolve to the logged-in
     *     WordPress user. wordpress-importer-archive#444
     *   - `{created_by:<property>}` resolves to the WordPress user who created the
     *     entry. wordpress-importer-archive#497
     *
     * The target Statamic app has no front-end authentication, so there is no user
     * to resolve either family against, and the source sites are not uniformly
     * login-gated — Gravity Forms already resolved these to empty for any anonymous
     * submitter and fell back to the site-default From. Left alone they would ship
     * as a literal token in the imported notification config: neither family is in
     * `getVariableMappings()`, and `created_by` is on the system-merge-tag list the
     * field-merge-tag path deliberately does not translate.
     *
     * They are removed rather than blanking the whole value, because the corpus
     * carries them embedded in longer strings (a `from` of
     * `{Name (First):1.3}oedassistance@uams.edu{user:display_name}`, a subject of
     * `Formulary Request ({date_dmy}_{user:display_name})`). A value that held
     * nothing else collapses to empty, which falls back to `MAIL_FROM`.
     *
     * The `{user:…}` alternative stays pinned to the two observed properties, so
     * widening for `{created_by:…}` cannot change wordpress-importer-archive#444's behavior. The
     * `{created_by:…}` alternative takes the whole property family rather than the
     * two corpus occurrences: every WordPress user property is equally
     * unresolvable here, and the class is bounded — it cannot span a `}`, so it
     * can never swallow a neighbouring tag.
     *
     * @param  string  $value  The value to process
     * @return string The value with any user-resolving merge tags removed
     */
    protected function processCurrentUserMergeTags(
        string $value
    ): string {
        $pattern = '/\{user:(?:display_name|user_email)\}|\{created_by:[A-Za-z0-9_]+\}/';

        // Find every tag. A value with none passes through the strip loop below unchanged.
        $matches = [];
        \preg_match_all(
            $pattern,
            $value,
            $matches
        );

        // Warn once per occurrence so the import report accounts for every tag that
        // was dropped rather than translated.
        foreach ($matches[0] as $mergeTag) {
            // Name the right absent user for the family that matched
            $subject = \str_starts_with($mergeTag, '{created_by:')
                ? 'Entry-author'
                : 'Current-user';

            ImportWarningManager::addWarning(
                'forms',
                'neutralized_merge_tags',
                [
                    'merge_tag' => $mergeTag,
                    'reason' => $subject.' merge tags have no Statamic equivalent; '
                        .'the value falls back to the site-default From.',
                ]
            );
        }

        // Strip repeatedly until the value stops changing. A single pass lets an
        // overlapping tag reassemble into a live one — removing the inner tag from
        // `{user:dis{user:user_email}play_name}` yields `{user:display_name}`, which
        // would then ship untouched. wordpress-importer-archive#444
        while (\preg_match($pattern, $value) === 1) {
            $stripped = \preg_replace(
                $pattern,
                '',
                $value
            ) ?? $value;

            // A strip that changed nothing could only be a failed one; stop rather than loop
            if ($stripped === $value) {
                break;
            }

            $value = $stripped;
        }

        return $value;
    }

    /**
     * Process Gravity Forms Field Merge Tags and convert them to Statamic submission
     * field tags.
     *
     * Handles patterns like:
     *   - {Something:1} -> {{ submission:field_handle }}
     *   - {:1} -> {{ submission:field_handle }}
     *   - {Something:1:} -> {{ submission:field_handle }}
     *   - {:1:} -> {{ submission:field_handle }}
     *
     * @param  string  $value  The value to process
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return string The processed value with field merge tags converted
     */
    protected function processFieldMergeTags(
        string $value,
        array $formFields
    ): string {

        // Pattern to match any merge tag with braces
        $pattern = '/\{([^}]+)\}/';

        $result = preg_replace_callback(
            $pattern,
            function ($matches) use ($formFields): string {
                $fullMatch = $matches[0];
                $content = $matches[1];

                // Parse the content to find the field ID (which is always a number)
                $parts = \explode(
                    ':',
                    $content
                );

                // If there's only one part, it's not a field merge tag
                if (\count($parts) < 2) {
                    return $fullMatch;
                }

                // Find the field ID by looking for a number (integer or decimal)
                $fieldId = null;
                $descriptorParts = [];

                foreach ($parts as $part) {
                    // Check if this part is a number (field ID)
                    if (
                        \preg_match(
                            '/^\d+(?:\.\d+)?$/',
                            $part
                        ) === 1
                    ) {
                        $fieldId = $part;
                        break;
                    } else { // Otherwise
                        // This part is part of the descriptor
                        $descriptorParts[] = $part;
                    }
                }

                // If no field ID found, this is not a field merge tag
                if ($fieldId === null) {
                    return $fullMatch;
                }

                // Join the descriptor parts
                $descriptor = \implode(
                    ':',
                    $descriptorParts
                );

                // Skip if this is a system merge tag
                // A descriptor is text and the system tags are words, so a loose comparison
                // matches exactly what this strict one does.
                // @pest-mutate-ignore: TrueToFalse
                if (
                    \in_array($descriptor, self::SYSTEM_MERGE_TAGS, true)
                ) {
                    return $fullMatch; // Return original unchanged
                }

                // Find the field handle for this field ID
                $fieldHandle = $this->getFieldHandleById(
                    $fieldId,
                    $formFields
                );

                if ($fieldHandle !== null) {
                    return "{{ submission:{$fieldHandle} }}";
                }

                // If field handle not found, return original unchanged
                return $fullMatch;
            },
            $value
        );

        // Clean up variables to free up memory
        unset(
            $formFields,
            $pattern,
        );

        return \is_string($result) ? $result : $value;
    }

    /**
     * Get the field name/label from a field array (Gravity Forms specific).
     *
     * @param  array<int|string, mixed>  $field  The field array
     * @return string The field name/label
     */
    protected function getFieldNameFromField(
        array $field
    ): string {
        // Gravity Forms uses 'label' property for field names
        if (isset($field['label']) && \is_scalar($field['label'])) {
            return (string) $field['label'];
        }

        return '';
    }

    /**
     * Get the subfield handle for a given subfield ID from the form fields array.
     *
     * @param  string  $subfieldId  The subfield ID (e.g., "67.1")
     * @param  array<int|string, mixed>  $formFields  Array of form fields
     * @return ?string The subfield handle or null if not found
     */
    protected function getSubfieldHandleById(
        string $subfieldId,
        array $formFields
    ): ?string {
        // Parse the subfield ID to get parent field ID and subfield number
        $parts = \explode(
            '.',
            $subfieldId
        );
        if (\count($parts) !== 2) {
            return null;
        }

        $parentFieldId = $parts[0];

        // Find the parent field
        foreach ($formFields as $field) {
            if (! \is_array($field)) {
                continue;
            }

            if (! isset($field['id']) || ! \is_scalar($field['id']) || (string) $field['id'] !== $parentFieldId) {
                continue;
            }

            // A subfield is named after a labelled, typed parent that lists its inputs
            if (! isset($field['label']) || ! \is_scalar($field['label'])) {
                continue;
            }

            if (! isset($field['type']) || ! \is_scalar($field['type'])) {
                continue;
            }

            if (! isset($field['inputs']) || ! \is_array($field['inputs'])) {
                continue;
            }

            // Get the parent field handle
            $parentHandle = $this->convertLabelToHandle(
                (string) $field['label'],
                $parentFieldId
            );

            // Find the subfield in the inputs array
            foreach ($field['inputs'] as $input) {
                if (! \is_array($input)) {
                    continue;
                }

                if (! isset($input['id']) || ! \is_scalar($input['id']) || (string) $input['id'] !== $subfieldId) {
                    continue;
                }

                // An input with no label has no mapped subfield
                if (! isset($input['label']) || ! \is_scalar($input['label'])) {
                    continue;
                }

                // Get the subfield handle from the mapping
                $subfieldHandle = $this->getSubfieldHandleFromMapping(
                    (string) $field['type'],
                    (string) $input['label'],
                    FieldtypeMapping::getSubfieldMapping()
                );

                if ($subfieldHandle !== null) {
                    // Return the combined handle: parent.subfield
                    return $parentHandle.'.'.$subfieldHandle;
                }
            }
        }

        // Clean up variables to free up memory
        unset(
            $subfieldId,
            $formFields,
            $parts,
            $parentFieldId,
            $field,
            $parentHandle,
            $input,
            $subfieldHandle,
        );

        return null;
    }

    /**
     * Get the subfield handle from the mapping based on field type and subfield label.
     *
     * @param  string  $fieldType  The Gravity Forms field type
     * @param  string  $subfieldLabel  The subfield label
     * @param  array<int|string, mixed>  $subfieldMapping  The subfield mapping configuration
     * @return ?string The subfield handle or null if not found
     */
    protected function getSubfieldHandleFromMapping(
        string $fieldType,
        string $subfieldLabel,
        array $subfieldMapping
    ): ?string {
        // Walk to the subfield's handle by exact keys: the mapping is keyed by field type,
        // then by subfield label, and a label is read as written
        $value = $subfieldMapping;
        foreach (['fieldtypes', $fieldType, 'subfields', $subfieldLabel, 'handle'] as $key) {
            if (! \is_array($value) || ! isset($value[$key])) {
                return null;
            }

            $value = $value[$key];
        }

        return \is_string($value) ? $value : null;
    }

    /**
     * Get Gravity Forms specific variable mappings for merge tag processing.
     *
     * @return array<string, string> Array mapping Gravity Forms variables to Statamic variables
     */
    protected function getVariableMappings(): array
    {
        return [
            '{admin_email}' => '{{ admin_email }}',
            '{all_fields:empty}' => '{{ all_fields show_empty="true" }}',
            '{all_fields:nohidden}' => '{{ all_fields hide_hidden="true" }}',
            '{all_fields:value}' => '{{ all_fields show_value="true" }}',
            '{all_fields}' => '{{ all_fields }}',
            '{date_mdy}' => '{{ today format="m/d/Y" }}',
            '{entry_id}' => '{{ submission:id }}',
            '{form_id}' => '{{ form:handle }}',
            '{form_title}' => '{{ form:title }}',
            '{ip}' => '{{ ip }}',
            '{quiz_grade}' => '{{ quiz:grade }}',
            '{quiz_passfail}' => '{{ quiz:passfail }}',
            '{quiz_percent}' => '{{ quiz:percent }}',
            '{quiz_score}' => '{{ quiz:score }}',
            '{quiz}' => '{{ quiz }}',
            '{referer}' => '{{ referrer }}',
            '{score}' => '{{ survey:score }}',
            '{today:timestamp}' => '{{ today:timestamp }}',
            '{today:time}' => '{{ today:time }}',
            '{today}' => '{{ today }}',
        ];
    }

    /**
     * Translate field references in string values to Statamic format.
     *
     * Gravity Forms writes a field reference as a field merge tag ({Something:67}), so it
     * is translated exactly as one, into a Statamic submission field tag. A bare number
     * such as {67} is not a field reference and is left alone.
     *
     * @param  string  $text  The text containing field references
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return string The text with field references translated to Statamic format
     */
    protected function translateFieldReferences(
        string $text,
        array $allFields = []
    ): string {
        // A field reference is written exactly as a field merge tag, so it is resolved the same way
        return $this->processFieldMergeTags(
            $text,
            $allFields
        );
    }
}
