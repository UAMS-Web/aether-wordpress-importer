<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use DateTime;
use Statamic\Forms\Form;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormConfigurationManagerTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\Concerns\MapsGravityFormsConfirmations;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\Concerns\MapsGravityFormsDateTimeFields;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\Concerns\MapsGravityFormsMergeTags;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\Concerns\MapsGravityFormsNotifications;

/**
 * Manages form configuration mapping from Gravity Forms to Statamic.
 *
 * This class is responsible for mapping Gravity Forms form configuration
 * data (notifications, etc.) to Statamic form settings.
 *
 * Supports complex conditional mapping with 'based_on_other_attribute_value'
 * structure for scenarios where the target Statamic field depends on the value of
 * other fields. This enables dynamic field mapping based on conditional logic,
 * similar to Formidable Forms but adapted for Gravity Forms' data structure.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * form configuration mapping operations.
 *
 * ---------------------------------------------------------------------------
 * Notification import audit (2026-06-02, issue wordpress-importer-archive#25)
 * ---------------------------------------------------------------------------
 * Audited against the 150-file Gravity Forms export corpus
 * (`GravityForms/` in `UAMS-Web/wordpress-importer-exports`, 1,503 forms,
 * 2,221 notifications).
 *
 * Coverage:
 *   - 17 of 18 canonical notification fields are mapped (`id`, `name`,
 *     `isActive`, `to`, `toEmail`, `toType`, `toField`, `cc`, `bcc`, `from`,
 *     `fromName`, `replyTo`, `subject`, `message`, `enableAttachments`,
 *     `routing`, `conditionalLogic`).
 *   - 3 fields are intentionally dropped: `service` (always `"wordpress"`),
 *     `event` (no Statamic analog -- see Skipped below, which is how the one
 *     event that carried meaning is handled), and `disableAutoformat` (no
 *     Statamic analog).
 *   - 6,961 of 7,045 merge-tag occurrences (98.8%) resolve cleanly via
 *     `getVariableMappings()`, `processFieldMergeTags()`, and the today/
 *     quiz/survey format mappers.
 *   - Legacy `*Field` notification aliases (`fromField`, `fromNameField`,
 *     `replyToField`) map to `from` / `from_name` / `reply_to` as
 *     `{{ submission:<handle> }}` merge tags via `field_id_to_submission_tag`,
 *     preferring the field reference over the literal (issue wordpress-importer-archive#34). The 44
 *     notifications that populate From Name via `fromNameField` now import the
 *     applicant's field value instead of the `{{ site:name }}` default.
 *
 * Skipped (recognized, deliberately not imported):
 *   - Save & Continue notifications (`event=form_save_email_requested`,
 *     `toType=hidden`, `{save_link}` token): 96 notifications in the corpus.
 *     Statamic has no Save & Continue analog, so an imported action would send
 *     to nobody (the recipient is resolved at GF runtime from the address the
 *     visitor types into the saved-link request — there is no field to copy)
 *     and would carry a literal, unresolvable `{save_link}` in its body. A
 *     recipient-less email action is worse than no email action, so the whole
 *     notification is skipped and warned about instead (issue wordpress-importer-archive#33).
 *
 * Dropped with a signal (recognized, no analog, reported when it matters):
 *   - Notification Scheduler add-on fields (`schedule*`, 12 notifications on
 *     one form): explicit `null` entries in the mapping array. All 12 corpus
 *     occurrences are `scheduleType="immediate"`, which is what the import
 *     already produces; any other `scheduleType` is warned about rather than
 *     silently collapsing to an immediate send (issue wordpress-importer-archive#35).
 *
 * Neutralized (recognized, deliberately resolved to nothing):
 *   - Current-user merge tags `{user:display_name}` / `{user:user_email}`:
 *     41 occurrences across 11 export files (`fromName` 17, `from` 13,
 *     `replyTo` 5, `bcc` 3, `subject` 2, `message` 1). They resolve to the
 *     logged-in WordPress user, and the target app has no front-end
 *     authentication, so `processCurrentUserMergeTags()` strips them and warns.
 *     A value that held nothing else collapses to empty and falls back to the
 *     site-default From. Previously they fell through unresolved and shipped as
 *     a literal token (issue wordpress-importer-archive#444).
 *   - Entry-author merge tags `{created_by:…}`: 2 occurrences in the corpus,
 *     both whole-value (`from` = `{created_by:user_email}`, `fromName` =
 *     `{created_by:display_name}`). They resolve to the WordPress user who
 *     created the entry — the same absent-user problem as `{user:…}`, at a
 *     different scope — and match neither `getVariableMappings()` nor the
 *     field-merge-tag path, which lists `created_by` as a system tag it must
 *     not translate. The same `processCurrentUserMergeTags()` pattern strips
 *     the whole `{created_by:<property>}` family and warns (issue wordpress-importer-archive#497).
 *
 * Benign/no-op fields present in the corpus but not mapped (no real data
 * loss):
 *   - `type` (always `"admin"`, 167 occurrences) -- pre-1.7 admin/user
 *     notification distinction with no Statamic analog.
 *   - `isDefault` (63 occurrences) -- marks the default notification; no
 *     behavioral impact.
 *   - `notification_conditional_logic` / `notification_conditional_logic_object`
 *     (1,055 occurrences) -- legacy pre-2.5 conditional logic storage,
 *     always disabled (`"0"`) or empty in the corpus.
 *
 * Full audit artifacts: see issue UAMS-Web/wordpress-importer-archive#25.
 * ---------------------------------------------------------------------------
 */
