<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Traits;

use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryFinder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Resolves the internal links `LinkProcessor` deferred, once every entry exists.
 *
 * Walks the deferred links of each entry, finds the entry each one points to, and
 * reports the links no entry resolves.
 *
 * @phpstan-import-type DeferredLinkData from LinkProcessor
 * @phpstan-import-type ResolvedDeferredLink from LinkProcessor
 */
trait LinkProcessorDeferredResolutionTrait
{
    /**
     * Processes all deferred internal links after all entries of a post type have
     * been imported.
     *
     * This method iterates through all entries that have deferred internal links
     * and attempts to resolve them using the entry ID map. It updates the entry
     * content with the resolved Statamic URLs. Deferred links persist across
     * post type imports to allow cross-post-type link resolution.
     *
     * @param  string  $statamicSiteHandle  The site handle for context
     * @param  OutputInterface  $output  Console output for user feedback
     * @param  bool  $isFinalCall  Whether this is the final call after all content types are imported
     */
    public static function processDeferredInternalLinks(
        string $statamicSiteHandle,
        OutputInterface $output,
        bool $isFinalCall = false
    ): void {
        // Check if there are any deferred links to process
        if (self::$deferredInternalLinks === []) {
            // Exit early if no deferred links exist
            return;
        }

        // Inform user that deferred link processing is starting
        $output->writeln('Processing deferred internal links...');
        $output->writeln('  - <comment>Resolving remaining cross-post-type deferred links...</comment>');

        // Initialize counter for successfully processed entries
        $processedCount = 0;

        // Initialize counter for links that could not be resolved
        $unmappedCount = 0;

        // Initialize array to collect unresolved link details
        $unresolvedLinks = [];

        // Process each entry's deferred internal links
        foreach (self::$deferredInternalLinks as $statamicSourceEntryId => $deferredLinks) {
            // An all-digit entry ID comes back as an integer key, so read it as the
            // string it was (#170)
            $statamicSourceEntryId = (string) $statamicSourceEntryId;

            // Get the source collection handle from the first deferred link
            // (all deferred links for an entry should have the same source collection)
            $statamicSourceCollectionHandle = $deferredLinks[0]['source_collection_handle'] ?? 'pages';

            // Find the entry that contains the deferred links with retry logic
            $statamicSourceEntry = self::findEntryWithRetry(
                fn (bool $forceFresh): ?Entry => EntryFinder::findEntryById(
                    $statamicSourceCollectionHandle,
                    $statamicSourceEntryId,
                    $forceFresh,
                    $statamicSiteHandle
                )
            );

            // Check if the entry was found
            if (! $statamicSourceEntry instanceof Entry) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'statamic_source_entry_id' => $statamicSourceEntryId,
                        'statamic_site_handle' => $statamicSiteHandle,
                    ]
                );

