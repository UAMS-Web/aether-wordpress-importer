<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\Concerns;

use DateTime;
use Illuminate\Support\Facades\Log;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Maps Gravity Forms plugin-specific field values, chiefly the date and time parts of a
 * field read from the form data.
 *
 * Split out of {@see FormConfigurationManager} (#551) so that a pull request changing
 * one part of the class creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of that class: they read its constants
 * and call its other methods, and a subclass can still override the protected ones.
 */
trait MapsGravityFormsDateTimeFields
{
    /**
     * Process datetime fields for Gravity Forms schedule form start and end times.
     *
     * Combines date, hour, minute, and am/pm fields into a single datetime string.
     * The method handles the complex mapping where multiple source fields map to
     * a single target field.
     *
     * @param  string  $statamicKey  The Statamic field handle (schedule_form.start_datetime or
     *                               schedule_form.end_datetime)
     * @param  mixed  $value  The original value (this will be one of the component fields)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @return ?string The formatted datetime string or null if incomplete
     */
    protected function processDateTimeField(
        string $statamicKey,
        $value,
        array $formData
    ): ?string {
        // Determine if this is start or end datetime
        $prefix = $statamicKey === 'schedule_form.start_datetime' ? 'scheduleStart' : 'scheduleEnd';

        // Read the date, hour, minute and meridiem from the form data
        $parts = $this->readDateTimeParts(
            $formData,
            [
                $prefix,
                $prefix.'Hour',
                $prefix.'Minute',
                $prefix.'Ampm',
            ]
        );
        if ($parts === null) {
            return null;
        }

        // Combine them, warning about a date that cannot be read (assuming a format like "08/19/2025")
        $formattedDateTime = $this->formatDateTimeParts($parts);
        if ($formattedDateTime === null) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'prefix' => $prefix,
                    'date' => $parts[0],
                ]
            );

            // Log warning for invalid date format
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "Invalid date format for {$prefix}: {$parts[0]}",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        return $formattedDateTime;
    }

    /**
     * Process Gravity Forms specific field types that require special handling.
     *
     * @param  string  $statamicKey  The Statamic field handle
     * @param  mixed  $value  The original value
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return ?mixed The processed value or null if no special processing was needed
     */
    protected function processPluginSpecificField(
        string $statamicKey,
        $value,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): mixed {
        // Handle datetime fields for schedule form (Gravity Forms specific)
        if (
            $statamicKey === 'schedule_form.start_datetime'
            || $statamicKey === 'schedule_form.end_datetime'
        ) {
            return $this->processDateTimeField(
                $statamicKey,
                $value,
                $formData
            );
        }

        // Return null if no special processing was needed
        return null;
    }

    /**
     * Process datetime combination fields and return the combined datetime value.
     *
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration with datetime_combination
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @return ?string The combined datetime string or null if incomplete
     */
    protected function processDatetimeCombination(
        array $mappingConfig,
        array $formData
    ): ?string {
        // Read the date, hour, minute and meridiem from the form fields the mapping names
        $parts = $this->readDateTimeParts(
            $formData,
            [
                data_get($mappingConfig, 'datetime_combination.date_field'),
                data_get($mappingConfig, 'datetime_combination.hour_field'),
                data_get($mappingConfig, 'datetime_combination.minute_field'),
                data_get($mappingConfig, 'datetime_combination.ampm_field'),
            ]
        );
        if ($parts === null) {
            return null;
        }

        // Accept a date written with escaped slashes ("09\/03\/2025") as well as plain ones
        $parts[0] = \str_replace(
            '\\/',
            '/',
            $parts[0]
        );

        return $this->formatDateTimeParts($parts);
    }

    /**
     * Read a datetime's date, hour, minute and meridiem from the form data.
     *
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  array{0: mixed, 1: mixed, 2: mixed, 3: mixed}  $fields  The keys of the date, hour, minute and meridiem
     * @return ?array{0: string, 1: string, 2: string, 3: string} The four parts, or null when one is missing, blank or not a scalar
     */
    private function readDateTimeParts(
        array $formData,
        array $fields
    ): ?array {
        $date = $this->readDateTimePart($formData, $fields[0]);
        $hour = $this->readDateTimePart($formData, $fields[1]);
        $minute = $this->readDateTimePart($formData, $fields[2]);
        $ampm = $this->readDateTimePart($formData, $fields[3]);

        // A part that is missing means there is no datetime to combine
        if ($date === null || $hour === null || $minute === null || $ampm === null) {
            return null;
        }

        return [
            $date,
            $hour,
            $minute,
            $ampm,
        ];
    }

    /**
     * Read one part of a datetime from the form data.
     *
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  mixed  $field  The part's key; any scalar other than empty text names a form field
     * @return ?string The part, or null when it is missing, blank or not a scalar
     */
    private function readDateTimePart(
        array $formData,
        mixed $field
    ): ?string {
        $part = \is_scalar($field) && (string) $field !== ''
            ? ($formData[(string) $field] ?? null)
            : null;

        if (! \is_scalar($part) || (string) $part === '') {
            return null;
        }

        return (string) $part;
    }

    /**
     * Combine a date and a 12-hour time into a 24-hour datetime.
     *
     * @param  array{0: string, 1: string, 2: string, 3: string}  $parts  The date (m/d/Y), hour, minute and meridiem
     * @return ?string The datetime as "Y-m-d H:i", or null when the date cannot be read
     */
    private function formatDateTimeParts(
        array $parts
    ): ?string {
        [$date, $hour, $minute, $ampm] = $parts;

        // Convert 12-hour format to 24-hour format
        $hour24 = (int) $hour;
        if (\strtolower($ampm) === 'pm' && $hour24 !== 12) {
            $hour24 += 12;
        } elseif (\strtolower($ampm) === 'am' && $hour24 === 12) {
            $hour24 = 0;
        }

        // Parse the date
        $dateObj = DateTime::createFromFormat(
            'm/d/Y',
            $date
        );
        if ($dateObj === false) {
            return null;
        }

        // Set the time; the format below shows no seconds
        $dateObj->setTime(
            $hour24,
            (int) $minute
        );

        return $dateObj->format('Y-m-d H:i');
    }
}