class FormConfigurationManager
{
    /**
     * Use shared functionality for form configuration management.
     */
    use FormConfigurationManagerTrait;

    /**
     * Map confirmations.
     */
    use MapsGravityFormsConfirmations;

    /**
     * Map plugin-specific and date and time field values.
     */
    use MapsGravityFormsDateTimeFields;

    /**
     * Translate merge tags and field references.
     */
    use MapsGravityFormsMergeTags;

    /**
     * Map notifications and their routing.
     */
    use MapsGravityFormsNotifications;

    /**
     * Gravity Forms notification event fired for a Save & Continue link email.
     *
     * These notifications are skipped on import — Statamic has no Save & Continue
     * analog, so the imported action would have no recipient and an unresolvable
     * `{save_link}` token. wordpress-importer-archive#33
     */
    protected const string NOTIFICATION_EVENT_SAVE_AND_CONTINUE = 'form_save_email_requested';

    /**
     * Notification Scheduler `scheduleType` value meaning "send immediately".
     *
     * This is the add-on's default and the only behavior the import can reproduce,
     * so it is the one value that needs no warning. wordpress-importer-archive#35
     */
    protected const string NOTIFICATION_SCHEDULE_TYPE_IMMEDIATE = 'immediate';

    /**
     * Gravity Forms system merge tags, which are never read as a field merge tag even when
     * they carry a number, such as `{entry_id:5}`.
     *
     * @var list<string>
     */
    protected const array SYSTEM_MERGE_TAGS = [
        'activation_url',
        'admin_email',
        'all_fields',
        'all_quiz_results',
        'apc_media',
        'created_by',
        'current_page_number',
        'custom_field',
        'date_created',
        'date_updated',
        'embed_post',
        'embed_url',
        'entry_id',
        'entry_url',
        'entry',
        'form_id',
        'form_title',
        'ip',
        'payment_action',
        'payment_date',
        'post_edit_url',
        'post_id',
        'pricing_fields',
        'quiz_grade',
        'quiz_passfail',
        'quiz_percent',
        'quiz_score',
        'quiz',
        'referer',
        'save_email_input',
        'score',
        'source_page_number',
        'square_receipt_url',
        'survey_total_score',
        'today',
        'user_agent',
        'user',
    ];