                // Log warning if entry cannot be found after retries
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "LinkProcessor::processDeferredInternalLinks: Entry with ID '{$statamicSourceEntryId}' not found for site {$statamicSiteHandle} after lightweight retry attempts",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Skip to next entry if current one cannot be found
                continue;
            }

            // Process deferred links for this entry using shared method
            $processingResult = self::processDeferredLinksForEntry(
                $statamicSourceEntry,
                $deferredLinks,
                $statamicSiteHandle,
                $output,
                $isFinalCall
            );

            // ---
            // Extract processing results
            // ---

            // Extract updated content field from processing results
            $content = $processingResult['content'];

            // Extract updated page_builder field from processing results
            $pageBuilder = $processingResult['page_builder'];

            // Extract content update flag from processing results
            $contentUpdated = $processingResult['content_updated'];

            // Extract page_builder update flag from processing results
            $pageBuilderUpdated = $processingResult['page_builder_updated'];

            // Extract processed link indices from processing results
            $processedLinkIndices = $processingResult['processed_link_indices'];

            // Extract unresolved links from processing results
            $entryUnresolvedLinks = $processingResult['unresolved_links'];

            // Add unresolved links to collection for reporting
            $unresolvedLinks = [
                ...$unresolvedLinks,
                ...$entryUnresolvedLinks,
            ];

            // Increment counter for unresolved links
            $unmappedCount += \count($entryUnresolvedLinks);

            // Check if the entry content or page_builder was modified during link processing
            if (
                $contentUpdated
                || $pageBuilderUpdated
            ) {
                // Update the entry with the modified content if it was modified
                if ($contentUpdated) {
                    $statamicSourceEntry->set(
                        'content',
                        $content
                    );
                }

                // Update the entry with the modified page_builder if it was modified
                if ($pageBuilderUpdated) {
                    $statamicSourceEntry->set(
                        'page_builder',
                        $pageBuilder
                    );
                }

                // Save the updated entry to the database
                $statamicSourceEntry->save();

                // Increment the counter for successfully processed entries
                $processedCount++;
            }

            // Remove successfully processed links from the deferred links array
            if ($processedLinkIndices !== []) {
                // Remove successfully processed links from the deferred links array
                // (unset by key shifts nothing, so the order does not matter)
                foreach ($processedLinkIndices as $index) {
                    unset(self::$deferredInternalLinks[$statamicSourceEntryId][$index]);
                }

                // Re-index the array to remove gaps
                self::$deferredInternalLinks[$statamicSourceEntryId] = \array_values(self::$deferredInternalLinks[$statamicSourceEntryId]);

                // Remove the entry entirely if no deferred links remain
                if (self::$deferredInternalLinks[$statamicSourceEntryId] === []) {
                    unset(self::$deferredInternalLinks[$statamicSourceEntryId]);
                }
            }

            // Unset loop variables
            unset(
                $statamicSourceEntryId,
                $deferredLinks,
                $statamicSourceCollectionHandle,
                $statamicSourceEntry,
                $processingResult,
                $content,
                $pageBuilder,
                $contentUpdated,
                $pageBuilderUpdated,
                $processedLinkIndices,
                $entryUnresolvedLinks
            );
        }

        // Display summary of processing results to user
        $output->writeln("Processed {$processedCount} entries with deferred internal links.");

        // Check if there were any unresolved links
        if ($unmappedCount > 0) {
            // Display warning about unresolved links to user
            $output->writeln("<comment>Remaining deferred internal links: {$unmappedCount}</comment>");

            // Group unresolved links by URL for better organization
            $groupedLinks = [];

            // Loop through all unresolved links to group them by URL
            foreach ($unresolvedLinks as $link) {
                // Extract the URL for grouping
                $url = $link['wordpress_url'];

                $groupedLinks[$url] ??= [
                    'wordpress_post_name' => $link['wordpress_post_name'],
                    'wordpress_post_id' => $link['wordpress_post_id'],
                ];

                // Add this entry to the group for this URL (the first starts its list)
                $groupedLinks[$url]['statamic_entries'][] = [
                    'statamic_entry_title' => \is_string($link['statamic_source_entry_title'])
                        ? $link['statamic_source_entry_title']
                        : '',
                    'statamic_entry_id' => \is_string($link['statamic_source_entry_id'])
                        ? $link['statamic_source_entry_id']
                        : '',
                ];
            }

            // Loop through grouped links to display them in organized format
            foreach ($groupedLinks as $url => $linkData) {
                $wordPressPostName = $linkData['wordpress_post_name'];
                $wordPressPostId = $linkData['wordpress_post_id'];

                // Display the WordPress URL, slug and ID
                $output->writeln("  - <comment>{$url} (WordPress slug: '{$wordPressPostName}'; WordPress ID: '{$wordPressPostId}')</comment>");

                // Count the number of Statamic entries that reference this URL
                $entryCount = \count($linkData['statamic_entries']);

                // Determine the correct singular or plural form based on entry count
                $entryText = $entryCount === 1 ? 'Entry' : 'Entries';

                // Display header for Statamic entries that use this link
                $output->writeln("      - <comment>Used on Statamic {$entryText}:</comment>");

                // Loop through all Statamic entries that reference this URL
                foreach ($linkData['statamic_entries'] as $entry) {
                    // Display each Statamic entry that uses this unresolved link
                    $output->writeln("          - <comment>'{$entry['statamic_entry_title']}' (Statamic ID: '{$entry['statamic_entry_id']}')</comment>");
                }
            }
        }
    }

    /**
     * Processes unresolved internal links at the end of post type imports.
     *
     * Outputs a summary of all internal links that could not be resolved during
     * processing, similar to how deferred links are reported.
     *
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  OutputInterface  $output  Console output for displaying results
     */
    public static function processUnresolvedLinksForPostType(
        string $statamicSiteHandle,
        OutputInterface $output
    ): void {
        // Check if there are any unresolved links to report
        if (self::$unresolvedLinksCache === []) {
            // Exit early if no unresolved links exist
            return;
        }

        // Inform user that unresolved link reporting is starting
        $output->writeln('Reporting unresolved internal links...');

        // Count total unresolved links
        $unresolvedCount = \count(self::$unresolvedLinksCache);

        // Display summary of unresolved links
        $output->writeln("<comment>Unresolved internal links: {$unresolvedCount}</comment>");

        // Sort URLs alphabetically for better readability (case-insensitive)
        $sortedUrls = \array_keys(self::$unresolvedLinksCache);
        sort(
            $sortedUrls,
            SORT_STRING | SORT_FLAG_CASE
        );

        // Display each unresolved link
        foreach ($sortedUrls as $url) {
            $output->writeln(
                "  - <comment>{$url}</comment>"
            );
        }

        // Clear the unresolved links cache to free memory
        self::$unresolvedLinksCache = [];
    }

    /**
     * Processes deferred links for a single entry.
     *
     * This is the core deferred link processing logic shared between
     * processDeferredInternalLinks() and DeferredProcessingCoordinator.
     * Processes all deferred links for an entry, resolves them, updates
     * content and page_builder fields, and returns processing results.
     *
     * @param  Entry  $entry  The Statamic entry to process
     * @param  array<int, DeferredLinkData>  $deferredLinks  Array of deferred links
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  OutputInterface  $output  Console output for user feedback
     * @param  bool  $isFinalCall  Whether this is the final call after all content types are imported
     * @param  ?AssetImporter  $assetImporter  Optional asset importer for processing attachment URLs as files
     * @return array{
     *     content: mixed,
     *     page_builder: mixed,
     *     content_updated: bool,
     *     page_builder_updated: bool,
     *     processed_link_urls: list<string>,
     *     processed_link_indices: list<int>,
     *     unresolved_links: list<array{
     *         statamic_source_entry_id: mixed,
     *         statamic_source_entry_title: mixed,
     *         wordpress_url: string,
     *         wordpress_post_name: string,
     *         wordpress_post_id: string,
     *         statamic_target_collection_handle: ?string
     *     }>
     * }
     */
    public static function processDeferredLinksForEntry(
        Entry $entry,
        array $deferredLinks,
        string $statamicSiteHandle,
        OutputInterface $output,
        bool $isFinalCall = false,
        ?AssetImporter $assetImporter = null
    ): array {
        // Get the current content of the entry
        $content = $entry->get('content');

        // Get the current page_builder of the entry
        $pageBuilder = $entry->get('page_builder');

        // Track whether content has been modified during processing
        $contentUpdated = false;

        // Track whether page_builder has been modified during processing
        $pageBuilderUpdated = false;

        // Track which links were successfully processed for removal (by URL)
        $processedLinkUrls = [];

        // Track which links were successfully processed for removal (by index)
        $processedLinkIndices = [];

        // Initialize array to collect unresolved link details
        $unresolvedLinks = [];

        // Process each deferred link for this entry
        foreach ($deferredLinks as $linkIndex => $linkData) {
            // Extract the original WordPress URL from the link data
            $wordPressUrl = $linkData['url'];

            // ---
            // Extract fragment and query from link data
            // ---

            // Extract fragment identifier from link data, defaulting to null if not present
            $fragment = $linkData['fragment'];

            // Extract query string from link data, defaulting to null if not present
            $query = $linkData['query'];

            // ---
            // Parse URL components to get base URL (deferred links may have full URL with
            // fragment)
            // ---

            // Parse WordPress URL into components (base, fragment, query)
            $urlComponents = self::parseUrlComponents($wordPressUrl);

            // Extract base URL from parsed components
            $baseUrl = $urlComponents['base'];

            // Extract fragment identifier from parsed URL components
            $fragmentFromUrl = $urlComponents['fragment'];

            // Extract query string from parsed URL components
            $queryFromUrl = $urlComponents['query'];

            // ---
            // Use fragment/query from linkData if available, otherwise from parsed URL
            // ---

            // Use fragment from parsed URL if not already set in link data
            $fragment ??= $fragmentFromUrl;

            // Use query from parsed URL if not already set in link data
            $query ??= $queryFromUrl;

            // ---
            // Construct search URL without fragment for caching (XML <link> elements don't
            // include fragments)
            // ---
            $searchUrl = $baseUrl.($query ?? '');

            // Extract the WordPress post name from the link data
            $wordPressPostName = $linkData['post_name'];

            // Extract the WordPress post ID from the link data
            $wordPressPostId = $linkData['post_id'];

            // Extract the WordPress post type from the link data
            $wordPressPostType = $linkData['post_type'];

            // Skip redirect_rule post types as they are imported into redirects global,
            // not a collection, and should not be linked to
            if ($wordPressPostType === 'redirect_rule') {
                // Continue to next link without attempting resolution
                // (redirect rules are intentionally skipped, not unresolved)
                continue;
            }

            // ---
            // Handle attachment post types by extracting attachment URL and processing as file
            // ---

            // Check if the post type is attachment
            if ($wordPressPostType === 'attachment') {
                // Get XML path from ProcessingContext
                $xmlPath = ProcessingContext::getXmlPath();

                // Check if XML path is available (an empty one names no part, so
                // finds no item)
                if ($xmlPath !== null) {
                    // Find the attachment item in XML to extract the attachment URL
                    $attachmentItem = XpathHelper::findItemByPostId(
                        $xmlPath,
                        $wordPressPostId,
                        'attachment'
                    );

                    // Check if attachment item was found
                    if ($attachmentItem instanceof SimpleXMLElement) {
                        // Extract the attachment URL from the XML item
                        $attachmentUrl = XpathHelper::getStringValue(
                            $attachmentItem,
                            'wp:attachment_url'
                        );

                        // Check if attachment URL was successfully extracted
                        if ($attachmentUrl !== '') {
                            // Initialize processed URL with attachment URL
                            $processedUrl = $attachmentUrl;

                            // Check if AssetImporter is available to process attachment as file
                            if ($assetImporter instanceof AssetImporter) {
                                // Process the attachment URL as a file URL
                                $processedUrl = self::processAttachmentUrlAsFileForDeferred(
                                    $attachmentUrl,
                                    $statamicSiteHandle,
                                    $assetImporter,
                                    $output
                                );

                                // Use attachment URL as fallback when file processing fails
                                // (it never gives an empty URL)
                                $processedUrl ??= $attachmentUrl;
                            }

                            // Replace WordPress URL with processed URL in content and page_builder
                            $fieldUpdates = self::processResolvedLinkInFields(
                                $content,
                                $pageBuilder,
                                $wordPressUrl,
                                $processedUrl,
                                $fragment,
                                $query
                            );

                            // Update content field with processed values
                            $content = $fieldUpdates['content'];

                            // Update page_builder field with processed values
                            $pageBuilder = $fieldUpdates['page_builder'];

                            // ---
                            // Update flags if fields were modified
                            // ---

                            // Check if content field was modified during processing
                            if ($fieldUpdates['content_updated']) {
                                // Mark content field as updated
                                $contentUpdated = true;
                            }

                            // Check if page_builder field was modified during processing
                            if ($fieldUpdates['page_builder_updated']) {
                                // Mark page_builder field as updated
                                $pageBuilderUpdated = true;
                            }

                            // ---
                            // Track this link as successfully processed
                            // ---

                            // Add WordPress URL to processed links array for tracking
                            $processedLinkUrls[] = $wordPressUrl;

                            // Add link index to processed indices array for removal
                            $processedLinkIndices[] = $linkIndex;

                            // Inform user that attachment was processed
                            $output->writeln("  - <info>Processed attachment link: {$wordPressUrl} -> {$processedUrl}</info>");

                            // Continue to next link
                            continue;
                        }
                    }
                }

                // If attachment URL could not be extracted, log warning and skip
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'wordpress_url' => $wordPressUrl,
                        'wordpress_post_id' => $wordPressPostId,
                        'wordpress_post_type' => $wordPressPostType,
                    ]
                );

                // Log warning about failed attachment URL extraction
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'LinkProcessor::processDeferredLinksForEntry: Could not extract attachment URL',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Continue to next link without attempting entry resolution
                continue;
            }

            // Resolve the deferred link using shared logic
            $resolvedLink = self::resolveDeferredLink(
                $linkData,
                $statamicSiteHandle,
                $isFinalCall
            );

            // Check if the link was successfully resolved
            if ($resolvedLink !== null) {
                // ---
                // Extract resolved data
                // ---

                // Extract Statamic entry reference URL from resolved link
                $statamicUrl = $resolvedLink['statamic_url'];

                // Extract fragment identifier from resolved link
                $fragment = $resolvedLink['fragment'];

                // Extract query string from resolved link
                $query = $resolvedLink['query'];

                // Process resolved link in content and page_builder fields
                $fieldUpdates = self::processResolvedLinkInFields(
                    $content,
                    $pageBuilder,
                    $wordPressUrl,
                    $statamicUrl,
                    $fragment,
                    $query
                );

                // Update content field with processed values from field updates
                $content = $fieldUpdates['content'];

                // Update page_builder field with processed values from field updates
                $pageBuilder = $fieldUpdates['page_builder'];

                // ---
                // Update flags if fields were modified
                // ---

                // Check if content field was modified during processing
                if ($fieldUpdates['content_updated']) {
                    // Mark content as updated
                    $contentUpdated = true;
                }

                // Check if page_builder field was modified during processing
                if ($fieldUpdates['page_builder_updated']) {
                    // Mark page_builder as updated
                    $pageBuilderUpdated = true;
                }

                // Store the successful conversion in the cache (use search URL as key)
                self::$processedLinksCache[$searchUrl] = $statamicUrl;

                // Track this link URL as successfully processed
                $processedLinkUrls[] = $wordPressUrl;

                // Track this link index as successfully processed
                $processedLinkIndices[] = $linkIndex;

                // Inform user that the link was successfully resolved
                $output->writeln("  - <info>Resolved deferred link: {$wordPressUrl} -> {$statamicUrl}</info>");
            } else { // Otherwise
                // Get target collection handle for logging
                $statamicTargetCollectionHandle = $linkData['collection_handle'];

                // Check if collection handle is missing and WordPress post type is available
                if (
                    ($statamicTargetCollectionHandle === null || $statamicTargetCollectionHandle === '')
                    && $wordPressPostType !== ''
                ) {
                    // Map WordPress post type to Statamic collection handle
                    $statamicTargetCollectionHandle = self::mapPostTypeToCollection(
                        $wordPressPostType
                    );
                }

                // Check if collection handle is still missing after mapping attempt
                if ($statamicTargetCollectionHandle === null || $statamicTargetCollectionHandle === '') {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'wordpress_url' => $wordPressUrl,
                            'wordpress_post_name' => $wordPressPostName,
                            'wordpress_post_id' => $wordPressPostId,
                            'wordpress_post_type' => $wordPressPostType,
                        ]
                    );

                    // Log warning for missing collection handle
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            "LinkProcessor::processDeferredLinksForEntry: No collection handle found for post type '{$wordPressPostType}'",
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }

                // Collect unresolved link details for user feedback
                $unresolvedLinks[] = [
                    'statamic_source_entry_id' => $entry->id(),
                    'statamic_source_entry_title' => $entry->get('title') ?? 'Untitled',
                    'wordpress_url' => $wordPressUrl,
                    'wordpress_post_name' => $wordPressPostName,
                    'wordpress_post_id' => $wordPressPostId,
                    'statamic_target_collection_handle' => $statamicTargetCollectionHandle,
                ];

                // Check if this is the final call after all content types are imported
                if ($isFinalCall) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'statamic_source_entry_id' => $entry->id(),
                            'wordpress_url' => $wordPressUrl,
                            'wordpress_post_name' => $wordPressPostName,
                            'wordpress_post_id' => $wordPressPostId,
                            'statamic_target_collection_handle' => $statamicTargetCollectionHandle,
                            'statamic_site_handle' => $statamicSiteHandle,
                        ]
                    );

                    // Log unresolved link details
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'LinkProcessor::processDeferredLinksForEntry: Could not resolve deferred link after all content types imported',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            }
        }

        // Return processing results
        return [
            'content' => $content,
            'page_builder' => $pageBuilder,
            'content_updated' => $contentUpdated,
            'page_builder_updated' => $pageBuilderUpdated,
            'processed_link_urls' => $processedLinkUrls,
            'processed_link_indices' => $processedLinkIndices,
            'unresolved_links' => $unresolvedLinks,
        ];
    }

    /**
     * Resolves a single deferred internal link to a Statamic entry reference.
     *
     * This is the core link resolution logic shared between
     * processDeferredInternalLinks() and DeferredProcessingCoordinator.
     * Extracts link data, finds the target entry, and generates the Statamic
     * entry reference URL.
     *
     * @param  DeferredLinkData  $linkData  The deferred link data array
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  bool  $isFinalCall  Whether this is the final call after all content types are imported (for
     *                             logging purposes)
     * @return ResolvedDeferredLink|null Resolved link data, or null if not found
     */
    public static function resolveDeferredLink(
        array $linkData,
        string $statamicSiteHandle,
        bool $isFinalCall = false
    ): ?array {
        // Extract link data
        $wordPressUrl = $linkData['url'];
        $wordPressPostName = $linkData['post_name'];
        $wordPressPostId = $linkData['post_id'];
        $wordPressPostType = $linkData['post_type'];
        $statamicTargetCollectionHandle = $linkData['collection_handle'];
        $fragment = $linkData['fragment'];
        $query = $linkData['query'];

        // Skip redirect_rule post types as they are imported into redirects global,
        // not a collection, and should not be linked to
        if ($wordPressPostType === 'redirect_rule') {
            // Return null to skip this link (redirect rules are not linkable entries)
            return null;
        }

        // Use the stored target collection handle, or fall back to mapping from post
        // type (an empty type maps to none)
        if ($statamicTargetCollectionHandle === null || $statamicTargetCollectionHandle === '') {
            // Map WordPress post type to Statamic collection handle
            $statamicTargetCollectionHandle = self::mapPostTypeToCollection(
                $wordPressPostType
            );
        }

        // Skip if we don't have a target collection handle (an empty one was mapped
        // above, and a mapping is never empty)
        if ($statamicTargetCollectionHandle === null) {
            // Return null if no collection handle found
            return null;
        }

        // Try to find the entry by slug first with retry logic
        $targetEntry = self::findEntryWithRetry(
            fn (bool $forceFresh): ?Entry => EntryFinder::findEntryBySlug(
                $statamicTargetCollectionHandle,
                $wordPressPostName,
                $forceFresh,
                $statamicSiteHandle
            )
        );

        $targetEntry ??= self::findEntryWithRetry(
            fn (bool $forceFresh): ?Entry => EntryFinder::findEntryByWordPressId(
                $statamicTargetCollectionHandle,
                $wordPressPostId,
                $forceFresh,
                $statamicSiteHandle
            )
        );

        // Check if the target entry was found
        if (! $targetEntry instanceof Entry) {
            // ---
            // Only log warnings during final call (after all content types imported). During
            // per-post-type processing, it's expected that cross-post-type links won't be
            // resolvable yet.
            // ---

            // Check if this is the final call after all content types are imported
            if ($isFinalCall) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'wordpress_post_name' => $wordPressPostName,
                        'wordpress_post_id' => $wordPressPostId,
                        'wordpress_post_type' => $wordPressPostType,
                        'statamic_target_collection_handle' => $statamicTargetCollectionHandle,
                        'statamic_site_handle' => $statamicSiteHandle,
                        'wordpress_url' => $wordPressUrl,
                    ]
                );

                // Log detailed diagnostic information for unresolved links after all imports
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'LinkProcessor::resolveDeferredLink: Entry not found by slug or WordPress ID after all content types imported',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Return null if entry not found
            return null;
        }

        // Generate the Statamic entry reference
        $statamicUrl = self::generateEntryReference(
            $targetEntry,
            $fragment,
            $query
        );

        // Return resolved link data
        return [
            'statamic_url' => $statamicUrl,
            'target_entry' => $targetEntry,
            'wordpress_url' => $wordPressUrl,
            'fragment' => $fragment,
            'query' => $query,
        ];
    }

    /**
     * Processes an attachment URL as a file URL for deferred link processing.
     *
     * When a deferred link points to an attachment post type, this method
     * processes the attachment URL through the file import workflow.
     *
     * @param  string  $attachmentUrl  The attachment URL to process
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @return ?string The processed file URL if successful, original URL if uploads disabled, null otherwise
     */
    private static function processAttachmentUrlAsFileForDeferred(
        string $attachmentUrl,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): ?string {
        // Check if file uploads are disabled
        if (
            self::checkUploadsDisabled(
                $assetImporter,
                $output,
                'linked attachment: '.basename($attachmentUrl),
                'file'
            )
        ) {
            // Return the original URL when uploads are disabled
            return $attachmentUrl;
        }

        // Check if the attachment URL is a file URL
        if (! self::isFileUrl($attachmentUrl)) {
            // Return the original URL if it's not a file URL
            return $attachmentUrl;
        }

        // Import the file and get the new path
        $newPath = $assetImporter->importFile(
            $attachmentUrl,
            $statamicSiteHandle,
            $output,
            'files',
            SameSitePolicy::LINK_DIFFERENT_SITE
        );

        // A file on another site is returned as its own URL, to link to as
        // it is (#408)
        if ($newPath === $attachmentUrl) {
            return $attachmentUrl;
        }

        // Check if the file was successfully imported
        if ($newPath !== null && $newPath !== '') {
            // Generate the new Statamic URL for the imported file
            $newUrl = self::getAppUrl().'/files/'.$newPath;

            // Return the new Statamic file URL
            return $newUrl;
        }

        // Return null if import failed
        return null;
    }

    /**
     * Finds an entry with optimized retry logic for deferred link processing.
     *
     * This helper method centralizes the retry logic for finding entries during
     * deferred link processing. It uses a lightweight approach with minimal cache
     * operations to handle cache timing issues without significant performance
     * overhead. Only used for deferred processing where entries should exist.
     *
     * @param  callable  $findCallback  The callback function to find the entry
     * @param  int  $retryCount  Number of retry attempts (default: 2)
     * @param  int  $retryDelay  Delay between retries in microseconds (default: 100000)
     * @param  ?callable(int): void  $sleep  Makes each wait, given its microseconds (default: `usleep`)
     * @return ?Entry The found entry or null if not found
     */
    public static function findEntryWithRetry(
        callable $findCallback,
        int $retryCount = 2,
        // A microsecond either way is no different wait
        // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        int $retryDelay = 100000,
        ?callable $sleep = null
    ): ?Entry {
        // Wait with the given callable, or for real
        $sleep ??= usleep(...);

        // Initialize entry variable for lookup results
        $statamicEntry = null;

        // Attempt to find the entry with lightweight retry logic
        for ($i = 0; $i < $retryCount; $i++) {
            try {
                // Force a fresh, targeted per-entry invalidation on every
                // attempt. The finder's forceFresh path calls forgetItem() for
                // just this entry, so a missed lookup no longer clears the
                // entire entries store (wordpress-importer-archive#306). That per-miss full-store
                // clear()/rebuild was a performance drain on large imports and
                // the source of the repeated wordpress-importer-archive#173 cache-write crash that wordpress-importer-archive#305
                // could only tolerate; the targeted invalidation removes it.
                $candidateEntry = $findCallback(true);
                $statamicEntry = $candidateEntry instanceof Entry ? $candidateEntry : null;
            } catch (\Throwable $e) {
                // A transient cache-write failure during the targeted
                // invalidation must not abort the entire import. On Windows,
                // forgetting a cached item can momentarily hit an
                // ERROR_SHARING_VIOLATION (another handle holding the file)
                // that PHP surfaces as "Permission denied". Log it and let the
                // loop retry — defense-in-depth carried over from wordpress-importer-archive#305.
                Log::warning(
                    'LinkProcessor::findEntryWithRetry: targeted entry invalidation failed; retrying',
                    ['exception' => $e->getMessage()]
                );

                // The failed attempt leaves the entry null, so the retry runs
            }

            // Check if entry was found successfully
            if ($statamicEntry instanceof Entry) {
                // Exit retry loop on successful find
                break;
            }

            // Brief delay before the next targeted retry to let a just-saved
            // entry's cache writes settle (skip the wait after the final attempt)
            if ($i < $retryCount - 1) {
                $sleep($retryDelay);
            }
        }

        // Return the found entry or null if not found after all retries
        return $statamicEntry;
    }
}
