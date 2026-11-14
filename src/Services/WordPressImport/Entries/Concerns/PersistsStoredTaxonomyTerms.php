<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Sites\Site;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\StacheIndexUpdateToggle;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;

/**
 * Stores per-site taxonomy data during the import and writes the terms it describes afterward.
 *
 * Split out of {@see BaseEntryCreator} so that each file's mutation run fits
 * the CI limit (#550). The methods run on the creator and use its properties
 * and static caches, which stay declared on that class.
 */
trait PersistsStoredTaxonomyTerms
{
    /**
     * Stores site-specific taxonomy data for later term creation.
     *
     * @param  array<int|string, mixed>  $siteSpecificData  Array to store data in (passed by reference)
     * @param  string  $siteSpecificSlug  The site-specific slug
     * @param  string  $originalSlug  The original slug before site prefix
     * @param  string  $termName  The term name
     */
    protected function storeSiteSpecificTaxonomyData(
        array &$siteSpecificData,
        string $siteSpecificSlug,
        string $originalSlug,
        string $termName
    ): void {
        $termData = [
            'site_specific' => $siteSpecificSlug,
            'original' => $originalSlug,
            'name' => $termName,
        ];

        // Check if this term data is already stored to avoid duplicates
        if (
            ! \in_array(
                $termData,
                $siteSpecificData,
                true
            )
        ) {
            $siteSpecificData[] = $termData;
        }
    }