    /**
     * Mapping from Gravity Forms form configuration fields to Statamic form fields.
     *
     * Key: Gravity Forms form configuration field name (supports dot notation
     * for nested objects)
     * Value: Statamic form field name (null to exclude from mapping)
     *
     * Note: Email configuration fields (notifications) are mapped separately
     * using the FORM_CONFIGURATION_ACTION_MAPPING_EMAIL array.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_MAPPING = [
        'description' => [
            'statamic_attribute' => 'description',
            'strip_html' => true,
        ],
        'title' => [
            'statamic_attribute' => 'heading',
        ],
        'button.text' => [
            'statamic_attribute' => 'submit',
        ],
        'limitEntries' => [
            'statamic_attribute' => 'limit_entries',
            'value_translation' => [
                'true' => true,
                'false' => null,
            ],
        ],
        'limitEntriesCount' => [
            'statamic_attribute' => 'limit_entries_count',
        ],
        'limitEntriesPeriod' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'limitEntries' => [
                        'true' => 'limit_entries_period',
                        'false' => null,
                    ],
                ],
            ],
            'value_translation' => [
                '#empty' => 'total',
                'day' => 'day',
                'week' => 'week',
                'month' => 'month',
                'year' => 'year',
            ],
        ],
        'limitEntriesMessage' => [
            'statamic_attribute' => 'limit_entries_message',
        ],
        'scheduleForm' => [
            'statamic_attribute' => 'schedule_form_query',
            'value_translation' => [
                'true' => true,
                'false' => null,
            ],
        ],
        'scheduleStart' => [
            'statamic_attribute' => 'schedule_form.start_datetime',
            'datetime_combination' => [
                'date_field' => 'scheduleStart',
                'hour_field' => 'scheduleStartHour',
                'minute_field' => 'scheduleStartMinute',
                'ampm_field' => 'scheduleStartAmpm',
            ],
        ],
        'scheduleStartHour' => [
            'statamic_attribute' => null, // Handled by scheduleStart datetime_combination
        ],
        'scheduleStartMinute' => [
            'statamic_attribute' => null, // Handled by scheduleStart datetime_combination
        ],
        'scheduleStartAmpm' => [
            'statamic_attribute' => null, // Handled by scheduleStart datetime_combination
        ],
        'scheduleEnd' => [
            'statamic_attribute' => 'schedule_form.end_datetime',
            'datetime_combination' => [
                'date_field' => 'scheduleEnd',
                'hour_field' => 'scheduleEndHour',
                'minute_field' => 'scheduleEndMinute',
                'ampm_field' => 'scheduleEndAmpm',
            ],
        ],
        'scheduleEndHour' => [
            'statamic_attribute' => null, // Handled by scheduleEnd datetime_combination
        ],
        'scheduleEndMinute' => [
            'statamic_attribute' => null, // Handled by scheduleEnd datetime_combination
        ],
        'scheduleEndAmpm' => [
            'statamic_attribute' => null, // Handled by scheduleEnd datetime_combination
        ],
        'schedulePendingMessage' => [
            'statamic_attribute' => 'schedule_pending_message',
        ],
        'scheduleMessage' => [
            'statamic_attribute' => 'schedule_expired_message',
        ],
        'requireLogin' => [
            'statamic_attribute' => 'require_login_query',
            'value_translation' => [
                'true' => true,
                'false' => null,
            ],
        ],
        'requireLoginMessage' => [
            'statamic_attribute' => 'require_login_message',
        ],

        // ---
        // Quiz grading, stored by the Gravity Forms Quiz add-on under a form-level
        // `gravityformsquiz` settings object. The Statamic targets are shipped
        // (`App\Services\Forms\QuizScorer` reads them), so the mapping is a plain
        // passthrough — no `#empty` translation, which keeps a form without any
        // `gravityformsquiz` settings from writing the keys at all. wordpress-importer-archive#420
        // ---
        'gravityformsquiz.grading' => [
            'statamic_attribute' => 'quiz_grading',
        ],
        'gravityformsquiz.passPercent' => [
            // Gravity Forms stores this as a numeric string; the Statamic field is an
            // integer.
            'statamic_attribute' => 'quiz_pass_percent',
            'as_integer' => true,
        ],
        'gravityformsquiz.grades' => [
            // Rows are already `{ text, value }`, matching the Statamic grid. Gravity
            // Forms keeps them even when grading is `passfail`; the Statamic side gates
            // the field on `letter` and strips it otherwise.
            'statamic_attribute' => 'quiz_grades',
        ],
    ];

    /**
     * Mapping from Gravity Forms confirmation fields to Statamic confirmation fields.
     *
     * Key: Gravity Forms confirmation field name
     * Value: Statamic confirmation field name (null to exclude from mapping)
     *
     * Note: This mapping is used for both default and additional confirmations.
     * The actual target path is determined by the confirmation type
     * (default vs additional).
     *
     * Supports conditional mapping with 'based_on_other_attribute_value' structure
     * for complex scenarios where the target field depends on other field values.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION = [
        'id' => [
            'statamic_attribute' => null, // Handled separately at top level for additional confirmations
        ],
        'name' => [
            'statamic_attribute' => null, // Handled separately at top level for additional confirmations
        ],
        'isDefault' => [
            'statamic_attribute' => null, // Handled separately at top level for additional confirmations
        ],
        'isActive' => [
            'statamic_attribute' => null, // Handled separately at top level for additional confirmations
        ],
        'type' => [
            'statamic_attribute' => 'confirmation_type',
            'value_translation' => [
                'message' => 'message',
                'redirect' => 'redirect',
                'page' => 'entry',
            ],
        ],
        'message' => [
            'statamic_attribute' => 'confirmation_message',
            'as_bard' => true,
            'translate_field_references' => true,
        ],
        'url' => [
            'statamic_attribute' => 'confirmation_redirect_url',
            'unescape_slashes' => true,
        ],
        'pageId' => [
            'statamic_attribute' => 'confirmation_page',
            'translate_page_references' => true,
        ],
        'queryString' => [
            'statamic_attribute' => 'confirmation_query_string',
            'translate_field_references' => true,
            'trim' => true,
        ],
        'disableAutoformat' => [
            'statamic_attribute' => null,
        ],
        'conditionalLogic' => [
            'statamic_attribute' => null, // Skip conditionalLogic as it's handled separately
        ],
    ];

    /**
     * Mapping from Gravity Forms notification fields to Statamic email fields.
     *
     * Key: Gravity Forms notification field name
     * Value: Statamic email field name (null to exclude from mapping)
     *
     * Supports conditional mapping with 'based_on_other_attribute_value' structure
     * for complex scenarios where the target field depends on other field values.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL = [
        'id' => [
            'statamic_attribute' => 'id',
        ],

        // Email Notification Name
        'name' => [
            'statamic_attribute' => 'name',
        ],

        // Send To Email
        // --------------------------------------------------------------------------------
        // The `toEmail` field is enabled in the Gravity Forms UI when the `toType` field
        // is set to `email`.
        //
        // The `toEmail` and `to` fields are the same value in the Gravity Forms JSON
        // structure.
        // --------------------------------------------------------------------------------
        'to' => [
            'statamic_attribute' => 'to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],
        'toEmail' => [
            'statamic_attribute' => 'to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // CC
        // --------------------------------------------------------------------------------
        // The `cc` field is in the JSON structure, but it is not visible in the Gravity
        // Forms UI.
        //
        // The `cc` field has no values in any of the active UAMS forms.
        // --------------------------------------------------------------------------------
        'cc' => [
            'statamic_attribute' => 'cc',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // BCC
        'bcc' => [
            'statamic_attribute' => 'bcc',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // From Email
        'from' => [
            'statamic_attribute' => 'from',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // From Name
        'fromName' => [
            'statamic_attribute' => 'from_name',
            'value_translation' => [
                '#empty' => '{{ site:name }}',
            ],
        ],

        // Reply To
        'replyTo' => [
            'statamic_attribute' => 'reply_to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // Subject
        'subject' => [
            'statamic_attribute' => 'subject',
            'translate_field_references' => true,
        ],

        // Attach uploaded fields to notification
        'enableAttachments' => [
            'statamic_attribute' => 'attachments',
            'value_translation' => [
                'true' => true,
                'false' => null,
            ],
        ],

        // Active
        'isActive' => [
            'statamic_attribute' => 'enabled',
        ],

        // Message
        'message' => [
            'statamic_attribute' => 'message',
            'as_bard' => true,
            'translate_field_references' => true,
        ],

        // Send To (type)
        'toType' => [
            'statamic_attribute' => 'to_type',
            'statamic_other_field_value' => [
                'cc_type' => 'email',
                'bcc_type' => 'email',
                'reply_to_type' => 'email',
            ],
        ],

        // Send To Field
        // --------------------------------------------------------------------------------
        // The `toField` field is enabled in the Gravity Forms UI when the `toType` field
        // is set to `field`.
        // --------------------------------------------------------------------------------
        'toField' => [
            'statamic_attribute' => 'to_field',
            'translate_field_references' => true,
        ],

        // Legacy `*Field` notification aliases (fromField, fromNameField, replyToField)
        // --------------------------------------------------------------------------------
        // Pre-1.7 aliases that populate From / From Name / Reply To from a form field
        // rather than a literal. Gravity Forms still emits them for backward
        // compatibility. Unlike `to`/`to_field`, Statamic's `from`/`from_name`/`reply_to`
        // have no dedicated field selector, so `field_id_to_submission_tag` resolves the
        // field ID to a handle and emits a `{{ submission:<handle> }}` merge tag into the
        // same attribute (see FormConfigurationManagerTrait::buildEmailConfiguration).
        //
        // These entries are placed after the literal `from`/`fromName`/`replyTo` entries
        // above so a set field reference overwrites the literal, matching the Gravity
        // Forms UI (issue wordpress-importer-archive#34).
        // --------------------------------------------------------------------------------
        'fromField' => [
            'statamic_attribute' => 'from',
            'field_id_to_submission_tag' => true,
        ],
        'fromNameField' => [
            'statamic_attribute' => 'from_name',
            'field_id_to_submission_tag' => true,
        ],
        'replyToField' => [
            'statamic_attribute' => 'reply_to',
            'field_id_to_submission_tag' => true,
        ],

        // Email Service
        // --------------------------------------------------------------------------------
        // The `service` field is hidden in the Gravity Forms UI.
        //
        // All instances of this field have the value "wordpress" in the active UAMS forms.
        // --------------------------------------------------------------------------------
        'service' => [
            'statamic_attribute' => null,
        ],

        // Event
        // --------------------------------------------------------------------------------
        // There is no analog to this in Statamic.
        //
        // Dropping the value is correct for `form_submission`, which is what a Statamic
        // email action already means. It is NOT correct for `form_save_email_requested`
        // (Save & Continue): that notification is skipped outright before it reaches this
        // mapping, because Statamic has no Save & Continue analog and the imported action
        // would carry no recipient and an unresolvable `{save_link}` token. See
        // isSaveAndContinueNotification() (issue wordpress-importer-archive#33).
        // --------------------------------------------------------------------------------
        'event' => [
            'statamic_attribute' => null,
        ],

        // Notification Scheduler add-on (issue wordpress-importer-archive#35)
        // --------------------------------------------------------------------------------
        // INTENTIONALLY DROPPED. The add-on schedules a notification for a delay, an
        // absolute date, or a field-derived time, optionally recurring. Statamic sends an
        // email action when the submission is made and has no analog for any of it.
        //
        // All 12 scheduler-bearing notifications in the export corpus are
        // `scheduleType="immediate"` — the add-on's default and exactly what the import
        // already produces — so nothing in the corpus loses behavior. Anything other than
        // `immediate` is reported by warnUnhonoredNotificationSchedule() rather than
        // collapsing to an immediate send silently.
        //
        // These entries are explicit so a future reader sees a decision rather than an
        // oversight.
        // --------------------------------------------------------------------------------
        'schedule' => [
            'statamic_attribute' => null,
        ],
        'scheduleType' => [
            'statamic_attribute' => null,
        ],
        'scheduleDelayOffset' => [
            'statamic_attribute' => null,
        ],
        'scheduleDelayOffsetUnit' => [
            'statamic_attribute' => null,
        ],
        'scheduleDate' => [
            'statamic_attribute' => null,
        ],
        'scheduleAmpm' => [
            'statamic_attribute' => null,
        ],
        'scheduleHour' => [
            'statamic_attribute' => null,
        ],
        'scheduleMinute' => [
            'statamic_attribute' => null,
        ],
        'scheduleEnableRecurring' => [
            'statamic_attribute' => null,
        ],
        'scheduleRecurringInterval' => [
            'statamic_attribute' => null,
        ],
        'scheduleRecurringEnding' => [
            'statamic_attribute' => null,
        ],
        'scheduleRecurringEndingValue' => [
            'statamic_attribute' => null,
        ],
        'scheduleRecurringEndingUnit' => [
            'statamic_attribute' => null,
        ],
        'scheduleFieldOffset' => [
            'statamic_attribute' => null,
        ],
        'scheduleFieldOffsetUnit' => [
            'statamic_attribute' => null,
        ],
        'scheduleField' => [
            'statamic_attribute' => null,
        ],
        'scheduleFieldAmpm' => [
            'statamic_attribute' => null,
        ],

        // From Name
        // --------------------------------------------------------------------------------
        // The `toField` field is enabled in the Gravity Forms UI when the `toType` field
        // is set to `routing`.
        // --------------------------------------------------------------------------------
        'routing' => [
            'statamic_attribute' => 'to_routing',
        ],

        // Conditional Logic
        // --------------------------------------------------------------------------------
        // Skip `conditionalLogic` as it's handled separately.
        // --------------------------------------------------------------------------------
        'conditionalLogic' => [
            'statamic_attribute' => null,
        ],

        // Disable auto-formatting
        // --------------------------------------------------------------------------------
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'disableAutoformat' => [
            'statamic_attribute' => null,
        ],
    ];

    /**
     * Configure a Statamic form with settings from Gravity Forms data.
     *
     * Maps Gravity Forms notifications to Statamic email configurations
     * and form configuration fields, then applies them to the provided form.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $gravityFormsData  The Gravity Forms form data
     * @param  string  $siteHandle  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return bool True if configuration was successful, false otherwise
     */
    public function configureFormEmailSettings(
        Form $form,
        array $gravityFormsData,
        string $siteHandle,
        $assetImporter
    ): bool {
        return $this->configureFormSettingsInternal(
            $form,
            $gravityFormsData,
            'Gravity Forms',
            $siteHandle,
            $assetImporter
        );
    }

