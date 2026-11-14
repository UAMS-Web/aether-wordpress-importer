<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns;

use Exception;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\NewsArticleResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;

/**
 * Creates taxonomy terms, directly or through the deferred term queue, and reports unmapped taxonomies.
 *
 * Split out of {@see BaseEntryCreator} so that each file's mutation run fits
 * the CI limit (#550). The methods run on the creator and use its properties
 * and static caches, which stay declared on that class.
 */
trait CreatesTaxonomyTerms
{
    /**
     * Safely saves a taxonomy term, handling search index JSON errors gracefully.
     *
     * When saving taxonomy terms during import, Statamic automatically updates
     * the search index. If the search index file contains corrupted JSON, this
     * can cause the import to fail. This method wraps the save operation in a
     * try-catch to handle such errors gracefully, allowing the import to
     * continue.
     *
     * @param  Term  $term  The term to save
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle for logging
     * @param  string  $termSlug  The term slug for logging
     * @param  OutputInterface  $output  Console output for logging
     * @return bool True if save was successful, false if an error occurred
     */
    private static function saveTaxonomyTermSafely(
        $term,
        string $statamicTaxonomyHandle,
        string $termSlug,
        OutputInterface $output
    ): bool {
        try {
            $term->save();

            return true;
        } catch (\JsonException $e) {
            // Log the JSON error with context
            Log::warning(
                "BaseEntryCreator::saveTaxonomyTermSafely: Search index JSON error when saving taxonomy term '{$statamicTaxonomyHandle}::{$termSlug}'. The term was saved but search indexing failed.",
                [
                    'taxonomy' => $statamicTaxonomyHandle,
                    'term_slug' => $termSlug,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                ]
            );

            // Display warning to user
            $output->writeln(
                "<comment>Warning: Taxonomy term '{$statamicTaxonomyHandle}::{$termSlug}' was saved but search index update failed (corrupted search index). Import will continue.</comment>"
            );

            // Return true because the term was actually saved, just the search
            // index update failed
            return true;
        } catch (Exception $e) {
            // Log other exceptions
            Log::error(
                "BaseEntryCreator::saveTaxonomyTermSafely: Unexpected error when saving taxonomy term '{$statamicTaxonomyHandle}::{$termSlug}'",
                [
                    'taxonomy' => $statamicTaxonomyHandle,
                    'term_slug' => $termSlug,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Display error to user
            $output->writeln(
                "<error>Error: Failed to save taxonomy term '{$statamicTaxonomyHandle}::{$termSlug}': {$e->getMessage()}</error>"
            );

            // Return false for unexpected errors
            return false;
        }
    }

    /**
     * Creates taxonomy terms with intelligent slug processing and caching.
     *
     * This method provides a unified interface for creating taxonomy terms from
     * parsers, processors, and entry creators. It intelligently handles both
     * simple and hierarchical slugs, automatically adding site prefixes while
     * preserving hierarchy structure.
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle (e.g., 'people_groups', 'logos_item_categories')
     * @param  string  $termSlug  The term slug to create (may be hierarchical or simple)
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     * @param  string  $termName  Optional term name. If empty or not provided, generates from slug
     * @return bool True if term was created, false if it already existed
     */
    public static function createTaxonomyTerm(
        string $statamicTaxonomyHandle,
        string $termSlug,
        string $site,
        OutputInterface $output,
        string $termName = ''
    ): bool {
        // Add site prefix to create site-specific taxonomy slug (handles hierarchy automatically)
        $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug(
            $termSlug,
            $site
        );

        // Create cache key for this term operation
        $cacheKey = "{$statamicTaxonomyHandle}::{$siteSpecificSlug}";

        // ---
        // Short-circuit only when this term has already been brought to its
        // final, correct state (cached `true`). A cached `false` means the term
        // still needs work — a prior save failed, or it was found with a
        // slug-as-title / wrong blueprint that was never reconciled — so fall
        // through and re-attempt rather than returning the stale failure. This
        // lets a later call with an explicit term name correct an auto-created
        // term whose title was left as its slug.
        // ---
        if (
            isset(self::$processedTermsCache[$cacheKey])
            && self::$processedTermsCache[$cacheKey]
        ) {
            return true;
        }

        // ---
        // Answer for a structured destination here rather than letting the
        // TaxonomyFacade::find() guard below answer for it.
        //
        // `UAMS-Web/uams-statamic-archive#1863` converted `people_groups` and
        // `logos_item_categories` into collections alongside `post_categories`,
        // and find() returns null for every one of them. So that guard reports
        // "the taxonomy does not exist" for a handle that does exist, and
        // reports it for every referenced term rather than for a genuinely
        // missing one - a run asserting a loss that did not occur (wordpress-importer-archive#1144).
        //
        // A term reaching here for a structured handle IS absent from the
        // collection: {@see BaseTaxonomyValidator::getExistingTerms()} resolves
        // existence against the collection before calling this. It cannot be
        // created from here either, because {@see StructuredTermImporter} owns
        // those entries and holds the tree position and WordPress term id this
        // call site has neither of. So the loss is reported and nothing is
        // written - the alternative being a parentless entry or silence.
        // ---
        if (TaxonomyDispositions::isStructuredDestination($statamicTaxonomyHandle)) {
            // Distinguish an absent term from an absent destination, since the
            // two need different fixes and the old message named neither
            $destinationMissing = CollectionFacade::findByHandle($statamicTaxonomyHandle) === null;

            $reason = $destinationMissing
                ? "'{$statamicTaxonomyHandle}' exists as neither a taxonomy nor a collection"
                : "the '{$statamicTaxonomyHandle}' collection has no entry for it";

            // Report the loss rather than writing a term to a handle that is
            // not a taxonomy, which would go nowhere
            $output->writeln("<comment>Warning: cannot create '{$siteSpecificSlug}': {$reason}. Structured terms are created by the term pass, so this reference will not resolve.</comment>");

            // Cache so a repeated reference does not repeat the warning, which
            // is what the taxonomy-missing path below does for the same reason
            self::$processedTermsCache[$cacheKey] = true;

            // Return false to indicate no term was created
            return false;
        }

        // Check if term already exists. The lookup answers a localized term, so
        // work on the term it localizes, as the flat pass does (#188)
        $existingTerm = TermFacade::find($cacheKey);
        if ($existingTerm instanceof LocalizedTerm) {
            $existingTerm = $existingTerm->term();
        }

        if ($existingTerm instanceof Term) {
            // Get site name from cache
            $siteName = self::getCachedSiteName($site);
            $expectedTermName = $termName !== ''
                ? $termName
                : \ucwords(
                    \str_replace(
                        [
                            '-',
                            '_',
                        ],
                        ' ',
                        $termSlug
                    )
                );
            $expectedTitle = "{$siteName} — {$expectedTermName}";

            // If the existing term has the wrong title or blueprint, update it
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
                $existingTerm->set(
                    'title',
                    $expectedTitle
                );
                $existingTerm->set(
                    'blueprint',
                    $statamicTaxonomyHandle
                );
                $saveSuccess = self::saveTaxonomyTermSafely(
                    $existingTerm,
                    $statamicTaxonomyHandle,
                    $siteSpecificSlug,
                    $output
                );

                // Check if save was successful
                if ($saveSuccess) {
                    $output->writeln("  - <info>Updated taxonomy term: {$statamicTaxonomyHandle}::{$siteSpecificSlug} with title: {$expectedTitle}</info>");
                    self::$processedTermsCache[$cacheKey] = true;

                    return true;
                }

                // If save failed, return false
                self::$processedTermsCache[$cacheKey] = false;

                return false;
            }

            $output->writeln("<comment>Taxonomy term already exists: {$statamicTaxonomyHandle}::{$siteSpecificSlug}</comment>");
            self::$processedTermsCache[$cacheKey] = false;

            return false;
        }

        // Get site name from cache
        $siteName = self::getCachedSiteName($site);

        // Use provided term name or generate from slug
        if ($termName === '') {
            $termName = \ucwords(
                \str_replace(
                    [
                        '-',
                        '_',
                    ],
                    ' ',
                    $termSlug
                )
            );
        }

        $title = "{$siteName} — {$termName}";

        // Check if the taxonomy exists in Statamic
        $taxonomyExists = TaxonomyFacade::find($statamicTaxonomyHandle);

        // Skip term creation if taxonomy doesn't exist
        if ($taxonomyExists === null) {
            // Log warning about missing taxonomy
            $output->writeln("<comment>Warning: Taxonomy '{$statamicTaxonomyHandle}' does not exist. Skipping term creation for '{$siteSpecificSlug}'.</comment>");

            // Cache the failed attempt to avoid repeated warnings
            self::$processedTermsCache[$cacheKey] = true;

            // Return false to indicate term creation failed
            return false;
        }

        // Create the term using Statamic facade
        /** @var Term $term */
        $term = TermFacade::make();
        $term->taxonomy($statamicTaxonomyHandle);
        $term->slug($siteSpecificSlug);
        // Term::in() answers a localization and changes the term in nothing, so no
        // outcome depends on this call; the host-app suite pins it
        // (TermCreationInNonDefaultSitesTest)
        // @pest-mutate-ignore: RemoveMethodCall
        $term->in($site);
        $term->set(
            'title',
            $title
        );
        $term->set(
            'blueprint',
            $statamicTaxonomyHandle
        );

        // Save the term safely, handling search index errors
        $saveSuccess = self::saveTaxonomyTermSafely(
            $term,
            $statamicTaxonomyHandle,
            $siteSpecificSlug,
            $output
        );

        // Check if save was successful
        if ($saveSuccess) {
            // Log the creation of the term
            $output->writeln("  - <info>Created '{$statamicTaxonomyHandle}::{$siteSpecificSlug}'</info>");

            // Clear the taxonomy validator cache to include the newly created term
            BaseTaxonomyValidator::clearCaches();

            self::$processedTermsCache[$cacheKey] = true;

            return true;
        }

        // If save failed, return false
        self::$processedTermsCache[$cacheKey] = false;

        return false;
    }

    /**
     * Queue a taxonomy term for batched creation instead of creating it inline.
     *
     * This is the taxonomy- and shortcode-agnostic counterpart to
     * {@see self::createTaxonomyTerm()}. On-demand callers (people/team and logo
     * shortcodes, and any future parser) enqueue here during the content loop;
     * {@see self::flushDeferredTaxonomyTerms()} then creates everything in one
     * preload + paused-index pass per taxonomy. Creating terms one at a time
     * inline forced a cold Stache associations rebuild per term (~7–9s each when
     * the taxonomy is attached to a collection); batching collapses that cost.
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle (e.g. 'people_groups')
     * @param  string  $termSlug  The full (pre-site-prefix) hierarchical term slug
     * @param  string  $site  The Statamic site handle
     * @param  ?string  $termName  Optional term name; the batch pass falls back to the slug when empty
     */
    public static function queueTaxonomyTerm(
        string $statamicTaxonomyHandle,
        string $termSlug,
        string $site,
        ?string $termName = null
    ): void {
        self::$deferredTermQueue[$statamicTaxonomyHandle][$site][$termSlug] = [
            'original' => $termSlug,
            'name' => $termName ?? '',
        ];
    }

    /**
     * Queue a taxonomy term resolved from a term map for batched creation.
     *
     * Takes the full slug, then the term slug, then the key, and the named field,
     * then the name, then nothing, and enqueues via {@see self::queueTaxonomyTerm()}.
     * Missing keys are skipped, since callers guard membership before calling.
     *
     * @param  string  $termKey  The term key (slug or ID) to look up in the term map
     * @param  array<int|string, mixed>  $termMap  Map of term keys to term data
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  string  $site  The Statamic site handle
     * @param  string  $nameField  The field name to use for term name extraction (default: 'term_name')
     */
    public static function queueTaxonomyTermFromMap(
        string $termKey,
        array $termMap,
        string $statamicTaxonomyHandle,
        string $site,
        string $nameField = 'term_name'
    ): void {
        // Extract the full hierarchical slug from the term map, skipping silently
        // when the key is absent; on-demand callers already verify membership
        $termMapEntry = $termMap[$termKey] ?? null;
        if (! \is_array($termMapEntry)) {
            return;
        }

        $fullSlug = isset($termMapEntry['full_slug']) && \is_string($termMapEntry['full_slug'])
            ? $termMapEntry['full_slug']
            : (
                isset($termMapEntry['term_slug']) && \is_string($termMapEntry['term_slug'])
                    ? $termMapEntry['term_slug']
                    : $termKey
            );

        // Extract the term name; leave empty when absent so the batch pass derives
        // it from the slug, matching the immediate path's fallback.
        $termName = isset($termMapEntry[$nameField]) && \is_string($termMapEntry[$nameField])
            ? $termMapEntry[$nameField]
            : (
                isset($termMapEntry['name']) && \is_string($termMapEntry['name'])
                    ? $termMapEntry['name']
                    : ''
            );

        self::queueTaxonomyTerm(
            $statamicTaxonomyHandle,
            $fullSlug,
            $site,
            $termName
        );
    }

    /**
     * Create every queued taxonomy term for a site, one batched pass per taxonomy.
     *
     * Drains {@see self::$deferredTermQueue} for the given site and routes each
     * taxonomy's terms through {@see self::createTaxonomyTermsFromStoredData()},
     * which preloads existing terms once and saves with Stache index updates
     * paused. Respects this creator's configured taxonomy overwrite behavior, so
     * deferring does not change create/skip/overwrite semantics. Called by the
     * orchestrator after the content loop and before taxonomy validation.
     *
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function flushDeferredTaxonomyTerms(
        string $site,
        OutputInterface $output
    ): void {
        foreach (\array_keys(self::$deferredTermQueue) as $statamicTaxonomyHandle) {
            $queued = self::$deferredTermQueue[$statamicTaxonomyHandle][$site] ?? [];

            // Nothing queued for this taxonomy/site combination.
            if ($queued === []) {
                continue;
            }

            // Create the whole taxonomy's queued terms in a single batched pass. The
            // pass reads each position only to pace its memory collection, so it
            // creates the same terms from the keyed queue as from a list
            // @pest-mutate-ignore: UnwrapArrayValues
            $this->createTaxonomyTermsFromStoredData(
                \array_values($queued),
                $statamicTaxonomyHandle,
                $site,
                $output
            );

            // Drop the drained entries so a later flush (another content type in
            // the same run) does not reprocess them.
            unset(self::$deferredTermQueue[$statamicTaxonomyHandle][$site]);
        }
    }

    /**
     * Clear the deferred taxonomy term queue (import lifecycle reset / tests).
     */
    public static function clearDeferredTermQueue(): void
    {
        self::$deferredTermQueue = [];
    }

    /**
     * Gets site name from cache or Statamic facade.
     *
     * @param  string  $site  The site handle
     * @return string The site name
     */
    private static function getCachedSiteName(
        string $site
    ): string {
        if (! isset(self::$siteNamesCache[$site])) {
            $siteModel = SiteFacade::get($site);
            $siteName = \is_object($siteModel) && \method_exists($siteModel, 'name')
                ? $siteModel->name()
                : null;
            self::$siteNamesCache[$site] = \is_string($siteName) && $siteName !== ''
                ? $siteName
                : \ucwords(
                    \str_replace(
                        '_',
                        ' ',
                        $site
                    )
                );
        }

        return self::$siteNamesCache[$site];
    }

    /**
     * Read a string field from a term's default locale.
     *
     * @param  Term  $term  The term to read from
     * @param  string  $field  The field handle to read
     * @return string The field value, or an empty string when unavailable
     */
    private static function getTermStringValue(
        Term $term,
        string $field
    ): string {
        // Resolve the default-locale term representation.
        $localizedTerm = $term->inDefaultLocale();

        // Return an empty string when the localized term cannot be read.
        if (
            ! \is_object($localizedTerm)
            || ! \method_exists(
                $localizedTerm,
                'get'
            )
        ) {
            return '';
        }

        // Read the requested field value.
        $value = $localizedTerm->get($field);

        return \is_string($value)
            ? $value
            : '';
    }

    /**
     * Extract an object identifier from term-like values.
     *
     * @param  mixed  $candidate  Candidate object with an id() accessor
     * @return string|null The string ID, or null when unavailable
     */
    private static function extractObjectId(mixed $candidate): ?string
    {
        // A value that is not an object, or whose id() this scope cannot call (a
        // private or protected one), has no id
        if (
            ! \is_callable(
                [
                    $candidate,
                    'id',
                ]
            )
        ) {
            return null;
        }

        $id = \call_user_func(
            [
                $candidate,
                'id',
            ]
        );

        return \is_string($id) && $id !== ''
            ? $id
            : null;
    }

    /**
     * Clears the taxonomy term caches for a new import session.
     *
     * The name undersells it: the two *entry-id* resolvers reset here — the one
     * that maps a category slug to a `post_categories` entry, and the one that
     * maps a WordPress post id to a `posts` entry — are not taxonomy caches, but
     * they have exactly this lifetime, and this is the one call every caller
     * already makes at an import boundary.
     *
     * {@see TermDestinationRegistry} and {@see StructuredTermImporter} are reset
     * with them, and must be. All three hold state the term pass produces, and
     * `$processedTaxonomyImports` records that the pass already ran. Clearing one
     * without the others leaves the pass free to run again while the previous
     * session's output survives: a consumer resolving a term id gets the earlier
     * session's entry id, and `saveTree()` publishes a collection tree still
     * carrying the earlier session's nodes. Inert in production, where one command
     * run imports one export and the process exits — live in the host-app suite,
     * where many imports share a process (wordpress-importer-archive#422).
     */
    public static function clearTaxonomyCaches(): void
    {
        self::$siteNamesCache = [];
        self::$processedTermsCache = [];
        self::$processedTaxonomyImports = [];
        self::$reportedUnmappedTaxonomies = [];

        TermDestinationRegistry::clear();
        StructuredTermImporter::reset();
        PostCategoryEntryResolver::clear();
        NewsArticleResolver::clearCache();
    }

    /**
     * Report taxonomies the export carries that have no Statamic destination.
     *
     * Reached two ways, which are mutually exclusive for a given handle: the
     * handle filter counts terms whose taxonomy no caller listed, and the
     * per-taxonomy loop catches one a caller did list. Both funnel here so the
     * wording and the once-per-site guard live in one place.
     *
     * @param  array<string, int>  $unmappedTermCounts  Discarded term counts keyed by WordPress taxonomy handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function reportUnmappedTaxonomies(
        array $unmappedTermCounts,
        string $site,
        OutputInterface $output
    ): void {
        foreach ($unmappedTermCounts as $taxonomy => $discardedTermCount) {
            $reportKey = $site.'::'.$taxonomy;

            // Every creator sees the same export; report each discard once.
            if (isset(self::$reportedUnmappedTaxonomies[$reportKey])) {
                continue;
            }

            self::$reportedUnmappedTaxonomies[$reportKey] = $reportKey;

            $output->writeln(
                "  - <comment>Skipping '{$taxonomy}': recorded as having no "
                ."Statamic destination. {$discardedTermCount} term(s) not imported.</comment>"
            );

            ImportWarningManager::addWarning(
                'content',
                'taxonomy_deliberately_unmapped',
                [
                    'taxonomy' => $taxonomy,
                    'term_count' => $discardedTermCount,
                    'site' => $site,
                ]
            );
        }
    }
}
