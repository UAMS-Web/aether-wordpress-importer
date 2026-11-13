<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\Concerns;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Maps Gravity Forms notifications to Statamic email configurations, with their routing
 * rules, the skipped Save and Continue notifications and the unhonored schedules.
 *
 * Split out of {@see FormConfigurationManager} (#551) so that a pull request changing
 * one part of the class creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of that class: they read its constants
 * and call its other methods, and a subclass can still override the protected ones.
 */
trait MapsGravityFormsNotifications
{
    /**
     * Convert Gravity Forms routing rules to Statamic format.
     *
     * @param  array<int|string, mixed>  $routingRules  Array of Gravity Forms routing rules
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return array<int|string, mixed> Array of Statamic routing rules
     */
    protected function convertRoutingRulesToStatamicFormat(
        array $routingRules,
        array $formFields
    ): array {
        $statamicRoutingRules = [];

        foreach ($routingRules as $rule) {
            if (! \is_array($rule)) {
                continue;
            }

            // Extract field ID and convert to field handle; a rule without one is looked up
            // as an empty ID, which only a field with an empty ID matches
            $fieldId = isset($rule['fieldId']) && \is_scalar($rule['fieldId'])
                ? (string) $rule['fieldId']
                : '';
            $fieldHandle = $this->getFieldHandleById(
                $fieldId,
                $formFields
            );

            // Skip this rule if field handle not found
            if ($fieldHandle === null) {
                continue;
            }

            // Convert operator to Statamic format
            $operator = $this->convertRoutingOperatorToStatamicFormat(
                isset($rule['operator']) && \is_scalar($rule['operator'])
                    ? (string) $rule['operator']
                    : ''
            );

            // Create Statamic routing rule
            $statamicRule = [
                'field' => $fieldHandle,
                'operator' => $operator,
                'value' => isset($rule['value']) && \is_scalar($rule['value'])
                    ? (string) $rule['value']
                    : '',
                'email' => isset($rule['email']) && \is_scalar($rule['email'])
                    ? (string) $rule['email']
                    : '',
            ];

            $statamicRoutingRules[] = $statamicRule;
        }

        // Clean up variables to free up memory
        unset(
            $routingRules,
            $formFields,
            $rule,
            $fieldId,
            $fieldHandle,
            $operator,
            $statamicRule,
        );

        return $statamicRoutingRules;
    }

    /**
     * Convert Gravity Forms routing operator to Statamic format.
     *
     * @param  string  $operator  The routing operator
     * @return string The Statamic operator
     */
    protected function convertRoutingOperatorToStatamicFormat(
        string $operator
    ): string {
        // Map Gravity Forms operators to Statamic operators
        $operatorMapping = [
            'is' => 'equals',
            'isnot' => 'not',
            '>' => 'greater_than',
            '<' => 'less_than',
        ];

        // Return mapped operator or original if not found
        $result = $operatorMapping[$operator] ?? $operator;

        // Clean up variables to free up memory
        unset(
            $operator,
            $operatorMapping,
        );

        return $result;
    }

    /**
     * Check if the form has notifications (Gravity Forms specific).
     *
     * @param  array<int|string, mixed>  $formData  The Gravity Forms form data
     * @return bool True if the form has notifications, false otherwise
     */
    protected function hasNotifications(
        array $formData
    ): bool {
        return isset($formData['notifications']) && \is_array($formData['notifications']) && $formData['notifications'] !== [];
    }

    /**
     * Determine whether a notification is a Gravity Forms Save & Continue email.
     *
     * Gravity Forms fires these from the `form_save_email_requested` event, which
     * has no Statamic analog — verified: `save_and_continue`, `save_link`,
     * `resume_link` and partial-submission mechanisms return zero hits across the
     * host app. wordpress-importer-archive#33
     *
     * @param  array<int|string, mixed>  $notification  The Gravity Forms notification
     * @return bool True when the notification belongs to the Save & Continue flow
     */
    protected function isSaveAndContinueNotification(
        array $notification
    ): bool {
        return isset($notification['event'])
            && \is_string($notification['event'])
            && $notification['event'] === self::NOTIFICATION_EVENT_SAVE_AND_CONTINUE;
    }

    /**
     * Warn that a Save & Continue notification was skipped rather than imported.
     *
     * Importing one produces a live but broken email action — no recipient
     * (Gravity Forms resolves it at runtime from the address the visitor types
     * into the saved-link request, so there is no field to copy) and a literal
     * `{save_link}` token in the body that nothing substitutes. A recipient-less
     * email action reads as real to anyone inspecting the form YAML, so writing
     * nothing is the safer outcome. wordpress-importer-archive#33
     *
     * @param  array<int|string, mixed>  $notification  The skipped notification
     * @param  int|float|string|bool  $formId  The Gravity Forms form id
     * @param  string  $notificationName  The notification's display name
     */
    protected function warnSkippedSaveAndContinueNotification(
        array $notification,
        int|float|string|bool $formId,
        string $notificationName
    ): void {
        // Identify the notification precisely so the migrating team can find it
        $notificationId = isset($notification['id']) && \is_scalar($notification['id'])
            ? (string) $notification['id']
            : '?';

        ImportWarningManager::addWarning(
            'forms',
            'skipped_notifications',
            [
                'plugin' => 'Gravity Forms',
                'form_id' => (string) $formId,
                'notification_id' => $notificationId,
                'notification_name' => $notificationName,
                'event' => self::NOTIFICATION_EVENT_SAVE_AND_CONTINUE,
                'reason' => 'Statamic has no Save & Continue analog; importing this '
                    .'notification would create an email action with no recipient and an '
                    .'unresolvable {save_link} token, so it was skipped.',
            ]
        );
    }

    /**
     * Warn when a Notification Scheduler delay cannot be honored on import.
     *
     * The Notification Scheduler add-on's `schedule*` fields have no Statamic
     * analog and are mapped to null (see
     * FORM_CONFIGURATION_ACTION_MAPPING_EMAIL). Every one of the 12
     * scheduler-bearing notifications in the export corpus is `immediate`, so
     * nothing is lost there — but the corpus is a sample of what the importer
     * will see, not a bound on it. A future export carrying a delayed or
     * recurring notification would otherwise collapse to an immediate send with
     * no signal anywhere, so anything other than `immediate` is reported. wordpress-importer-archive#35
     *
     * @param  array<int|string, mixed>  $notification  The Gravity Forms notification
     * @param  int|float|string|bool  $formId  The Gravity Forms form id
     * @param  string  $notificationName  The notification's display name
     */
    protected function warnUnhonoredNotificationSchedule(
        array $notification,
        int|float|string|bool $formId,
        string $notificationName
    ): void {
        // An absent scheduleType is the add-on not being in use at all
        if (
            ! isset($notification['scheduleType'])
            || ! \is_string($notification['scheduleType'])
        ) {
            return;
        }

        $scheduleType = \trim($notification['scheduleType']);

        // An empty or immediate schedule already matches what the import produces
        if (
            $scheduleType === ''
            || $scheduleType === self::NOTIFICATION_SCHEDULE_TYPE_IMMEDIATE
        ) {
            return;
        }

        // Identify the notification precisely so the migrating team can find it
        $notificationId = isset($notification['id']) && \is_scalar($notification['id'])
            ? (string) $notification['id']
            : '?';

        ImportWarningManager::addWarning(
            'forms',
            'unhonored_notification_schedule',
            [
                'plugin' => 'Gravity Forms',
                'form_id' => (string) $formId,
                'notification_id' => $notificationId,
                'notification_name' => $notificationName,
                'schedule_type' => $scheduleType,
                'reason' => 'The Notification Scheduler add-on has no Statamic analog; '
                    .'this notification will send immediately instead of on its '
                    .'configured schedule.',
            ]
        );
    }

    /**
     * Map Gravity Forms notifications to Statamic email configurations.
     *
     * Supports conditional mapping with 'based_on_other_attribute_value' structure
     * for complex scenarios where the target field depends on other field values.
     *
     * @param  array<int|string, mixed>  $formData  The Gravity Forms form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return array<int|string, mixed> Array of Statamic email configurations
     */
    protected function mapNotificationsToEmailConfigurations(
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): array {
        $notifications = isset($formData['notifications']) && \is_array($formData['notifications'])
            ? $formData['notifications']
            : [];
        $formFields = isset($formData['fields']) && \is_array($formData['fields'])
            ? $formData['fields']
            : [];
        $emailConfigurations = [];

        // Process each notification and map it to Statamic format
        foreach ($notifications as $notification) {
            if (! \is_array($notification)) {
                continue;
            }

            $formIdForContext = isset($formData['id']) && \is_scalar($formData['id'])
                ? $formData['id']
                : '?';
            $sourceNameForContext = isset($notification['name']) && \is_scalar($notification['name'])
                ? (string) $notification['name']
                : 'Unnamed';

            // Drop Save & Continue notifications rather than importing a dead one. wordpress-importer-archive#33
            if ($this->isSaveAndContinueNotification($notification)) {
                $this->warnSkippedSaveAndContinueNotification(
                    $notification,
                    $formIdForContext,
                    $sourceNameForContext
                );

                continue;
            }

            // Record any Notification Scheduler delay the import cannot honor. wordpress-importer-archive#35
            $this->warnUnhonoredNotificationSchedule(
                $notification,
                $formIdForContext,
                $sourceNameForContext
            );

            // Set form config context so log messages identify this notification
            $this->setFormConfigContextForLogging([
                'form_id' => $formIdForContext,
                'source' => 'notification',
                'source_name' => $sourceNameForContext,
                'plugin' => 'Gravity Forms',
            ]);

            // Wrap processing in try/finally so form config context is always cleared
            try {
                // Neutralize current-user merge tags before anything maps the values.
                //
                // This has to happen ahead of buildEmailConfiguration(), because that
                // applies `value_translation` first and only strips merge tags
                // afterwards. A `fromName` of exactly `{user:display_name}` — which is
                // every one of the 17 corpus `fromName` occurrences — would otherwise
                // reach `value_translation` still holding the tag, miss the
                // `#empty => '{{ site:name }}'` branch declared for precisely this
                // case, and persist as an empty From label. Stripping first makes it
                // indistinguishable from an empty `fromName`, which is the intent. wordpress-importer-archive#444
                $notification = $this->neutralizeCurrentUserMergeTags($notification);

                // Build the base email configuration using common logic
                $emailConfig = $this->buildEmailConfiguration(
                    $notification,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // ---
                // Map conditional logic if present (Gravity Forms specific)
                // ---

                // Map conditional logic when present
                if (
                    isset($notification['conditionalLogic'])
                    && \is_array($notification['conditionalLogic'])
                ) {
                    // Convert conditional logic to Statamic format
                    $conditionalLogic = FieldtypeMapping::mapConditionalLogic(
                        $notification['conditionalLogic'],
                        $formFields
                    );

                    // ---
                    // Store conditional logic under 'conditional_logic' key if not empty
                    // ---

                    // Store conditional logic when not empty
                    if ($conditionalLogic !== []) {
                        // Add conditional logic to email configuration
                        $emailConfig['conditional_logic'] = $conditionalLogic;
                    }
                }

                // Add the mapped configuration to the array
                $emailConfigurations[] = $emailConfig;
            } finally {
                // Clear form config context so logs do not attribute later processing
                $this->clearFormConfigContextForLogging();
            }
        }

        // Clean up variables to free up memory
        unset(
            $notifications,
            $formFields,
            $formData,
            $siteHandle,
            $assetImporter,
            $notification,
            $emailConfig,
            $conditionalLogic,
        );

        // Return mapped email configurations
        return $emailConfigurations;
    }
}