    /**
     * Parse and prepare form data for mapping (plugin-specific).
     *
     * @param  array<int|string, mixed>  $formData  The raw form data from the plugin
     * @return array<int|string, mixed> The processed form data ready for mapping
     */
    protected function parseFormData(
        array $formData
    ): array {
        // Gravity Forms doesn't have options JSON parsing like Formidable Forms
        // Return the form data as-is
        return $formData;
    }

    /**
     * Map plugin-specific fields that aren't covered by standard mappings.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    protected function mapPluginSpecificFields(
        array &$currentFormData,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        // Gravity Forms: Handle datetime field processing for schedule form fields
        // This logic was previously hardcoded in the trait but is specific to Gravity Forms

        // Process schedule form datetime fields that require special handling
        $scheduleFields = ['schedule_form.start_datetime', 'schedule_form.end_datetime'];

        foreach ($scheduleFields as $fieldPath) {
            if (
                str_contains(
                    $fieldPath,
                    '.'
                )
            ) {
                $parts = \explode(
                    '.',
                    $fieldPath
                );
                $parentKey = $parts[0];
                $childKey = $parts[1];

                // Check if we have a value for this datetime field
                if (
                    isset($currentFormData[$parentKey])
                    && \is_array($currentFormData[$parentKey])
                    && \array_key_exists($childKey, $currentFormData[$parentKey])
                ) {
                    $value = $currentFormData[$parentKey][$childKey];

                    // Only set the value if it's not null (indicating complete data)
                    if ($value === null) {
                        // Remove the field if the value is null
                        unset($currentFormData[$parentKey][$childKey]);

                        // If the parent array is now empty, remove it too
                        if ($currentFormData[$parentKey] === []) {
                            unset($currentFormData[$parentKey]);
                        }
                    }
                }
            }
        }

        // Clean up variables to free up memory
        unset(
            $formData,
            $siteHandle,
            $assetImporter,
            $scheduleFields,
            $fieldPath,
            $parts,
            $parentKey,
            $childKey,
            $value,
        );
    }
}
