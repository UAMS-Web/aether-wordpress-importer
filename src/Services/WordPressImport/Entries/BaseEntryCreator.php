<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry;
use Statamic\Sites\Site;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns\CreatesTaxonomyTerms;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns\ImportsTaxonomyTerms;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns\MapsEntryStatusAndDates;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns\PersistsStoredTaxonomyTerms;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns\SavesEntries;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;

/**
 * Base class for creating Statamic entries from WordPress content.
 *
 * Provides common functionality for entry creation, such as slug and ID
 * generation, date handling, and saving entries with overwrite behavior options
 * ("Overwrite," "Skip," "Create an additional file"), adhering to DRY (Don't
 * Repeat Yourself) and SRP (Single Responsibility Principle) principles.
 * Subclasses implement type-specific mapping logic for content types like pages,
 * posts, or people.
 */
abstract class BaseEntryCreator
{
    use CreatesTaxonomyTerms;
    use ImportsTaxonomyTerms;
    use MapsEntryStatusAndDates;
    use PersistsStoredTaxonomyTerms;
    use SavesEntries;

    /**
     * Collected page data for tree building.
     *
     * @var array<int|string, mixed>
     */
    protected $pageData = [];

    /**
     * The overwrite behavior for existing entries, set by the user.
     *
     * @var string
     */
    protected $entryOverwriteBehavior = 'Overwrite matching files';

    /**
     * The overwrite behavior for existing taxonomy terms, set by the user.
     *
     * @var string
     */
    protected $taxonomyOverwriteBehavior = 'Overwrite matching terms';

    /**
     * @var array<int|string, mixed> Term map built by TermMapBuilder for efficient term lookups. Maps term IDs to term data including hierarchical slugs.
     */
    protected $termMap = [];

    /**
     * @var array<int|string, mixed> Map of category nicenames to term IDs for efficient ID-based lookups. Structure: ['taxonomy' => ['nicename' => 'term_id']]
     */
    protected $nicenameToTermIdMap = [];

    /**
     * Create entries for a specific WordPress content type.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  Console output interface
     * @param  MetadataMapper  $metadataMapper  Metadata mapper instance
     * @param  ContentProcessor  $contentProcessor  Content parser instance
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array<int|string, mixed> The page data for tree building
     */
    abstract public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor,
        ?array $minervaKbSettings = null
    ): array;

    /**
     * Check if the content type uses ShowPeople shortcodes.
     */
    abstract public function hasShowPeople(): bool;

    /**
     * Get post categories for validation (if applicable).
     *
     * @return array<int|string, mixed>
     */
    abstract public function getPostCategories(): array;

    /**
     * Get people groups for validation (if applicable).
     *
     * @return array<int|string, mixed>
     */
    public function getPeopleGroups(): array
    {
        return [];
    }

    /**
     * Get post tags for validation (if applicable).
     *
     * @return array<int|string, mixed>
     */
    abstract public function getPostTags(): array;

    /**
     * Validates taxonomy terms specific to this entry creator's content type.
     *
     * Each entry creator is responsible for validating its own taxonomy terms
     * (e.g., people_group for PeopleEntryCreator, post_category/post_tag for
     * PostEntryCreator). This method is called after entry creation to ensure
     * all referenced taxonomy terms exist in Statamic.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    abstract public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void;

    /**
     * Set the overwrite behavior for entry imports.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching files', 'Skip matching files', or
     *                            'Create an additional file'
     */
    public function setEntryOverwriteBehavior(
        string $behavior
    ): void {
        $this->entryOverwriteBehavior = $behavior;
    }

    /**
     * Set the overwrite behavior for taxonomy term imports.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching terms', 'Skip matching terms', or
     *                            'Create an additional term'
     */
    public function setTaxonomyOverwriteBehavior(
        string $behavior
    ): void {
        $this->taxonomyOverwriteBehavior = $behavior;
    }

    /**
     * Cache for site names to avoid repeated Statamic facade calls.
     *
     * @var array<string, string>
     */
    private static array $siteNamesCache = [];

    /**
     * Cache for processed taxonomy terms to avoid duplicate operations.
     *
     * @var array<string, bool>
     */
    private static array $processedTermsCache = [];

    /**
     * Cache for processed taxonomy imports to avoid duplicate processing.
     *
     * @var array<string, bool>
     */
    private static array $processedTaxonomyImports = [];

    /**
     * Unmapped taxonomies already reported, keyed "{site}::{taxonomy}".
     *
     * The term pass runs once per entry creator, and every creator sees the same
     * export, so without this the same discard is reported once per creator.
     *
     * @var array<string, string>
     */
    private static array $reportedUnmappedTaxonomies = [];

    /**
     * On-demand taxonomy terms queued during content processing for a single
     * batched creation pass per taxonomy.
     *
     * Structure: [statamicTaxonomyHandle][site][slug] => ['original' => slug,
     * 'name' => name]. Keyed by slug so repeated references to the same term
     * (across pages/shortcodes) dedupe in memory. The value shape is exactly what
     * {@see self::createTaxonomyTermsFromStoredData()} consumes.
     *
     * @var array<string, array<string, array<string, array{original: string, name: string}>>>
     */
    private static array $deferredTermQueue = [];

    /**
     * Create a typed entry query builder.
     */
    private function newEntryQuery(): EntryQueryBuilder
    {
        return EntryQueryHelper::query();
    }

    /**
     * Add page data for tree building.
     *
     * Stores metadata for an entry, including its Statamic entry ID, parent post
     * ID, menu order, title, and optional link URL, used for constructing
     * collection trees and front-page detection during import.
     *
     * @param  string  $postId  The WordPress post ID
     * @param  string  $id  The Statamic entry ID
     * @param  string  $parentId  The parent post ID
     * @param  int  $menuOrder  The menu order
     * @param  string  $title  The entry title
     * @param  ?string  $link  The page's <link> URL (optional, for front-page detection)
     * @param  ?Entry  $entry  The entry object (optional, for avoiding re-queries)
     */
    protected function addPageData(
        string $postId,
        string $id,
        string $parentId,
        int $menuOrder,
        string $title,
        ?string $link = null,
        ?Entry $entry = null
    ): void {
        // ---
        // Use the actual entry ID from the entry object if available, otherwise fall back
        // to the provided ID. This ensures we use the correct ID for existing entries
        // that may have different random suffixes than the generated ID.
        // ---
        $actualEntryId = $entry instanceof Entry ? $entry->id() : $id;

        $this->pageData[$postId] = [
            'entry' => $actualEntryId,
            'parent' => $parentId,
            'menu_order' => $menuOrder,
            'title' => $title,
        ];
        if ($link !== null) {
            $this->pageData[$postId]['link'] = $link;
        }

        if ($entry instanceof Entry) {
            $this->pageData[$postId]['entry_object'] = $entry;
        }
    }

    /**
     * Check if content is empty or the default empty message.
     *
     * @param  array<int|string, mixed>  $content  The parsed content
     * @return bool True if empty or default message, false otherwise
     */
    protected function isEmptyContent(
        array $content
    ): bool {
        return $content === [] || $content === [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Imported from WordPress XML. Content empty.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get the collected page data.
     *
     * @return array<int|string, mixed> The page data array
     */
    public function getPageData(): array
    {
        return $this->pageData;
    }
}