    /**
     * Creates taxonomy terms from stored site-specific data.
     *
     * Processes multiple taxonomy terms one at a time to avoid memory issues.
     * Uses caching to avoid duplicate operations and Statamic facade calls.
     * Groups terms by operation type (create, update, already exist) for
     * efficient processing with proper memory management.
     *
     * @param  array<int|string, mixed>  $siteSpecificData  Array of term data with 'original', 'name' keys
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle (e.g., 'post_categories', 'people_groups')
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function createTaxonomyTermsFromStoredData(
        array $siteSpecificData,
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output
    ): void {
        if ($siteSpecificData === []) {
            return;
        }

        // ---
        // Refuse to write taxonomy terms to a handle that is a structured
        // COLLECTION. `UAMS-Web/uams-statamic-archive#1863` converted `people_groups` and
        // `logos_item_categories` into collections alongside `post_categories`,
        // and terms written to one of those went nowhere: the preload below
        // guards on TaxonomyFacade::find(), which returns null for a collection,
        // so every term was silently discarded. Those taxonomies are imported by
        // StructuredTermImporter instead. This guard covers the callers that
        // reach this method directly — PostEntryCreator::validateTaxonomies() and
        // the per-creator overrides — rather than through the routed term pass.
        // ---
        if (TaxonomyDispositions::isStructuredDestination($statamicTaxonomyHandle)) {
            return;
        }

        // Get site name from cache once
        $siteName = self::getCachedSiteName($site);

        // Group terms by operation type for batch processing
        $termsToCreate = [];
        $termsToUpdate = [];
        $termsAlreadyExist = [];

        // Preload every existing term for this taxonomy in a single query rather than
        // calling TermFacade::find() once per term. Term::find() routes through
        // TermRepository::query(), which unconditionally forces every taxonomy's
        // associations index to load — and on a cold cache that rebuild scans all entries
        // in all collections. Doing that N times (once per term) is the dominant cost of
        // term creation. One whereTaxonomy() pass collapses the storm to a single query.
        // The map is keyed by term id ("{handle}::{slug}"), which is identical to the
        // $cacheKey computed per term below. whereTaxonomy() throws if the taxonomy does
        // not exist yet, so guard with find() (a missing taxonomy is reported later).
        $existingTerms = [];
        if (TaxonomyFacade::find($statamicTaxonomyHandle) !== null) {
            foreach (TermFacade::whereTaxonomy($statamicTaxonomyHandle) as $existingTermEntry) {
                $existingTermId = self::extractObjectId($existingTermEntry);
                if ($existingTermId === null) {
                    continue;
                }

                $existingTerms[$existingTermId] = $existingTermEntry;
            }
        }

        // Track cache keys queued for creation during this run so the "Create an
        // additional term" uniqueness loop (and repeated input slugs) don't collide on a
        // slug that exists only in memory and has not been persisted yet.
        $reservedKeys = [];

        foreach (ImportProgress::track($output, "{$statamicTaxonomyHandle} terms checked", $siteSpecificData) as $index => $termData) {
            if (! \is_array($termData)) {
                continue;
            }

            $originalSlug = isset($termData['original']) && \is_string($termData['original'])
                ? $termData['original']
                : '';
            $termName = isset($termData['name']) && \is_string($termData['name'])
                ? $termData['name']
                : '';
            $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug(
                $originalSlug,
                $site
            );
            $cacheKey = "{$statamicTaxonomyHandle}::{$siteSpecificSlug}";

            // Check if we've already processed this term in this session
            if (isset(self::$processedTermsCache[$cacheKey])) {
                continue;
            }

            // Skip terms already queued for creation this run (duplicate input slugs)
            if (isset($reservedKeys[$cacheKey])) {
                continue;
            }

            // Check if term already exists (from the preloaded map, not a per-term query)
            $existingTerm = $existingTerms[$cacheKey] ?? null;

            // The preload yields localized terms (Statamic 6's term query returns
            // LocalizedTerm); work on the term each one localizes (#188)
            if ($existingTerm instanceof LocalizedTerm) {
                $existingTerm = $existingTerm->term();
            }

            if ($existingTerm !== null) {
                // Handle existing term based on overwrite behavior
                switch ($this->taxonomyOverwriteBehavior) {
                    // Handle case where existing terms should be skipped
                    case 'Skip matching terms':
                        // Display skip message to user
                        $output->writeln("      - <comment>Skipped taxonomy term '$siteSpecificSlug' as it already exists.</comment>");

                        // Mark term as processed to avoid duplicate processing
                        self::$processedTermsCache[$cacheKey] = false;

                        // Skip to next term in the loop
                        continue 2; // Skip to next term

                        // Handle case where additional terms should be created with unique slugs
                    case 'Create an additional term':
                        if (! $existingTerm instanceof Term) {
                            continue 2;
                        }

                        // Generate a new slug with counter
                        $counter = 1;

                        // Loop until we find a unique slug
                        do {
                            // Append counter to create unique slug, in a form Statamic's
                            // slug setter keeps, so a later run finds it (#193)
                            $newSlug = "{$siteSpecificSlug}-{$counter}";

                            // Update cache key with new slug
                            $newCacheKey = "{$statamicTaxonomyHandle}::{$newSlug}";

                            // Increment counter for next iteration
                            $counter++;

                            // Check if term with new slug already exists (persisted in
                            // the preloaded map, or reserved by a create queued this run)
                            $termExists = isset($existingTerms[$newCacheKey])
                                || isset($reservedKeys[$newCacheKey]);
                        } while ($termExists);

                        // Generate expected term name from original slug
                        $expectedTermName = $termName !== ''
                            ? $termName
                            : \ucwords(
                                \str_replace(
                                    ['-', '_'],
                                    ' ',
                                    $originalSlug
                                )
                            );

                        // Create title with site name prefix
                        $title = "{$siteName} — {$expectedTermName}";

                        // Add new term to creation queue
                        $termsToCreate[] = [
                            'slug' => $newSlug,
                            'title' => $title,
                            'cacheKey' => $newCacheKey,
                        ];

                        // Reserve the slug so later iterations treat it as taken. Only
                        // isset() reads it, which takes false as set as well
                        // @pest-mutate-ignore: TrueToFalse
                        $reservedKeys[$newCacheKey] = true;

                        // Exit the switch statement after processing additional term creation
                        break;

                        // Handle case where existing terms should be overwritten or updated
                    case 'Overwrite matching terms':
                    default:
                        if (! $existingTerm instanceof Term) {
                            continue 2;
                        }

                        // Generate expected term name from original slug
                        $expectedTermName = $termName !== ''
                            ? $termName
                            : \ucwords(
                                \str_replace(
                                    ['-', '_'],
                                    ' ',
                                    $originalSlug
                                )
                            );

                        // Create expected title with site name prefix
                        $expectedTitle = "{$siteName} — {$expectedTermName}";

                        // Check if the existing term needs updating
                        if (
                            self::getTermStringValue(
                                $existingTerm,
                                'title'
                            ) !== $expectedTitle
                            || self::getTermStringValue(
                                $existingTerm,
                                'blueprint'
                            ) !== $statamicTaxonomyHandle
                        ) {
                            // Add term to update queue if changes are needed
                            $termsToUpdate[] = [
                                'term' => $existingTerm,
                                'slug' => $siteSpecificSlug,
                                'title' => $expectedTitle,
                                'cacheKey' => $cacheKey,
                            ];
                        } else { // Otherwise
                            // Mark term as already existing if no changes needed
                            $termsAlreadyExist[] = $siteSpecificSlug;

                            // Mark term as processed to avoid duplicate processing
                            self::$processedTermsCache[$cacheKey] = false;
                        }

                        // Exit the switch statement after processing overwrite behavior
                        break;
                }
            } else { // Otherwise
                // Term needs to be created
                $expectedTermName = $termName !== ''
                    ? $termName
                    : \ucwords(
                        \str_replace(
                            [
                                '-',
                                '_',
                            ],
                            ' ',
                            $originalSlug
                        )
                    );
                $title = "{$siteName} — {$expectedTermName}";

                $termsToCreate[] = [
                    'slug' => $siteSpecificSlug,
                    'title' => $title,
                    'cacheKey' => $cacheKey,
                ];

                // Reserve the slug so repeated input slugs are not queued twice. Only
                // isset() reads it, which takes false as set as well
                // @pest-mutate-ignore: TrueToFalse
                $reservedKeys[$cacheKey] = true;
            }

            // Clean up variables to free up memory
            unset(
                $termData,
                $existingTerm
            );

            // Force garbage collection every 20 terms to prevent memory buildup.
            // Collecting cycles frees memory and changes no result, so neither
            // does how often the condition lets it run
            // @pest-mutate-ignore: DecrementInteger, IncrementInteger, IdenticalToNotIdentical, IfNegated, ModulusToMultiplication, PlusToMinus
            if (((int) $index + 1) % 20 === 0) {
                // @pest-mutate-ignore: RemoveFunctionCall
                gc_collect_cycles();
            }
        }

        // Persist the queued updates and creations with Stache index updating paused (when
        // the installed statamic/cms supports a safe re-enable). Pausing skips the costly
        // full index rebuilds that scan every entry; terms are still written to disk on
        // save, and the wrapper re-enables indexing and rebuilds this taxonomy's term store
        // from disk afterward, so the Stache is correct before entry creation continues.
        // Only enter the paused window when there is actually something to write.
        if ($termsToUpdate !== [] || $termsToCreate !== []) {
            $this->withTermIndexUpdatesDisabled(
                $statamicTaxonomyHandle,
                fn () => $this->persistQueuedTermChanges(
                    $termsToUpdate,
                    $termsToCreate,
                    $statamicTaxonomyHandle,
                    $site,
                    $output
                )
            );
        }

        // Log terms that already exist (only if there are any)
        if ($termsAlreadyExist !== []) {
            $output->writeln('      - <comment>Taxonomy terms that already exist in Statamic:</comment>');

            foreach ($termsAlreadyExist as $slug) {
                $output->writeln("          - <comment>{$statamicTaxonomyHandle}::{$slug}</comment>");
            }
        }

        // Clean up variables to free up memory
        unset(
            $siteSpecificData,
            $termsToCreate,
            $termsToUpdate,
            $termsAlreadyExist,
            $siteName
        );

        // Force final garbage collection, which frees memory and changes no result
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();
    }

    /**
     * Persist the queued taxonomy term updates and creations.
     *
     * Extracted from {@see self::createTaxonomyTermsFromStoredData()} so the
     * save phase can be wrapped by {@see self::withTermIndexUpdatesDisabled()}.
     * An early return here only skips the remaining saves (the caller still runs
     * its trailing logging/cleanup), matching the original control flow.
     *
     * @param  array<int|string, mixed>  $termsToUpdate  Existing terms queued for update
     * @param  array<int|string, mixed>  $termsToCreate  New terms queued for creation
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    private function persistQueuedTermChanges(
        array $termsToUpdate,
        array $termsToCreate,
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Update existing terms individually
        if ($termsToUpdate !== []) {
            $output->writeln('      - Updating existing taxonomy terms...');
        }

        // Loop through each term that needs to be updated
        foreach (ImportProgress::track($output, "{$statamicTaxonomyHandle} terms updated", $termsToUpdate) as $index => $termData) {
            if (! \is_array($termData)) {
                continue;
            }

            if (
                ! isset($termData['term'], $termData['slug'], $termData['title'], $termData['cacheKey'])
                || ! $termData['term'] instanceof Term
                || ! \is_string($termData['slug'])
                || ! \is_string($termData['title'])
                || ! \is_string($termData['cacheKey'])
            ) {
                continue;
            }

            // ---
            // Set the data directly on the term
            // ---

            // Set the title on the term from the term data
            $termData['term']->set(
                'title',
                $termData['title']
            );

            // Set the blueprint on the term to match the taxonomy handle
            $termData['term']->set(
                'blueprint',
                $statamicTaxonomyHandle
            );

            // Save the term safely, handling search index JSON errors gracefully
            $saveSuccess = self::saveTaxonomyTermSafely(
                $termData['term'],
                $statamicTaxonomyHandle,
                $termData['slug'],
                $output
            );

            // Check if save was successful
            if ($saveSuccess) {
                // Log the successful update to console output
                $output->writeln("          - <info>Updated taxonomy term: {$statamicTaxonomyHandle}::{$termData['slug']} with title: {$termData['title']}</info>");

                // Cache the processed term to avoid duplicate processing
                self::$processedTermsCache[$termData['cacheKey']] = true;
            }

            // Clean up variables to free up memory
            unset($termData);

            // Check if we've processed a multiple of 10 terms. The condition only paces
            // the collection below, which changes no result; the index is a list
            // position, so the cast changes nothing either
            // @pest-mutate-ignore: DecrementInteger, IncrementInteger, IdenticalToNotIdentical, IfNegated, ModulusToMultiplication, PlusToMinus, RemoveIntegerCast
            if (((int) $index + 1) % 10 === 0) {
                // Force garbage collection to reclaim memory from circular references,
                // which changes no result
                // @pest-mutate-ignore: RemoveFunctionCall
                gc_collect_cycles();
            }
        }

        // Create new terms individually
        if ($termsToCreate !== [] && $termsToUpdate === []) {
            $output->writeln('      - Creating new taxonomy terms...');
        }

        // Validate that the taxonomy exists in Statamic before creating terms
        $taxonomyExists = TaxonomyFacade::find($statamicTaxonomyHandle);

        // Check if the taxonomy was found
        if ($taxonomyExists === null) {
            // Log error and exit if taxonomy doesn't exist
            Log::error("BaseEntryCreator::createTaxonomyTermsFromStoredData - Taxonomy '{$statamicTaxonomyHandle}' does not exist");

            // Exit early to prevent term creation attempts
            return;
        }

        // Validate that the site exists in Statamic before creating terms
        $siteExists = SiteFacade::get($site);

        // Check if the site was found
        if ($siteExists === null) {
            // Log error and exit if site doesn't exist
            Log::error("BaseEntryCreator::createTaxonomyTermsFromStoredData - Site '{$site}' does not exist");

            // Exit early to prevent term creation attempts
            return;
        }

        foreach (ImportProgress::track($output, "{$statamicTaxonomyHandle} terms created", $termsToCreate) as $index => $termData) {
            if (! \is_array($termData)) {
                continue;
            }

            if (
                ! isset($termData['slug'], $termData['title'], $termData['cacheKey'])
                || ! \is_string($termData['slug'])
                || ! \is_string($termData['title'])
                || ! \is_string($termData['cacheKey'])
            ) {
                continue;
            }

            /** @var Term $term */
            $term = TermFacade::make();
            $term->taxonomy($statamicTaxonomyHandle);
            $term->slug($termData['slug']);
            $term->set(
                'title',
                $termData['title']
            );
            $term->set(
                'blueprint',
                $statamicTaxonomyHandle
            );
            $saveSuccess = self::saveTaxonomyTermSafely(
                $term,
                $statamicTaxonomyHandle,
                $termData['slug'],
                $output
            );

