<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\Concerns;

use Illuminate\Support\Collection;
use Statamic\Forms\Form;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager;

/**
 * Maps Gravity Forms confirmations: the default confirmation, the additional ones, and a
 * single confirmation view.
 *
 * Split out of {@see FormConfigurationManager} (#551) so that a pull request changing
 * one part of the class creates few enough mutants to finish inside the CI mutation
 * job's limit. The methods run as methods of that class: they read its constants
 * and call its other methods, and a subclass can still override the protected ones.
 */
trait MapsGravityFormsConfirmations
{
    /**
     * Map confirmation fields from Gravity Forms data to Statamic form settings.
     *
     * Maps confirmation fields from all confirmations in the confirmations array
     * to Statamic confirmation configuration fields. Handles both default and
     * additional confirmations with proper nested group structure.
     *
     * Supports conditional mapping with 'based_on_other_attribute_value' structure
     * for complex scenarios where the target field depends on other field values.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $formData  The Gravity Forms form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    protected function mapConfirmationFields(
        Form $form,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        // Check if confirmations exist and have at least one item
        if (! isset($formData['confirmations']) || ! \is_array($formData['confirmations']) || $formData['confirmations'] === []) {
            return;
        }

        // Get the current form data. A Statamic form's data is always a collection; the check
        // narrows the untyped accessor for PHPStan.
        $formDataStore = $form->data();
        // @pest-mutate-ignore: InstanceOfToTrue
        $currentFormData = $formDataStore instanceof Collection ? $formDataStore->all() : [];

        // Initialize arrays for default and additional confirmations
        $defaultConfirmation = null;
        $additionalConfirmations = [];

        // Process each confirmation
        foreach ($formData['confirmations'] as $confirmation) {
            if (! \is_array($confirmation)) {
                continue;
            }

            // Skip confirmations that don't have event equal to 'form_submission'
            if (! isset($confirmation['event']) || $confirmation['event'] !== 'form_submission') {
                continue;
            }

            // Check if this is the default confirmation
            if (isset($confirmation['isDefault']) && $confirmation['isDefault'] === true) {
                $defaultConfirmation = $confirmation;
            } else { // Otherwise
                // This is an additional confirmation
                $additionalConfirmations[] = $confirmation;
            }
        }

        // Map default confirmation if it exists
        if ($defaultConfirmation !== null) {
            $this->mapDefaultConfirmation(
                $currentFormData,
                $defaultConfirmation,
                $formData,
                $siteHandle,
                $assetImporter
            );
        }

        // Map additional confirmations if they exist
        if ($additionalConfirmations !== []) {
            $this->mapAdditionalConfirmations(
                $currentFormData,
                $additionalConfirmations,
                $formData,
                $siteHandle,
                $assetImporter
            );
        }

        // Apply the mapped confirmation data to the form
        $form->merge($currentFormData);

        // Clean up variables to free up memory
        unset(
            $formData,
            $siteHandle,
            $assetImporter,
            $currentFormData,
            $defaultConfirmation,
            $additionalConfirmations,
            $confirmation,
        );
    }

    /**
     * Map default confirmation to Statamic form settings.
     *
     * Maps a default confirmation to the confirmation_settings_default group field.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  array<int|string, mixed>  $confirmation  The confirmation data
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    private function mapDefaultConfirmation(
        array &$currentFormData,
        array $confirmation,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        $formIdForContext = isset($formData['id']) && \is_scalar($formData['id'])
            ? $formData['id']
            : '?';
        $sourceNameForContext = isset($confirmation['name']) && \is_scalar($confirmation['name'])
            ? (string) $confirmation['name']
            : 'Default';

        // Set form config context so log messages identify this confirmation
        $this->setFormConfigContextForLogging([
            'form_id' => $formIdForContext,
            'source' => 'confirmation',
            'source_name' => $sourceNameForContext,
            'plugin' => 'Gravity Forms',
        ]);

        // Wrap processing in try/finally so form config context is always cleared
        try {
            $currentFormData['confirmation_settings_default'] ??= [];

            // ---
            // Use common mapping logic
            // ---

            // Get form fields for mapping
            $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                ? $formData['fields']
                : [];

            // Map confirmation fields to Statamic structure. The confirmation table spreads no
            // value and sets no other field, so the mapped data it returns is always empty.
            $this->processConfirmationFieldMapping(
                $confirmation,
                $formData,
                $siteHandle,
                $assetImporter,
                $formFields,
                static::FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION,
                function (string $statamicKey, $value, $mappingConfig) use (&$currentFormData): void {
                    if (
                        ! isset($currentFormData['confirmation_settings_default'])
                        || ! \is_array($currentFormData['confirmation_settings_default'])
                    ) {
                        $currentFormData['confirmation_settings_default'] = [];
                    }

                    // Store the value in the default confirmation settings group
                    $currentFormData['confirmation_settings_default'][$statamicKey] = $value;
                },
                // This is a default confirmation. The flag only excludes entries marked for it,
                // and this class's confirmation table marks none.
                // @pest-mutate-ignore: TrueToFalse
                true
            );

            // ---
            // Note: Default confirmations do not support conditional logic in Statamic.
            // Conditional logic is only supported for additional confirmations.
            // ---
        } finally {
            // Clear form config context so logs do not attribute later processing
            $this->clearFormConfigContextForLogging();
        }

        // Clean up variables to free up memory
        unset(
            $confirmation,
            $formData,
            $siteHandle,
            $assetImporter,
        );
    }

    /**
     * Map additional confirmations to Statamic form settings.
     *
     * Maps additional confirmations to the confirmation_additional replicator field.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  list<array<int|string, mixed>>  $additionalConfirmations  Array of additional confirmations
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    private function mapAdditionalConfirmations(
        array &$currentFormData,
        array $additionalConfirmations,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        $currentFormData['confirmation_additional'] ??= [];

        // Process each additional confirmation
        foreach ($additionalConfirmations as $confirmation) {
            $formIdForContext = isset($formData['id']) && \is_scalar($formData['id'])
                ? $formData['id']
                : '?';
            $sourceNameForContext = isset($confirmation['name']) && \is_scalar($confirmation['name'])
                ? (string) $confirmation['name']
                : 'Additional';

            // Set form config context so log messages identify this confirmation
            $this->setFormConfigContextForLogging([
                'form_id' => $formIdForContext,
                'source' => 'confirmation',
                'source_name' => $sourceNameForContext,
                'plugin' => 'Gravity Forms',
            ]);

            // Wrap processing in try/finally so form config context is always cleared
            try {
                // Create a new replicator set for this confirmation
                $replicatorSet = [
                    'type' => 'confirmation',
                    'enabled' => isset($confirmation['isActive']) ? (bool) $confirmation['isActive'] : true,
                ];

                // ---
                // Map the confirmation ID if present
                // ---

                // Map confirmation ID when present
                if (isset($confirmation['id'])) {
                    // Store confirmation ID in replicator set
                    $replicatorSet['id'] = $confirmation['id'];
                }

                // ---
                // Map the confirmation name
                // ---

                // Map confirmation name when present
                if (isset($confirmation['name'])) {
                    // Store confirmation name in replicator set
                    $replicatorSet['confirmation_name'] = $confirmation['name'];
                }

                // Initialize the confirmation settings group
                $replicatorSet['confirmation_settings'] = [];

                // ---
                // Use common mapping logic
                // ---

                // Get form fields for mapping
                $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                    ? $formData['fields']
                    : [];

                // Map confirmation fields to Statamic structure; as for the default confirmation,
                // the mapped data it returns is always empty
                $this->processConfirmationFieldMapping(
                    $confirmation,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields,
                    static::FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION,
                    function (string $statamicKey, $value, $mappingConfig) use (&$replicatorSet): void {
                        // Store the value in the confirmation settings group
                        $replicatorSet['confirmation_settings'][$statamicKey] = $value;
                    },
                    // This is an additional confirmation; as above, the table marks no entry the
                    // flag could exclude.
                    // @pest-mutate-ignore: FalseToTrue
                    false
                );

                // ---
                // Map conditional logic if present
                // ---

                // Map conditional logic when present
                if (
                    isset($confirmation['conditionalLogic'])
                    && \is_array($confirmation['conditionalLogic'])
                ) {
                    // Convert conditional logic to Statamic format
                    $conditionalLogic = FieldtypeMapping::mapConditionalLogic(
                        $confirmation['conditionalLogic'],
                        $formFields
                    );

                    // ---
                    // Store conditional logic at root level if not empty
                    // ---

                    // Store conditional logic when not empty
                    if ($conditionalLogic !== []) {
                        // Add conditional logic to replicator set
                        $replicatorSet['conditional_logic'] = $conditionalLogic;
                    }
                }

                // Add the replicator set to the additional confirmations array
                if (
                    ! isset($currentFormData['confirmation_additional'])
                    || ! \is_array($currentFormData['confirmation_additional'])
                ) {
                    $currentFormData['confirmation_additional'] = [];
                }

                $currentFormData['confirmation_additional'][] = $replicatorSet;
            } finally {
                // Clear form config context so logs do not attribute later processing
                $this->clearFormConfigContextForLogging();
            }
        }

        // Clean up variables to free up memory
        unset(
            $additionalConfirmations,
            $formData,
            $siteHandle,
            $assetImporter,
            $confirmation,
            $replicatorSet,
        );
    }

    /**
     * Process a single confirmation view and return the mapped data.
     *
     * @param  array<int|string, mixed>  $processedViewData  The processed view data (including parsed content)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @param  bool  $isAdditional  Whether this is an additional confirmation (affects field structure)
     * @return array<int|string, mixed>|null The mapped confirmation data or null if no data was mapped
     */
    protected function processSingleConfirmationView(
        array $processedViewData,
        array $formData,
        string $siteHandle,
        $assetImporter = null,
        bool $isAdditional = false
    ): ?array {
        $mappedData = [];

        // Gravity Forms: Use direct mapping
        foreach (static::FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION as $pluginKey => $mappingConfig) {
            // Handle complex mapping configurations (arrays) vs simple string mappings
            $statamicKey = $mappingConfig['statamic_attribute'];

            // Skip the entries handled elsewhere, which map to no attribute
            if (! \is_string($statamicKey)) {
                continue;
            }

            // Extract the value using dot notation if needed
            $value = $this->extractValueFromData(
                $processedViewData,
                $pluginKey
            );

            // Only include the field if the value exists and is not empty
            if ($value !== null && $value !== '') {
                // Process the value using the common configuration processing method
                $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                    ? $formData['fields']
                    : [];
                $value = $this->processMappingConfiguration(
                    $value,
                    $mappingConfig,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Set the value in the appropriate structure. The confirmation table spreads no
                // value over several attributes, so each lands under its own attribute.
                if ($isAdditional) {
                    // For additional confirmations, add to confirmation_settings
                    if (
                        ! isset($mappedData['confirmation_settings'])
                        || ! \is_array($mappedData['confirmation_settings'])
                    ) {
                        $mappedData['confirmation_settings'] = [];
                    }

                    $mappedData['confirmation_settings'][$statamicKey] = $value;
                } else { // Otherwise
                    // For default confirmation, set directly
                    $mappedData[$statamicKey] = $value;
                }
            }
        }

        // Return null if no data was mapped
        return $mappedData === [] ? null : $mappedData;
    }
}
