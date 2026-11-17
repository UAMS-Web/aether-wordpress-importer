<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns;

use SimpleXMLElement;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Imports an export's categories and tags as taxonomy terms before its entries, and builds the term map.
 *
 * Split out of {@see BaseEntryCreator} so that each file's mutation run fits
 * the CI limit (#550). The methods run on the creator and use its properties
 * and static caches, which stay declared on that class.
 */
trait ImportsTaxonomyTerms
{
    /**
     * Converts a WXR `<wp:category>` element into the `<wp:term>` shape.
     *
     * WordPress serializes the built-in `category` taxonomy as `<wp:category>` with
     * `<wp:category_nicename>`, not as the `<wp:term>` shape every other taxonomy uses.
     * `XmlParser::getTerms()` matches only `term`, so categories are invisible to it —
     * in the news export that is 136 `<wp:category>` elements against 6 `<wp:term>`.
     *
     * **`<wp:category_parent>` has to travel with it.** It holds the parent's
     * *nicename*, which is exactly the shape `<wp:term_parent>` carries and the
     * shape {@see StructuredTermImporter} resolves back to a term id when it
     * builds the collection tree. Dropping it made every category import as a
     * root: the tree came out flat and `stampParents()` had no edge to stamp, so
     * a nested category's `:parent:is` child query returned nothing — the exact
     * behavior wordpress-importer-archive#422 requires. Measured on the 259-export corpus: 19 exports carry
     * 146 nested categories, and 16 of those 19 declare categories ONLY as
     * `<wp:category>`, so for them the hierarchy was lost outright.
     *
     * @param  SimpleXMLElement  $category  A `<wp:category>` element
     * @return SimpleXMLElement The equivalent pseudo `<wp:term>` element
     */
    protected static function categoryToPseudoTerm(
        SimpleXMLElement $category
    ): SimpleXMLElement {
        // Build the term element with the WordPress export namespace declared
        $pseudoTerm = new SimpleXMLElement('<wp:term xmlns:wp="http://wordpress.org/export/1.2/"></wp:term>');

        // Carry the term id across unchanged
        $pseudoTerm->addChild(
            'wp:term_id',
            XpathHelper::getStringValue(
                $category,
                'wp:term_id'
            )
        );

        // Categories are, by definition, the `category` taxonomy
        $pseudoTerm->addChild(
            'wp:term_taxonomy',
            'category'
        );

        // The slug lives under a category-specific element name
        $pseudoTerm->addChild(
            'wp:term_slug',
            XpathHelper::getStringValue(
                $category,
                'wp:category_nicename'
            )
        );

        // The display name likewise
        $pseudoTerm->addChild(
            'wp:term_name',
            XpathHelper::getStringValue(
                $category,
                'wp:cat_name'
            )
        );

        // ---
        // The parent edge, under yet another category-specific element name. Both
        // sides hold a nicename, so this is a straight carry-over; a top-level
        // category yields an empty string, which the tree builder reads as "no
        // parent" rather than as a parent it could not find.
        // ---
        $pseudoTerm->addChild(
            'wp:term_parent',
            XpathHelper::getStringValue(
                $category,
                'wp:category_parent'
            )
        );

        return $pseudoTerm;
    }

    /**
     * Returns every `<wp:category>` in the export as a pseudo `<wp:term>`.
     *
     * Lets the term maps see built-in categories. Without this,
     * `nicenameToTermIdMap['category']` is empty on every real export, silently
     * disabling anything that resolves a category by term id (wordpress-importer-archive#441).
     *
     * @param  XmlParser  $xmlParser  Parser for the WordPress export
     * @return list<SimpleXMLElement> Category elements in the `<wp:term>` shape
     */
    protected static function categoryPseudoTerms(
        XmlParser $xmlParser
    ): array {
        $pseudoTerms = [];

        // Convert each category element, skipping anything not parseable
        foreach ($xmlParser->getCategories() as $category) {
            // Guard against a malformed entry in the parsed list
            if (! $category instanceof SimpleXMLElement) {
                continue;
            }

            $pseudoTerms[] = self::categoryToPseudoTerm($category);
        }

        return $pseudoTerms;
    }

    /**
     * Converts a WXR `<wp:tag>` element into the `<wp:term>` shape.
     *
     * WordPress serializes the built-in `post_tag` taxonomy as `<wp:tag>` with
     * `<wp:tag_slug>` and `<wp:tag_name>`, the tag-side counterpart of
     * {@see self::categoryToPseudoTerm()}. Tags are flat, so there is no parent
     * element to carry.
     *
     * @param  SimpleXMLElement  $tag  A `<wp:tag>` element
     * @return SimpleXMLElement The equivalent pseudo `<wp:term>` element
     */
    protected static function tagToPseudoTerm(
        SimpleXMLElement $tag
    ): SimpleXMLElement {
        // Build the term element with the WordPress export namespace declared
        $pseudoTerm = new SimpleXMLElement('<wp:term xmlns:wp="http://wordpress.org/export/1.2/"></wp:term>');

        // Carry the term id across unchanged
        $pseudoTerm->addChild(
            'wp:term_id',
            XpathHelper::getStringValue(
                $tag,
                'wp:term_id'
            )
        );

        // Tags are, by definition, the `post_tag` taxonomy
        $pseudoTerm->addChild(
            'wp:term_taxonomy',
            'post_tag'
        );

        // The slug lives under a tag-specific element name
        $pseudoTerm->addChild(
            'wp:term_slug',
            XpathHelper::getStringValue(
                $tag,
                'wp:tag_slug'
            )
        );

        // The display name likewise
        $pseudoTerm->addChild(
            'wp:term_name',
            XpathHelper::getStringValue(
                $tag,
                'wp:tag_name'
            )
        );

        return $pseudoTerm;
    }

    /**
     * Returns every `<wp:tag>` in the export as a pseudo `<wp:term>`.
     *
     * Lets the term maps see built-in tags, as {@see self::categoryPseudoTerms()}
     * does for categories. A post names its tags by nicename, and a tag's
     * destination is recorded by term id, so without these the nicename has no
     * term id to resolve through and every tag reference is lost (wordpress-importer-archive#1220).
     *
     * @param  XmlParser  $xmlParser  Parser for the WordPress export
     * @return list<SimpleXMLElement> Tag elements in the `<wp:term>` shape
     */
    protected static function tagPseudoTerms(
        XmlParser $xmlParser
    ): array {
        $pseudoTerms = [];

        // Convert each tag element, skipping anything not parseable
        foreach ($xmlParser->getTags() as $tag) {
            // Guard against a malformed entry in the parsed list
            if (! $tag instanceof SimpleXMLElement) {
                continue;
            }

            $pseudoTerms[] = self::tagToPseudoTerm($tag);
        }

        return $pseudoTerms;
    }

    /**
     * Initialize terms for the entry creator with consistent processing.
     *
     * This method provides a standardized approach to term handling across all
     * entry creators. It:
     *   1. Imports taxonomy terms at the beginning of the import process
     *   2. Gets all terms from the XML parser
     *   3. Builds a term map using TermMapBuilder for efficient lookups
     *   4. Stores the term map in the class property for use throughout processing
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  array<int|string, mixed>  $wordpressTaxonomyHandles  Array of WordPress source taxonomy handles to import terms for
     *                                                              (e.g., ['category', 'post_tag', 'uamspeople-categories'])
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function initializeTerms(
        XmlParser $xmlParser,
        array $wordpressTaxonomyHandles,
        string $site,
        OutputInterface $output
    ): void {
        // Import taxonomy terms at the beginning of the import process
        $this->importTaxonomyTermsAtBeginning(
            $xmlParser,
            $wordpressTaxonomyHandles,
            $site,
            $output
        );

        // ---
        // Get all terms for term map building. Built-in categories arrive as
        // `<wp:category>` rather than `<wp:term>`, so `getTerms()` never returns them —
        // they are converted and appended here, otherwise every category lookup by term
        // id misses on every real export (wordpress-importer-archive#441). Built-in tags arrive as `<wp:tag>`
        // for the same reason and are appended alongside (wordpress-importer-archive#1220).
        // ---
        $terms = [
            ...$xmlParser->getTerms(),
            ...self::categoryPseudoTerms($xmlParser),
            ...self::tagPseudoTerms($xmlParser),
        ];

        // Build term map using TermMapBuilder for efficient term lookups
        $this->termMap = TermMapBuilder::buildTermMap(
            $terms,
            $wordpressTaxonomyHandles
        );

        // Build nicename to term ID mapping for efficient ID-based lookups
        $this->nicenameToTermIdMap = TermMapBuilder::buildNicenameToTermIdMap(
            $terms,
            $wordpressTaxonomyHandles
        );

        // Clean up variables to free up memory
        unset(
            $terms
        );
    }

    /**
     * Import taxonomy terms at the beginning of the import process.
     *
     * This method processes all terms from the XML export for the specified
     * WordPress taxonomies and creates/updates them in Statamic before processing
     * entries. This ensures that taxonomy terms are available when entries are
     * created.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  array<int|string, mixed>  $wordpressTaxonomyHandles  Array of WordPress source taxonomy handles to import terms for
     *                                                              (e.g., ['category', 'post_tag', 'uamspeople-categories'])
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function importTaxonomyTermsAtBeginning(
        XmlParser $xmlParser,
        array $wordpressTaxonomyHandles,
        string $site,
        OutputInterface $output
    ): void {
        // Check if taxonomies array is empty to avoid unnecessary processing
        if ($wordpressTaxonomyHandles === []) {
            // Exit early if no taxonomies to process
            return;
        }

        // Create a copy of the taxonomy handles array for sorting
        $sortedTaxonomies = $wordpressTaxonomyHandles;

        // Sort the taxonomy handles alphabetically to ensure consistent cache keys
        sort($sortedTaxonomies);
        $sortedTaxonomies = \array_filter(
            $sortedTaxonomies,
            \is_string(...)
        );

        // Generate a unique cache key combining site and sorted taxonomy handles
        $cacheKey = $site.'::'.\implode(
            ',',
            $sortedTaxonomies
        );

        // Check if we've already processed these taxonomies for this site
        if (isset(self::$processedTaxonomyImports[$cacheKey])) {
            // Skip processing if already done
            return;
        }

        // Mark this taxonomy import as processed. Only isset() reads it, which takes
        // false as set as well
        // @pest-mutate-ignore: TrueToFalse
        self::$processedTaxonomyImports[$cacheKey] = true;

        // Display progress message to inform user of current operation
        $output->writeln('Importing taxonomy terms at beginning of import process...');

        // Get all terms from the XML export
        $terms = $xmlParser->getTerms();

        // Initialize array to group terms by taxonomy type. Its keys are taxonomy
        // names, which PHP stores as ints when numeric, hence the is_string() guard
        // below; addTermToTaxonomyGroup() fills it by reference.
        /** @var array<int|string, mixed> $termsByTaxonomy */
        $termsByTaxonomy = [];

        // Initialize array to track seen term IDs and prevent duplicates
        $seenTermIds = [];

        // Tally terms belonging to taxonomies recorded as having no destination.
        // These never enter $termsByTaxonomy — no caller lists them — so they are
        // counted here, where every term in the export is still visible.
        $unmappedTermCounts = [];

        // Loop through all terms from the XML export to categorize them
        foreach ($terms as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the taxonomy type from the current term
            $termTaxonomy = XpathHelper::getStringValue(
                $term,
                'wp:term_taxonomy'
            );

            // Check if this term belongs to one of the taxonomies we need to process
            if (
                \in_array(
                    $termTaxonomy,
                    $wordpressTaxonomyHandles,
                    true
                )
            ) {
                // Add term to taxonomy group with deduplication
                $this->addTermToTaxonomyGroup(
                    $termsByTaxonomy,
                    $seenTermIds,
                    $termTaxonomy,
                    $term
                );
            } elseif (TaxonomyDispositions::isUnmapped($termTaxonomy)) {
                // ---
                // Recorded as having no Statamic destination. No caller lists
                // these handles, so the term is dropped by the filter above and
                // never reaches the per-taxonomy loop below. Count it here — the
                // last point at which every term in the export is still visible
                // — so the discard can be reported rather than being silent.
                // ---
                $unmappedTermCounts[$termTaxonomy] =
                    ($unmappedTermCounts[$termTaxonomy] ?? 0) + 1;
            }
        }

        // The loop variable outlives the loop; release it before it is reused
        unset($term);

        // Check if category taxonomy is in the list of taxonomies to process
        if (
            \in_array(
                'category',
                $wordpressTaxonomyHandles,
                true
            )
        ) {
            // Get all wp:category elements from the XML parser
            $categories = $xmlParser->getCategories();

            $termsByTaxonomy['category'] ??= [];

            // Loop through each wp:category element to convert to wp:term format
            foreach ($categories as $category) {
                if (! $category instanceof SimpleXMLElement) {
                    continue;
                }

                // Convert the category element into the wp:term shape
                $pseudoTerm = self::categoryToPseudoTerm($category);

                // Add converted pseudo-term to taxonomy group with deduplication
                $this->addTermToTaxonomyGroup(
                    $termsByTaxonomy,
                    $seenTermIds,
                    'category',
                    $pseudoTerm
                );
            }
        }

        // Check if post_tag taxonomy is in the list of taxonomies to process
        if (
            \in_array(
                'post_tag',
                $wordpressTaxonomyHandles,
                true
            )
        ) {
            // Get all wp:tag elements from the XML parser
            $tags = $xmlParser->getTags();

            $termsByTaxonomy['post_tag'] ??= [];

            // Loop through each wp:tag element to convert to wp:term format
            foreach ($tags as $tag) {
                if (! $tag instanceof SimpleXMLElement) {
                    continue;
                }

                // Convert the tag element into the wp:term shape
                $pseudoTerm = self::tagToPseudoTerm($tag);

                // Add converted pseudo-term to taxonomy group with deduplication
                $this->addTermToTaxonomyGroup(
                    $termsByTaxonomy,
                    $seenTermIds,
                    'post_tag',
                    $pseudoTerm
                );
            }
        }

        // Report anything the export carries that is recorded as unimportable.
        $this->reportUnmappedTaxonomies($unmappedTermCounts, $site, $output);

        // Loop through each taxonomy group to process terms
        foreach ($termsByTaxonomy as $taxonomy => $taxonomyTerms) {
            if (! \is_string($taxonomy) || ! \is_array($taxonomyTerms)) {
                continue;
            }

            // Check if there are any terms to process for this taxonomy
            if ($taxonomyTerms === []) {
                // Display message that there are no terms to process for this taxonomy
                $output->writeln("  - No '{$taxonomy}' taxonomy terms to process.");

                // Continue to the next taxonomy
                continue;
            }

            // ---
            // A deliberately unmapped taxonomy has no Statamic destination by
            // decision rather than by omission. Left to fall through, its terms
            // reach createTaxonomyTermsFromStoredData(), whose
            // TaxonomyFacade::find() guard can never resolve a taxonomy that
            // does not exist — so they vanish with nothing written and nothing
            // said. Report the loss instead, and skip the work.
            // ---
            if (TaxonomyDispositions::isUnmapped($taxonomy)) {
                $this->reportUnmappedTaxonomies(
                    [$taxonomy => \count($taxonomyTerms)],
                    $site,
                    $output
                );

                // Continue to the next taxonomy
                continue;
            }

            // Display progress message for current taxonomy
            $output->writeln("  - Processing '{$taxonomy}' taxonomy terms...");

            // Build hierarchical term map for this taxonomy
            $termMap = $this->buildHierarchicalTermMap($taxonomyTerms);

            // Initialize array to store processed term data for this taxonomy
            $siteSpecificData = [];

            // Loop through all terms in the current taxonomy group
            foreach ($taxonomyTerms as $term) {
                // Every term was grouped through addTermToTaxonomyGroup(), which takes
                // only elements, so the check below only narrows the type for phpstan
                // @pest-mutate-ignore: InstanceOfToTrue
                if (! $term instanceof SimpleXMLElement) {
                    // @pest-mutate-ignore: ContinueToBreak
                    continue;
                }

                // Extract the term slug from the XML element
                $termSlug = XpathHelper::getStringValue(
                    $term,
                    'wp:term_slug'
                );

                // Extract the term name from the XML element
                $termName = XpathHelper::getStringValue(
                    $term,
                    'wp:term_name'
                );

                // Check if the term has a valid slug before processing
                if ($termSlug !== '') {
                    // Get the full hierarchical slug from termMap or use the original slug as fallback
                    $mappedTerm = $termMap[$termSlug] ?? null;

                    // Every term with a slug is in the map, as an array with a string
                    // full slug, so the checks below only narrow the type for phpstan
                    // @pest-mutate-ignore: BooleanAndToBooleanOr
                    $fullSlug = \is_array($mappedTerm)
                        && isset($mappedTerm['full_slug'])
                        && \is_string($mappedTerm['full_slug'])
                        ? $mappedTerm['full_slug']
                        : $termSlug;

                    // Add term data to the processing array with hierarchical slug
                    $siteSpecificData[] = [
                        'original' => $fullSlug,
                        'name' => $termName,
                    ];
                }
            }

            // ---
            // Map the WordPress taxonomy to its Statamic destination handle. The
            // mapping table stays the single source of truth for the HANDLE; the
            // destination's SHAPE lives in TaxonomyDispositions, because
            // `UAMS-Web/uams-statamic-archive#1863` converted four of these destinations
            // into collections without changing their handles.
            // ---
            $statamicTaxonomyHandle = ImportWordPress::mapWordPressToStatamicHandle(
                $taxonomy,
                'taxonomy'
            ) ?? $taxonomy;

            // ---
            // A structured destination is a collection of entries carrying a
            // tree, not a flat taxonomy of terms. Terms written to one of those
            // handles went nowhere: createTaxonomyTermsFromStoredData() guards on
            // TaxonomyFacade::find(), which returns null for a collection, so the
            // hierarchy was silently dropped rather than reported.
            // ---
            if (TaxonomyDispositions::isStructured($taxonomy)) {
                $this->structuredTermImporter()->import(
                    $taxonomyTerms,
                    $taxonomy,
                    $statamicTaxonomyHandle,
                    $site,
                    $output
                );

                continue;
            }

            // Create taxonomy terms from the collected data
            $this->createTaxonomyTermsFromStoredData(
                $siteSpecificData,
                $statamicTaxonomyHandle,
                $site,
                $output
            );

            // Record where each flat term landed so anything downstream can
            // resolve a WordPress term id to its Statamic destination.
            self::registerFlatTermDestinations(
                $taxonomyTerms,
                $taxonomy,
                $statamicTaxonomyHandle,
                $termMap,
                $site
            );
        }

        // Clean up variables to free up memory
        unset(
            $terms,
            $termsByTaxonomy,
            $taxonomyTerms,
            $termMap,
            $siteSpecificData,
            $term,
            $termTaxonomy,
            $termSlug,
            $termName,
            $fullSlug,
            $sortedTaxonomies,
            $cacheKey,
            $seenTermIds
        );

        // Display completion message to inform user that import is finished
        $output->writeln('  - <info>Taxonomy terms import completed.</info>');
    }

    /**
     * Resolve the importer used for structured, collection-backed taxonomies.
     *
     * Resolved from the container rather than constructor-injected so the seven
     * existing entry-creator constructors keep their signatures; overridable so a
     * test can substitute a probe.
     *
     * @return StructuredTermImporter The structured term importer
     */
    protected function structuredTermImporter(): StructuredTermImporter
    {
        return app(StructuredTermImporter::class);
    }

    /**
     * Record the Statamic destination of every term written to a flat taxonomy.
     *
     * The slug recorded here is the one {@see self::createTaxonomyTermsFromStoredData()}
     * actually writes — the hierarchical full slug with the site prefix applied —
     * so a later lookup resolves to a term that exists rather than to the raw
     * WordPress slug.
     *
     * @param  array<int|string, mixed>  $taxonomyTerms  The `<wp:term>` elements for one taxonomy
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  array<int|string, mixed>  $termMap  Map of term slugs to full slugs and parents
     * @param  string  $site  The Statamic site handle
     */
    protected static function registerFlatTermDestinations(
        array $taxonomyTerms,
        string $wordpressTaxonomy,
        string $statamicTaxonomyHandle,
        array $termMap,
        string $site
    ): void {
        foreach ($taxonomyTerms as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term id, which is the key callers resolve against.
            $termId = XpathHelper::getStringValue(
                $term,
                'wp:term_id'
            );

            // Extract the term slug used to look up its hierarchical form.
            $termSlug = XpathHelper::getStringValue(
                $term,
                'wp:term_slug'
            );

            if ($termId === '' || $termSlug === '') {
                continue;
            }

            // Prefer the hierarchical full slug when one was computed for it.
            $mappedTerm = $termMap[$termSlug] ?? null;
            $mappedFullSlug = \is_array($mappedTerm) && isset($mappedTerm['full_slug'])
                ? $mappedTerm['full_slug']
                : null;
            $fullSlug = \is_string($mappedFullSlug) ? $mappedFullSlug : $termSlug;

            // Record the destination against the site-prefixed slug that was written.
            TermDestinationRegistry::registerTaxonomyTerm(
                $wordpressTaxonomy,
                $termId,
                $statamicTaxonomyHandle,
                TaxonomySlugProcessor::addSitePrefixToSlug(
                    $fullSlug,
                    $site
                )
            );
        }
    }

    /**
     * Build hierarchical term map for a given set of taxonomy terms.
     *
     * This method processes terms to build a map that includes hierarchical
     * relationships and full slug paths, similar to the logic used in
     * individual entry creators.
     *
     * @param  array<int|string, mixed>  $wordPressTaxonomyTermObjects  Array of WordPress XML taxonomy term elements
     * @return array<int|string, mixed> Map of term slugs to their full slugs and parent slugs
     */
    protected function buildHierarchicalTermMap(
        array $wordPressTaxonomyTermObjects
    ): array {
        // Initialize empty term map for storing processed terms. PHP stores an
        // all-digit slug as an integer key, so the keys are typed as either
        /** @var array<int|string, array{full_slug: string, parent: string}> $termMap */
        $termMap = [];

        // Loop through all terms to extract and process taxonomy data
        foreach ($wordPressTaxonomyTermObjects as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term slug from the XML term
            $slug = XpathHelper::getStringValue(
                $term,
                'wp:term_slug'
            );

            // Extract the parent term slug from the XML term
            $parent = XpathHelper::getStringValue(
                $term,
                'wp:term_parent'
            );

            // Check if the term has a valid slug before processing
            if ($slug !== '') {
                // Store the term data in the term map
                $termMap[$slug] = [
                    'full_slug' => $slug,
                    'parent' => $parent,
                ];
            }
        }

        // Loop through the term map to build full hierarchical slugs. An all-digit
        // slug comes back as an integer key, so it is read as the string it was (#303)
        foreach ($termMap as $slug => &$data) {
            // Calculate the full hierarchical slug for each term
            $data['full_slug'] = $this->getFullTermSlug(
                (string) $slug,
                $termMap
            );
        }

        // Clean up variables to free up memory
        unset(
            $data,
            $parent,
            $slug,
            $term
        );

        // Return the completed term map with hierarchical slugs
        return $termMap;
    }

    /**
     * Constructs the full slug path for a term, recursively prepending ancestor slugs.
     *
     * This method recursively builds hierarchical slugs by prepending parent
     * slugs to create full hierarchical paths (e.g., 'grandparent_parent_term').
     *
     * @param  string  $termSlug  The term slug to process
     * @param  array<int|string, mixed>  $termMap  Map of term slugs to their full slugs and parent slugs
     * @param  array<int|string, mixed>  $visited  Array to track visited term slugs to prevent infinite recursion
     * @return string The full slug path or term slug if no parent
     */
    protected function getFullTermSlug(
        string $termSlug,
        array $termMap,
        array &$visited = []
    ): string {
        // Check if the term slug is valid and exists in the term map
        if (
            $termSlug === ''
            || ! isset($termMap[$termSlug])
        ) {
            // Return the original slug if term is not found or empty
            return $termSlug;
        }

        // Get the term data from the term map
        $term = $termMap[$termSlug];
        if (! \is_array($term)) {
            return $termSlug;
        }

        // ---
        // Use the base slug (termSlug) instead of full_slug to avoid duplication. The
        // full_slug may already contain parent paths from previous iterations.
        // ---
        $baseSlug = $termSlug;

        // Check if we've already visited this term to prevent infinite recursion
        if (
            \in_array(
                $termSlug,
                $visited,
                true
            )
        ) {
            // ---
            // Return the base slug if we've already processed this term (to break circular
            // references)
            // ---
            return $baseSlug;
        }

        // Mark this term as visited to prevent circular references
        $visited[] = $termSlug;

        // Get the parent slug from the term data. A missing parent reads as an
        // empty one, and either ends at the base slug below.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $parentSlug = isset($term['parent']) && \is_string($term['parent'])
            ? $term['parent']
            : '';

        // Check if the term has a parent and the parent exists in the term map. The
        // full slug of an empty parent is empty, which ends at the base slug, and that
        // of any other parent never is, so neither empty-string comparison can change
        // the slug
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        if (
            $parentSlug !== ''
            && isset($termMap[$parentSlug])
        ) {
            // Recursively get the full slug for the parent term
            $ancestorSlug = $this->getFullTermSlug(
                $parentSlug,
                $termMap,
                $visited
            );

            // Combine the ancestor slug with the current base slug
            return $ancestorSlug !== '' ? "{$ancestorSlug}_{$baseSlug}" : $baseSlug;
        }

        // Return the base slug if no parent exists
        return $baseSlug;
    }

    /**
     * Adds a term to the taxonomy group with deduplication.
     *
     * Checks if the term ID has already been seen and skips adding if it's a
     * duplicate. Initializes the taxonomy array if it doesn't exist yet.
     *
     * @param  array<int|string, mixed>  $termsByTaxonomy  Reference to the terms by taxonomy array
     * @param  array<int|string, mixed>  $seenTermIds  Reference to the seen term IDs tracking array
     * @param  string  $taxonomy  The taxonomy type (e.g., 'category', 'post_tag')
     * @param  SimpleXMLElement  $term  The term element to add
     */
    private function addTermToTaxonomyGroup(
        array &$termsByTaxonomy,
        array &$seenTermIds,
        string $taxonomy,
        SimpleXMLElement $term
    ): void {
        // Extract the term ID to check for duplicates
        $termId = XpathHelper::getStringValue(
            $term,
            'wp:term_id'
        );

        // Check if this term ID has already been processed
        if (
            $termId !== ''
            && isset($seenTermIds[$termId])
        ) {
            // Skip adding this duplicate term
            return;
        }

        // Check if term ID exists before tracking it
        if ($termId !== '') {
            // Mark this term ID as seen to prevent future duplicates
            $seenTermIds[$termId] = true;
        }

        $termsByTaxonomy[$taxonomy] ??= [];

        // Add the term to the appropriate taxonomy group
        if (! \is_array($termsByTaxonomy[$taxonomy])) {
            $termsByTaxonomy[$taxonomy] = [];
        }

        $termsByTaxonomy[$taxonomy][] = $term;
    }
}