            // Check if save was successful
            if ($saveSuccess) {
                $output->writeln("          - <info>Created '{$statamicTaxonomyHandle}::{$termData['slug']}'</info>");
            }

            self::$processedTermsCache[$termData['cacheKey']] = true;

            // Clean up variables to free up memory
            unset(
                $term,
                $termData
            );

            // Force garbage collection every 10 terms to prevent memory buildup.
            // Collecting cycles frees memory and changes no result, so neither does
            // how often the condition lets it run; the index is a list position, so
            // the cast changes nothing either
            // @pest-mutate-ignore: DecrementInteger, IncrementInteger, IdenticalToNotIdentical, IfNegated, ModulusToMultiplication, PlusToMinus, RemoveIntegerCast
            if (((int) $index + 1) % 10 === 0) {
                // @pest-mutate-ignore: RemoveFunctionCall
                gc_collect_cycles();
            }
        }
    }

    /**
     * Run the term-save work with Stache index updating paused, then restored.
     *
     * Disabling `Stache::updateIndexes` skips the expensive full index rebuilds
     * (which scan every entry in every collection) that would otherwise fire
     * while saving terms. Statamic offers no public way to turn the flag back
     * on, so this is gated behind {@see StacheIndexUpdateToggle::isSupported()},
     * which fails safe: when re-enabling cannot be verified, the work simply runs
     * with indexing left on (no speedup, never a corrupted Stache).
     *
     * Because terms are written to disk on save regardless of the flag, any cache
     * staleness during the paused window is harmless — the `finally` block
     * re-enables indexing and rebuilds this taxonomy's term store from disk before
     * the caller (and subsequent entry creation) reads from the Stache again.
     *
     * @param  string  $statamicTaxonomyHandle  Taxonomy whose term store to rebuild
     * @param  callable  $work  The term-save work to perform
     */
    private function withTermIndexUpdatesDisabled(
        string $statamicTaxonomyHandle,
        callable $work
    ): void {
        // Fall back to the safe path (indexing stays on) when the toggle is not
        // supported on the installed statamic/cms.
        if (! StacheIndexUpdateToggle::isSupported()) {
            $work();

            return;
        }

        StacheIndexUpdateToggle::disable();

        try {
            $work();
        } finally {
            // Re-enabling is critical: subsequent entry creation in this same run
            // queries the Stache and needs indexing live again.
            if (! StacheIndexUpdateToggle::enable()) {
                Log::error('BaseEntryCreator: failed to re-enable Stache index updating after term import; the Stache may be stale until the next full warm.');
            }

            // Rebuild this taxonomy's term store from disk so the indexes reflect
            // the bulk saves made while indexing was paused.
            $this->rebuildTermStoreFromDisk($statamicTaxonomyHandle);
        }
    }

    /**
     * Clear and warm a single taxonomy's term store so its indexes are rebuilt
     * from the term files on disk.
     *
     * Scoped to one taxonomy (not the whole Stache) to keep the rebuild cheap.
     * Failures are logged but never thrown — re-enabling the index flag already
     * happened in the caller, and the next full warm will reconcile anything left.
     *
     * **The find() guard below cannot be reached with a structured handle**, and
     * would be harmless if it were. Its callers are
     * {@see self::withTermIndexUpdatesDisabled()}, itself called only from
     * {@see self::createTaxonomyTermsFromStoredData()}, which returns early for
     * a structured destination, and `AdvertEntryCreator`, which passes the flat
     * `classified_categories`. Were it reached, a collection has no term store
     * to rebuild and needs none, so returning early is the right answer rather
     * than a false report — unlike {@see self::createTaxonomyTerm()}, where the
     * same null answer was being reported to an operator as a missing taxonomy
     * (wordpress-importer-archive#1144).
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     */
    protected function rebuildTermStoreFromDisk(string $statamicTaxonomyHandle): void
    {
        try {
            // Nothing to rebuild if the taxonomy does not exist.
            if (TaxonomyFacade::find($statamicTaxonomyHandle) === null) {
                return;
            }

            $termStoreRoot = StacheFacade::store('terms');
            if (! \is_object($termStoreRoot) || ! \method_exists($termStoreRoot, 'store')) {
                return;
            }

            $store = $termStoreRoot->store($statamicTaxonomyHandle);
            if (! \is_object($store) || ! \method_exists($store, 'clear') || ! \method_exists($store, 'warm')) {
                return;
            }

            $store->clear();
            $store->warm();
        } catch (\Throwable $throwable) {
            Log::warning("BaseEntryCreator: failed to rebuild term store '{$statamicTaxonomyHandle}' after bulk term save: ".$throwable->getMessage());
        }
    }
}
