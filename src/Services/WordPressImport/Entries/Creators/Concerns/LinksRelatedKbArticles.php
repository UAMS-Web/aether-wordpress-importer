<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Statamic\Sites\Site;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryFieldUpdater;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryFinder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Resolves a knowledge base article's related articles once every article exists.
 *
 * Part of {@see KbEntryCreator},
 * split out so that each file's mutation run fits the CI limit (#553). The methods run
 * on the creator and use its properties.
 */
trait LinksRelatedKbArticles
{
    /**
     * Get related articles from postmeta.
     *
     * This method extracts related article data from the article's
     * postmeta. It looks for the _mkb_related_articles meta key and
     * unserializes the stored data to get related article information.
     * The actual resolution of WordPress post IDs to Statamic entry IDs
     * is deferred until after all articles are imported.
     *
     * @param  SimpleXMLElement  $item  The wp:post element
     * @param  string  $entryId  The Statamic entry ID for this article
     * @param  ?string  $entrySlug  The entry slug for proper lookup
     * @param  ?Carbon  $entryDate  The entry date for proper lookup
     */
    protected function getRelatedArticles(
        SimpleXMLElement $item,
        string $entryId,
        ?string $entrySlug = null,
        ?Carbon $entryDate = null
    ): void {
        // Initialize an array to hold related article WordPress post IDs
        $relatedArticleIds = [];
        // Extract all postmeta elements from the article XML
        $postmeta = XpathHelper::xpathElements($item, 'wp:postmeta');

        // Search for related articles metadata
        foreach ($postmeta as $meta) {
            // Extract the meta key from the current postmeta element
            $metaKey = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // Check if the meta key indicates related articles metadata
            if ($metaKey === '_mkb_related_articles') {
                // Extract the meta value, which should be a serialized array of related post IDs
                $metaValue = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_value'
                );

                // Attempt to unserialize the meta value
                $unserialized = @\unserialize($metaValue, ['allowed_classes' => false]);

                // If unserialization is successful and yields an array, process related articles
                if (\is_array($unserialized)) {
                    $normalizedPostIds = [];
                    foreach ($unserialized as $unserializedPostId) {
                        $normalizedPostId = self::mixedToString(
                            $unserializedPostId,
                            ''
                        );
                        if ($normalizedPostId === '') {
                            continue;
                        }

                        $normalizedPostIds[] = $normalizedPostId;
                    }

                    // Store the WordPress post IDs for deferred processing
                    $relatedArticleIds = $normalizedPostIds;

                    // Store this entry's related articles data for later processing
                    $this->deferredRelatedArticles[$entryId] = [
                        'wp_post_ids' => $normalizedPostIds,
                        'slug' => $entrySlug,
                        'date' => $entryDate,
                    ];
                }

                // Exit the loop after finding and processing the related articles metadata
                break;
            }
        }

