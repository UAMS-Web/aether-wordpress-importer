<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables as StatamicVariables;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationDesignator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationMap;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationOutcome;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteResolver;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Enables collections, globals and taxonomies for the site, and imports its navigation and department organization.
 *
 * Part of the `statamic:import:wordpress` command, split out of
 * {@see ImportWordPress} so that each file's
 * mutation run fits the CI limit (#546). The methods run on the command and use its
 * properties and output.
 */
trait PreparesImportSite
{
    /**
     * Check if a collection is enabled for a site.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @return bool True if enabled, false otherwise
     */
    private function isCollectionEnabledForSite(
        string $site,
        string $collectionHandle
    ): bool {
        // Check if collection is enabled for the site using SiteManager
        return $this->siteManager->isCollectionEnabledForSite(
            $site,
            $collectionHandle
        );
    }

    /**
     * Enable a collection for a site.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     */
    private function enableCollectionForSite(
        string $site,
        string $collectionHandle
    ): void {
        // Enable collection for the site using SiteManager
        $this->siteManager->enableCollectionForSite(
            $site,
            $collectionHandle,
            $this->output
        );
    }

    /**
     * Check if a global set is enabled for a site.
     *
     * @param  string  $site  The site handle
     * @param  string  $globalHandle  The global set handle
     * @return bool True if enabled (variables exist for the site), false otherwise
     */
    private function isGlobalEnabledForSite(
        string $site,
        string $globalHandle
    ): bool {
        // Find global set by handle
        $global = GlobalSet::findByHandle($globalHandle);

        // Check if global set exists and has localization for the site
        return $global !== null && $global->in($site) !== null;
    }

    /**
     * Enable a global set for a site by creating its variables.
     *
     * Adds the site to the global set's sites and saves the set so Statamic
     * creates and persists the Variables (localization) for the site.
     *
     * @param  string  $site  The site handle
     * @param  string  $globalHandle  The global set handle
     */
    private function enableGlobalForSite(
        string $site,
        string $globalHandle
    ): void {
        // Find global set by handle
        $global = GlobalSet::findByHandle($globalHandle);

        // Check if the global set does not exist
        if (! $global instanceof StatamicGlobalSet) {
            // Skip the rest of the method
            return;
        }

        // Check if the global set is already localized for this site
        if ($global->in($site) !== null) {
            // Skip the rest of the method
            return;
        }

        // Add site to the global set's sites so Statamic creates Variables for it
        $sites = $global->sites();

        // A global set's sites() always answers a collection, so the assertion only
        // types it.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveFunctionCall
        \assert($sites instanceof \Illuminate\Support\Collection);

        $global->sites(
            $sites->merge([$site])->unique()->values()->all()
        );

        // Persist global set (creates and saves Variables for the new site)
        $global->save();
    }

    /**
     * Enable a taxonomy for a site if needed.
     *
     * Validates the existence of the taxonomy and checks if it is enabled for the
     * site. Enables it automatically if not already enabled.
     *
     * @param  string  $site  The site handle
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  string  $contentType  The content type being imported
     * @return bool True if the taxonomy is enabled or successfully enabled, false otherwise
     */
    private function enableTaxonomyIfNeeded(
        string $site,
        string $statamicTaxonomyHandle,
        string $contentType
    ): bool {
        // Resolve the destination in whichever shape it takes. Asking only about a
        // taxonomy reported `logos_item_categories` and `people_groups` as missing
        // once `uams-statamic-archive#1863` converted them to structured collections, and
        // every caller of this method skips a whole content type on a false answer.
        $result = $this->siteManager->enableDestinationForSite(
            $site,
            $statamicTaxonomyHandle,
            $this->output
        );

        // Check if enabling the destination failed
        if (! $result) {
            // Warn the user that the destination is absent in both shapes, rather
            // than naming one of them and sending them to create the wrong thing.
            $this->warn("'$statamicTaxonomyHandle' does not exist in Statamic as a taxonomy or a collection.");

            // Inform user that the destination needs to be created
            $this->info("Please create and configure '$statamicTaxonomyHandle' before importing '$contentType' content.");

            // Return false to indicate failure
            return false;
        }

        // Return true to indicate success
        return true;
    }

    /**
     * Handle navigation menu import process.
     *
     * Prompts user to confirm active primary navigation and select menu to import,
     * then imports the selected menu items into Statamic's primary_navigation.
     */
    private function handleNavigationMenuImport(): void
    {
        // Get the site handle
        $site = SiteResolver::getCurrentSite();

        // Check if no site was selected. The resolver falls back to the default site, so
        // it always answers one, and the check only types it.
        // @pest-mutate-ignore: RemoveMethodCall, RemoveEarlyReturn
        if ($site === null) {
            // Alert the user that no site was selected
            $this->error('No site selected for navigation import');

            // Exit the command
            return;
        }

        // Confirm if there's an active menu in the primary navigation position
        $hasActiveMenu = $this->navigationMenuSelector->confirmActivePrimaryNavigation(
            $this->xmlParser,
            $this->input,
            $this->output,
            $this
        );

        // Check if no menu was selected
        if (! $hasActiveMenu) {
            // Alert the user that no menu was selected
            $this->info('Skipping navigation menu import - no active menu in primary navigation position');

            // Exit the command
            return;
        }

        // Set manual_primary_navigation to true in uams_multisite_settings Global
        $this->setManualPrimaryNavigation($site);

        // Select which menu to import
        $selectedMenuSlug = $this->navigationMenuSelector->selectNavigationMenu(
            $this->xmlParser,
            $this->input,
            $this->output,
            $this
        );

        // Check if no menu was selected
        if ($selectedMenuSlug === null) {
            // Alert the user that no menu was selected
            $this->info('No menu selected - skipping navigation menu import');

            // Exit the command
            return;
        }

        // Import the selected menu
        $this->navigationMenuImporter->importNavigationMenu(
            $this->xmlParser,
            $selectedMenuSlug,
            $site,
            $this->output
        );
    }

    /**
     * Set manual_primary_navigation to true in uams_multisite_settings Global.
     *
     * Updates the localized uams_multisite_settings Global for the specified site
     * to indicate that manual primary navigation is enabled.
     *
     * @param  string  $site  The Statamic site handle
     */
    private function setManualPrimaryNavigation(
        string $site
    ): void {
        // Attempt to update the Global with error handling
        try {
            // Get the uams_multisite_settings Global
            $global = GlobalSet::findByHandle('uams_multisite_settings');

            // Check if the Global exists
            if ($global === null) {
                // Warn user that Global was not found
                $this->warn('uams_multisite_settings Global not found - skipping manual_primary_navigation setting');

                // Exit early since Global doesn't exist
                return;
            }

            // Get the localized version for the site
            $localizedGlobal = $global->in($site);

            // Check if the Global is available for the specified site
            if (! $localizedGlobal instanceof StatamicVariables) {
                // Warn user that Global is not available for this site
                $this->warn("uams_multisite_settings Global not available for site '{$site}' - skipping manual_primary_navigation setting");

                // Exit early since Global is not available for this site
                return;
            }

            // Set manual_primary_navigation to true
            $localizedGlobal->set(
                'manual_primary_navigation',
                true
            );

            // Save the changes to persist them
            $localizedGlobal->save();

            // Inform user of successful operation
            $this->info("Set manual_primary_navigation to true for site '{$site}'");
        }

        // Handle any exceptions that occur during Global operations
        catch (\Exception $exception) {
            // Warn user about the failure
            $this->warn("Failed to set manual_primary_navigation for site '{$site}': {$exception->getMessage()}");

            // Log the error for debugging purposes
            Log::error(
                'ImportWordPress::setManualPrimaryNavigation failed',
                [
                    'site' => $site,
                    'error' => $exception->getMessage(),
                ]
            );
        }
    }

    /**
     * Set this site's `department_organization`, opting it in as a news hub.
     *
     * Reports rather than throws. A designation that cannot be applied leaves the
     * site rendering its news page as a plain listing, which is the documented
     * degrade and not a reason to abandon an import — so every branch here ends in
     * output, and none of them alters the caller's control flow (wordpress-importer-archive#768).
     *
     * The crosswalk this reads ships empty pending its authoring, so a normal run
     * reports every site as unrecorded. That line is the point: it is the only
     * signal an operator gets that the designation step exists and has nothing to
     * apply yet.
     *
     * @param  string  $site  The Statamic site handle
     */
    private function designateDepartmentOrganization(
        string $site
    ): void {
        // Attempt the designation with error handling
        try {
            // Build the designator over the designations recorded in this package
            $designator = new DepartmentOrganizationDesignator(
                $this->departmentOrganizationMap()
            );

            // Apply whatever the crosswalk records for this site
            $designation = $designator->designate($site);

            // Report the outcome
            switch ($designation->outcome) {
                case DepartmentOrganizationOutcome::Designated:
                    $this->info("Designated site '$site' as a news hub for '{$designation->organizationSlug}'.");
                    break;

                case DepartmentOrganizationOutcome::AlreadyDesignated:
                    $this->info("Site '$site' is already designated as a news hub for '{$designation->organizationSlug}'.");
                    break;

                case DepartmentOrganizationOutcome::DeliberatelyUnmapped:
                    // A recorded rejection is a decision, not a gap - nothing to report
                    break;

                case DepartmentOrganizationOutcome::NotRecorded:
                    $this->info("No department_organization designation is recorded for site '$site' - its news page will render as a plain listing.");
                    break;

                case DepartmentOrganizationOutcome::DanglingReference:
                    $this->warn("Site '$site' is designated for organization '{$designation->organizationSlug}', which does not exist - designation not applied.");
                    break;

                case DepartmentOrganizationOutcome::GlobalMissing:
                    $this->warn('uams_multisite_settings Global not found - skipping department_organization designation');
                    break;

                case DepartmentOrganizationOutcome::SiteUnavailable:
                    $this->warn("uams_multisite_settings Global not available for site '$site' - skipping department_organization designation");
                    break;
            }

            // Attribute the hub-audit failure this designation will cause, so it is
            // expected rather than discovered by whoever runs the audit later.
            if ($designation->isDesignated() && $designation->schemaSubjectMissing) {
                $this->warn("Organization '{$designation->organizationSlug}' has no schema_subject - 'multisite:audit-department-hubs' will report site '$site' until UAMS-Web/uams-statamic#197 authors it.");
            }
        }

        // Handle any exceptions that occur during the designation
        catch (\Exception $exception) {
            // Warn user about the failure
            $this->warn("Failed to set department_organization for site '$site': {$exception->getMessage()}");

            // Log the error for debugging purposes
            Log::error(
                'ImportWordPress::designateDepartmentOrganization failed',
                [
                    'site' => $site,
                    'error' => $exception->getMessage(),
                ]
            );
        }
    }

    /**
     * The site to organization crosswalk the designation applies: the one recorded in this
     * package.
     *
     * A method of its own so a test can supply a crosswalk with entries, since the recorded
     * one ships empty.
     */
    protected function departmentOrganizationMap(): DepartmentOrganizationMap
    {
        // Read the crosswalk recorded in this package
        return DepartmentOrganizationMap::recorded();
    }
}