        $this->deferredRelatedArticles[$entryId] ??= [
            'wp_post_ids' => $relatedArticleIds,
            'slug' => $entrySlug,
            'date' => $entryDate,
        ];
    }

    /**
     * Process deferred related articles after all articles are imported.
     *
     * This method resolves WordPress post IDs to Statamic entry IDs for
     * related articles and updates the entries with the resolved data.
     * It is called after all articles have been imported to ensure that
     * all entry IDs are available for cross-referencing.
     *
     * The method performs the following operations:
     *   - Iterates through all deferred related articles data
     *   - Resolves WordPress post IDs to Statamic entry IDs using the article ID map
     *   - Uses forced fresh cache lookup to ensure correct entry retrieval with
     *     original dates
     *   - Updates each entry's related_articles field with resolved entry IDs
     *   - Tracks and reports on successful updates and unmapped references
     *   - Provides detailed logging for monitoring and debugging
     *
     * This deferred processing approach ensures that all articles exist
     * before attempting to establish relationships between them, preventing
     * broken references in the knowledge base structure.
     *
     * @param  OutputInterface  $output  Console output interface for user feedback
     * @param  string  $site  Selected site handle for context and logging
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     */
    protected function processDeferredRelatedArticles(
        OutputInterface $output,
        string $site,
        string $collectionHandle
    ): void {
        // Early return if no deferred related articles to process
        if ($this->deferredRelatedArticles === []) {
            return;
        }

        // Display progress message to user and log operation start
        $output->writeln('Processing deferred related articles...'); // Inform user

        // Initialize counters for tracking processing results
        $processedCount = 0; // Number of entries successfully updated
        $unmappedCount = 0; // Number of related articles that could not be resolved

        // Iterate over each entry that has deferred related articles
        foreach (ImportProgress::track($output, 'kb related articles', $this->deferredRelatedArticles) as $entryId => $relatedArticlesData) {
            // ---
            // Use EntryFinder to get the correct entry with proper path handling. Force
            // fresh cache lookup to ensure we get the correct entry with original date;
            // a cached copy of an entry this import just saved is the same entry.
            // ---
            // @pest-mutate-ignore: TrueToFalse
            $entry = EntryFinder::findEntryById(
                $collectionHandle,
                $entryId,
                true,
                $site
            );

            if (! $entry instanceof Entry) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'entry_id' => $entryId,
                        'site' => $site,
                    ]
                );

                // Log a warning if the entry could not be found and skip to the next
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "KbEntryCreator::processDeferredRelatedArticles: Entry with ID '{$entryId}' not found when processing deferred related articles for site {$site}.",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Skip this entry if not found
                continue;
            }

            // Initialize arrays to track resolved and unmapped article references
            $resolvedRelatedArticles = []; // Statamic entry IDs that were successfully resolved
            $unmappedArticles = []; // WordPress post IDs that could not be resolved

            // Resolve each WordPress post ID to its corresponding Statamic entry ID
            $wpPostIds = $relatedArticlesData['wp_post_ids'];
            foreach ($wpPostIds as $wpPostId) {
                // Check if the WordPress post ID has a corresponding Statamic entry ID
                if (isset($this->articleIdMap[$wpPostId])) {
                    // Add the resolved Statamic entry ID to the related articles array
                    $resolvedRelatedArticles[] = $this->articleIdMap[$wpPostId];
                } else {
                    // Track unmapped references for reporting and debugging
                    $unmappedArticles[] = $wpPostId;

                    // Increment unmapped count
                    $unmappedCount++;
                }
            }

            // Update the entry with resolved related articles if any were found
            if ($resolvedRelatedArticles !== []) {
                // Use the EntryFieldUpdater utility to update the field
                $success = EntryFieldUpdater::updateEntryFields(
                    $entry,
                    ['related_articles' => $resolvedRelatedArticles]
                );

                // ---
                // `updateEntryFields()` returns the save's own boolean result when it
                // saves, and the updated `Entry` only when `$save` is false; either way
                // `false` means the update did not land.
                // This call takes the saving default, so testing `instanceof Entry`
                // never matched — every SUCCESSFUL update logged an error and left
                // `$processedCount` at zero, so the console reported "Processed 0
                // entries with related articles" on a run that updated them all.
                // Test the failure sentinel instead, which is correct for both
                // return shapes (wordpress-importer-archive#761).
                // ---
                if ($success !== false) {
                    // Increment processed count
                    $processedCount++;
                } else {
                    // Log an error if the update failed
                    $entryTitle = self::mixedToString(
                        $entry->get('title'),
                        ''
                    );
                    Log::error("KbEntryCreator::processDeferredRelatedArticles: Failed to update entry '{$entryTitle}' (ID: {$entryId}) with related articles for site {$site}.");
                }
            }

            // Log warnings for unmapped articles to help identify missing content
            if ($unmappedArticles !== []) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'entry_id' => $entryId,
                        'entry_title' => self::mixedToString(
                            $entry->get('title'),
                            ''
                        ),
                        'unmapped_articles' => $unmappedArticles,
                        'site' => $site,
                    ]
                );

                // Log warning for unmapped articles
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "KbEntryCreator::processDeferredRelatedArticles: Entry '".self::mixedToString(
                            $entry->get('title'),
                            ''
                        )."' (ID: {$entryId}) has unmapped related articles: ".\implode(
                            ', ',
                            $unmappedArticles
                        )." for site {$site}.",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Display final processing results to user
        $output->writeln("Processed {$processedCount} entries with related articles.");

        // Warn the user if any related article references could not be resolved
        if ($unmappedCount > 0) {
            $output->writeln("<comment>Warning: {$unmappedCount} related article references could not be resolved.</comment>");
        }
    }
}
