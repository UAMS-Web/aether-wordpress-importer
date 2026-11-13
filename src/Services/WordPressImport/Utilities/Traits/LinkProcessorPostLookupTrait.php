<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Traits;

use SimpleXMLElement;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\SplitExportPartLocator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\GuardedHttpFetcher;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizedStream;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Finds the export post a WordPress link points to, for `LinkProcessor`.
 *
 * Resolves a URL to its post through the URL map, then an exact `<link>` match in
 * any part of the export, then the URL's path segments matched against the
 * export's posts, and last the page id the live page's HTML carries.
 *
 * @phpstan-import-type PostData from LinkProcessor
 */
trait LinkProcessorPostLookupTrait
{
    /**
     * Finds WordPress post data for a given URL by searching the XML export.
     *
     * This method searches through the WordPress XML export to find the post
     * that has the given URL as its permalink. It returns both the post ID
     * and post name if found. Uses cross-platform PHP file reading instead
     * of shell commands.
     *
     * Items are partitioned across the parts of a split export, so this expands
     * the given path to its full part-set and searches each part in turn — loading
     * one part at a time — for an exact link match, then a URL-structure match. A
     * single-file export resolves to a one-part set, preserving prior behavior.
     *
     * @param  string  $wordPressUrl  The URL to search for
     * @param  string  $xmlPath  The path to any one export part (or a single-file export)
     * @param  ?string  $finalUrl  The final URL after redirects (optional)
     * @return PostData|null Post data if found, or null if not found
     */
    private function findPostDataByUrl(
        string $wordPressUrl,
        string $xmlPath,
        ?string $finalUrl = null
    ): ?array {
        $searchUrl = ($finalUrl !== null && $finalUrl !== '') ? $finalUrl : $wordPressUrl;

        // Get the cached post ID for this URL
        $wordPressPostId = self::$urlToPostIdMap[$searchUrl] ?? null;

        // Check if we already have this URL mapped
        if ($wordPressPostId !== null && $wordPressPostId !== '') {
            // Find and return the post name for the cached post ID
            return $this->findPostNameById($wordPressPostId, $xmlPath);
        }

        // Expand any single part path to the whole ordered split-export part-set
        $parts = SplitExportPartLocator::locate($xmlPath)->parts;

        // Build an XPath string literal for the URL so a value containing a quote
        // cannot break out of the query. XPath compares text, not markup, so the
        // raw URL is what matches a stored link holding & (#160)
        $linkLiteral = XpathHelper::xpathStringLiteral($searchUrl);

        // Hold the first URL-structure (non-published) match until every part has
        // been checked for a definitive exact-link match. An exact <link> match
        // anywhere outranks a structure match, matching single-file precedence.
        $structureMatch = null;

        // Search each part: exact-link matches are definitive, structure is fallback
        foreach ($parts as $part) {
            // Skip a part that is no longer present on disk. The locator lists
            // only parts that exist, so a missing one is either the lone path of a
            // single-file export or a file removed mid-run; with nothing after the
            // lone path, stopping and skipping cannot be told apart in a test.
            // @pest-mutate-ignore: ContinueToBreak
            if (! \file_exists($part)) {
                continue;
            }

            // Use SimpleXML for efficient XML parsing of this part
            $xml = simplexml_load_file(WxrSanitizedStream::wrap($part));

            // Skip a part that could not be parsed
            if ($xml === false) {
                continue;
            }

            // Register WordPress XML namespaces for XPath queries
            XpathHelper::registerWordPressNamespaces($xml);

            // Use XPath to find items with exact URL match in their link element
            $items = $xml->xpath("//item[link[text()={$linkLiteral}]]");

            // An exact link match is definitive (the URL is unique to a single part)
            if (\is_array($items) && isset($items[0])) {
                // Extract and return this item's post data (or null when incomplete)
                return $this->extractPostDataFromLinkMatch(
                    $items[0],
                    $searchUrl
                );
            }

            $structureMatch ??= $this->findPostDataByUrlStructure(
                $searchUrl,
                $xml
            );

            // Free this part before loading the next one to bound peak memory
            unset(
                $xml,
                $items
            );
        }

        // No part held an exact link match — fall back to the first structure match
        if ($structureMatch !== null) {
            // Cache the result
            self::$urlToPostIdMap[$searchUrl] = $structureMatch['post_id'];

            // Return the found post data
            return $structureMatch;
        }

        // Try to extract page-id from HTML body class as a final fallback
        $extractedPostId = $this->extractPageIdFromHtml($searchUrl);

        // Check if a post ID was extracted from the HTML (its pattern needs a digit)
        if ($extractedPostId !== null) {
            // Find and return the post data using the extracted post ID (across parts)
            return $this->findPostNameById(
                $extractedPostId,
                $xmlPath
            );
        }

        // Return null if no matching items found and no post ID could be extracted
        return null;
    }

    /**
     * Extract post data from an item matched by its exact link URL.
     *
     * The link URL is unique to a single part, so a match here is definitive:
     * the item's post data is returned, or null when any required field is absent.
     *
     * @param  SimpleXMLElement  $item  The item whose <link> matched the search URL
     * @param  string  $searchUrl  The URL used to match, keyed into the resolution cache
     * @return PostData|null Post data when all fields are present, otherwise null
     */
    private function extractPostDataFromLinkMatch(
        SimpleXMLElement $item,
        string $searchUrl
    ): ?array {
        // Extract post ID nodes from the matching item
        $postIdNodes = $item->xpath('wp:post_id');

        // Extract post name nodes from the matching item
        $postNameNodes = $item->xpath('wp:post_name');

        // Extract post type nodes from the matching item
        $postTypeNodes = $item->xpath('wp:post_type');

        // Convert post ID node to string, defaulting to empty string if not found
        $wordPressPostId = \is_array($postIdNodes) && isset($postIdNodes[0])
            ? (string) $postIdNodes[0]
            : '';

        // Convert post name node to string, defaulting to empty string if not found
        $wordPressPostName = \is_array($postNameNodes) && isset($postNameNodes[0])
            ? (string) $postNameNodes[0]
            : '';

        // Convert post type node to string, defaulting to empty string if not found
        $wordPressPostType = \is_array($postTypeNodes) && isset($postTypeNodes[0])
            ? (string) $postTypeNodes[0]
            : '';

        // Check if post ID, post name, and post type were successfully extracted
        if (
            $wordPressPostId === ''
            || $wordPressPostName === ''
            || $wordPressPostType === ''
        ) {
            // Return null if any required field is missing
            return null;
        }

        // Cache the result
        self::$urlToPostIdMap[$searchUrl] = $wordPressPostId;

        // Return the found post data
        return [
            'post_id' => $wordPressPostId,
            'post_name' => $wordPressPostName,
            'post_type' => $wordPressPostType,
        ];
    }

    /**
     * Finds WordPress post data by analyzing URL structure when link element is not
     * found.
     *
     * This method attempts to find post items by matching URL path segments to post
     * names and validating parent-child relationships. It's used as a fallback when
     * posts are not published (and thus don't have matching link elements in the XML).
     *
     * @param  string  $wordPressUrl  The WordPress URL to analyze
     * @param  SimpleXMLElement  $xml  The parsed XML document
     * @return PostData|null Post data if found, or null if not found
     */
    private function findPostDataByUrlStructure(
        string $wordPressUrl,
        SimpleXMLElement $xml
    ): ?array {
        // Get the base blog URL from the XML channel
        $baseBlogUrl = XpathHelper::getStringValue(
            $xml->channel,
            'wp:base_blog_url'
        );

        // Check if base blog URL is available
        if ($baseBlogUrl === '') {
            // Return null if base blog URL is not available
            return null;
        }

        // Extract path segments from the URL
        $pathSegments = $this->extractPathSegmentsFromUrl(
            $wordPressUrl,
            $baseBlogUrl
        );

        // Find matching post by validating segments and parent relationships
        // (no segments match nothing)
        return $this->findPostByPathSegments(
            $pathSegments,
            $xml
        );
    }

    /**
     * Extracts path segments from a WordPress URL.
     *
     * Removes the base blog URL, query parameters, fragment identifiers, and
     * trailing slashes to extract the clean path segments.
     *
     * @param  string  $wordPressUrl  The WordPress URL
     * @param  string  $baseBlogUrl  The base blog URL from XML
     * @return list<string> Array of path segments (e.g., ['baz', 'qux'])
     */
    private function extractPathSegmentsFromUrl(
        string $wordPressUrl,
        string $baseBlogUrl
    ): array {
        // Remove protocol from both URLs for comparison
        $normalizedUrl = \preg_replace(
            '#^https?:#',
            '',
            $wordPressUrl
        );

        $normalizedBaseBlogUrl = \preg_replace(
            '#^https?:#',
            '',
            $baseBlogUrl
        );

        // Preserve inputs if protocol stripping fails
        $url = $normalizedUrl ?? $wordPressUrl;
        $baseBlogUrl = $normalizedBaseBlogUrl ?? $baseBlogUrl;

        // Remove leading slashes
        $url = \ltrim(
            $url,
            '/'
        );

        $baseBlogUrl = \ltrim(
            $baseBlogUrl,
            '/'
        );

        // Check if URL starts with base blog URL
        if (
            ! \str_starts_with(
                $url,
                $baseBlogUrl
            )
        ) {
            // Return empty array if URL doesn't belong to the same site
            return [];
        }

        // Remove the base blog URL from the beginning of the path
        $path = \substr(
            $url,
            \strlen($baseBlogUrl)
        );

        // Cut the query and fragment, so a path that is only a query or a fragment
        // is no path rather than a slug (#160). A leading slash leaves an empty
        // first segment, which the filter below drops
        $path = \substr(
            $path,
            0,
            \strcspn($path, '?#')
        );

        // Split the path into segments
        $segments = \explode(
            '/',
            $path
        );

        // Filter out empty segments, which a trailing or doubled slash leaves
        $segments = \array_filter(
            $segments,
            fn ($segment): bool => $segment !== ''
        );

        // Re-index array to remove gaps
        return \array_values($segments);
    }

    /**
     * Finds a WordPress post by validating path segments against post names and
     * parents.
     *
     * Works backwards through path segments, starting with the last (most specific)
     * segment. For each segment, finds posts with matching post_name, filters out
     * published posts, validates parent-child relationships, and continues until
     * a unique match is found or all segments are validated.
     *
     * For posts with post_type='post', uses date-based or category-based validation
     * instead of parent validation when the URL matches those patterns.
     *
     * @param  list<string>  $pathSegments  URL path segments (e.g., ['baz', 'qux'])
     * @param  SimpleXMLElement  $xml  The parsed XML document
     * @return PostData|null Post data if found, or null if not found
     */
    private function findPostByPathSegments(
        array $pathSegments,
        SimpleXMLElement $xml
    ): ?array {
        // Get the number of segments
        $segmentCount = \count($pathSegments);

        // Check if there are no segments
        if ($segmentCount === 0) {
            // Return null if no segments to process
            return null;
        }

        // ---
        // Start with the last segment (most specific)
        // ---

        // Calculate the index of the last segment in the path
        $lastSegmentIndex = $segmentCount - 1;

        // Get the last segment value to use for post name matching
        $currentSegment = $pathSegments[$lastSegmentIndex];

        // Search XML for all items with matching post_name value, through a
        // string literal so a slug holding & or an apostrophe matches (#160)
        $candidateItems = $xml->xpath(
            '//item[wp:post_name[text()='.XpathHelper::xpathStringLiteral($currentSegment).']]'
        );

        // Check if any candidates were found. xpath() answers false only for an
        // invalid query, which the string literal rules out, and with no candidates
        // every step below also ends in null, so this return only saves the work
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn
        if (! \is_array($candidateItems) || $candidateItems === []) {
            // Return null if no posts found for the last segment
            return null;
        }

        // Filter out published posts (they would have matched via link element)
        $nonPublishedItems = \array_filter(
            $candidateItems,
            function (SimpleXMLElement $item): bool {
                // Extract the post status from the XML item
                $status = XpathHelper::getStringValue(
                    $item,
                    'wp:status'
                );

                // Return true if post status is not published
                return $status !== 'publish';
            }
        );

        // ---
        // Check if any items have post_type='post' for special validation
        // ---

        // Filter items to check if any are post type
        $postTypeItems = \array_filter(
            $nonPublishedItems,
            function (SimpleXMLElement $item): bool {
                // Extract the post type from the XML item
                $postType = XpathHelper::getStringValue(
                    $item,
                    'wp:post_type'
                );

                // Return true if post type is 'post'
                return $postType === 'post';
            }
        );

        // Check if we have post type items (the date and category patterns both need
        // more than one segment, so a single one matches neither)
        if ($postTypeItems !== []) {
            // Extract the remaining path segments (excluding base blog URL)
            $remainingPath = \implode(
                '/',
                $pathSegments
            );

            // Check if URL matches date pattern: \d{4}/\d{2}/\d{2}/[^/]+
            if (
                \preg_match(
                    '/^(\d{4})\/(\d{2})\/(\d{2})\/[^\/]+$/',
                    $remainingPath,
                    $dateMatches
                ) === 1
            ) {
                // Build the URL's date from its year, month and day
                $urlDate = "{$dateMatches[1]}-{$dateMatches[2]}-{$dateMatches[3]}";

                // Validate items by matching post date
                $validatedItems = [];

                // Loop through each post type item
                foreach ($postTypeItems as $item) {
                    // Extract post date (prefer wp:post_date, fallback to pubDate)
                    $postDate = XpathHelper::getStringValue(
                        $item,
                        'wp:post_date'
                    );

                    // Fallback to pubDate if wp:post_date is empty
                    if ($postDate === '') {
                        $postDate = XpathHelper::getStringValue(
                            $item,
                            'pubDate'
                        );
                    }

                    // Check if post date is available
                    if ($postDate === '') {
                        // Skip items without post date
                        continue;
                    }

                    // Parse post date to compare its year, month and day
                    try {
                        // Create DateTime object from post date string
                        $postDateTime = new \DateTime($postDate);

                        // Check if post date matches URL date
                        if ($postDateTime->format('Y-m-d') === $urlDate) {
                            // Add this item to validated items
                            $validatedItems[] = $item;
                        }
                    } catch (\Exception) {
                        // Skip items with invalid date format
                        continue;
                    }
                }

                // Check if any validated items remain
                if ($validatedItems !== []) {
                    // Check if multiple validated items remain
                    if (\count($validatedItems) > 1) {
                        // Return null if multiple matches found (ambiguous)
                        return null;
                    }

                    // Get the single validated item
                    $matchedItem = $validatedItems[0];
                }
            }

            // ---
            // If date pattern didn't match or date validation found no matches, check
            // category pattern: [^/]+/[^/]+
            // ---

            // Check if no date match was found and URL matches category pattern
            if (
                ! isset($matchedItem)
                && \preg_match(
                    '/^([^\/]+)\/[^\/]+$/',
                    $remainingPath,
                    $categoryMatches
                ) === 1
            ) {
                // Extract category slug from URL
                $urlCategorySlug = $categoryMatches[1];

                // Validate items by matching category nicename
                $validatedItems = [];

                // Loop through each post type item
                foreach ($postTypeItems as $item) {
                    // Get all category elements with domain="category"
                    $categoryElements = $item->xpath(
                        'category[@domain="category"]/@nicename'
                    );
                    $hasMatchingCategory = array_any(\is_array($categoryElements) ? $categoryElements : [], fn (SimpleXMLElement $categoryNicename): bool => (string) $categoryNicename === $urlCategorySlug);

                    // ---
                    // Add item if category matches
                    // ---

                    // Check if category match was found for this item
                    if ($hasMatchingCategory) {
                        // Add item to validated items array
                        $validatedItems[] = $item;
                    }
                }

                // Check if any validated items remain
                if ($validatedItems === []) {
                    // Return null if no items passed validation
                    return null;
                }

                // Check if multiple validated items remain
                if (\count($validatedItems) > 1) {
                    // Return null if multiple matches found (ambiguous)
                    return null;
                }

                // Get the single validated item
                $matchedItem = $validatedItems[0];
            }

            // If we found a match via post type validation, extract and return
            if (isset($matchedItem)) {
                // Extract the WordPress post ID from the matched XML item
                $postId = XpathHelper::getStringValue(
                    $matchedItem,
                    'wp:post_id'
                );

                // Extract the WordPress post name (slug) from the matched XML item
                $postName = XpathHelper::getStringValue(
                    $matchedItem,
                    'wp:post_name'
                );

                // Extract the WordPress post type from the matched XML item
                $postType = XpathHelper::getStringValue(
                    $matchedItem,
                    'wp:post_type'
                );

                // Return null if the post ID or name is missing (the type is post;
                // the name can be empty when the item carries a second, matching one)
                if (
                    $postId === ''
                    || $postName === ''
                ) {
                    return null;
                }

                // Return the found post data
                return [
                    'post_id' => $postId,
                    'post_name' => $postName,
                    'post_type' => $postType,
                ];
            }

            // ---
            // If no post type validation matched, fall through to parent validation
            // (only for non-post types or when post type validation didn't find a match)
            // ---
        }

        // Check if this segment is not the first segment in the path
        if ($lastSegmentIndex > 0) {
            // Validate parent relationships for the candidate items
            $validatedItems = [];

            // Loop through each candidate item
            foreach ($nonPublishedItems as $item) {
                // Get the parent post ID
                $parentPostId = XpathHelper::getStringValue(
                    $item,
                    'wp:post_parent'
                );

                // Skip a root-level item or one with no parent ID, which cannot be
                // the child the path names
                if (
                    $parentPostId === '0'
                    || $parentPostId === ''
                ) {
                    continue;
                }

                // Find the parent post item
                $parentItems = $xml->xpath(
                    '//item[wp:post_id[text()='.XpathHelper::xpathStringLiteral($parentPostId).']]'
                );

                // Get the parent post item, skipping an item whose parent cannot be
                // found (or whose parent ID breaks the query)
                $firstParentItem = \is_array($parentItems) ? ($parentItems[0] ?? null) : null;

                if ($firstParentItem === null) {
                    continue;
                }

                $parentPostName = XpathHelper::getStringValue(
                    $firstParentItem,
                    'wp:post_name'
                );

                // Get the expected parent segment (previous segment in path)
                $expectedParentSegment = $pathSegments[$lastSegmentIndex - 1];

                // Check if parent post name matches the expected segment
                if ($parentPostName !== $expectedParentSegment) {
                    // Skip items whose parent doesn't match expected segment
                    continue;
                }

                // If parent segment is the first segment, validate it has no parent
                if ($lastSegmentIndex - 1 === 0) {
                    // Get the parent's parent post ID
                    $parentParentPostId = XpathHelper::getStringValue(
                        $firstParentItem,
                        'wp:post_parent'
                    );

                    // Check if parent's parent is not 0 (should be root level)
                    if ($parentParentPostId !== '0') {
                        // Skip items whose parent is not at root level when it should be
                        continue;
                    }
                }

                // Add this item to validated items
                $validatedItems[] = $item;
            }

            // Check if any validated items remain
            if ($validatedItems === []) {
                // Return null if no items passed validation
                return null;
            }

            // Check if multiple validated items remain
            if (\count($validatedItems) > 1) {
                // Return null if multiple matches found (ambiguous)
                return null;
            }

            // Get the single validated item
            $matchedItem = $validatedItems[0];
        } else { // Otherwise, if this is the first segment, validate it differently
            // Filter out items with non-zero post_parent (should be root level)
            $rootLevelItems = \array_filter(
                $nonPublishedItems,
                function (SimpleXMLElement $item): bool {
                    // Extract the post parent ID from the XML item
                    $postParent = XpathHelper::getStringValue(
                        $item,
                        'wp:post_parent'
                    );

                    // Return true if post is at root level (no parent)
                    return $postParent === '0';
                }
            );

            // Check if any root level items remain
            if ($rootLevelItems === []) {
                // Return null if no root level items found
                return null;
            }

            // Re-index array to remove gaps
            $rootLevelItems = \array_values($rootLevelItems);

            // Check if multiple root level items remain
            if (\count($rootLevelItems) > 1) {
                // Return null if multiple matches found (ambiguous)
                return null;
            }

            // Get the single root level item
            $matchedItem = $rootLevelItems[0];
        }

        // ---
        // Extract post data from the matched item
        // ---

        // Extract the WordPress post ID from the matched XML item
        $postId = XpathHelper::getStringValue(
            $matchedItem,
            'wp:post_id'
        );

        // Extract the WordPress post name (slug) from the matched XML item
        $postName = XpathHelper::getStringValue(
            $matchedItem,
            'wp:post_name'
        );

        // Extract the WordPress post type from the matched XML item
        $postType = XpathHelper::getStringValue(
            $matchedItem,
            'wp:post_type'
        );

        // Check if all required fields are present (the name can be empty when
        // the item carries a second, matching one)
        if (
            $postId === ''
            || $postName === ''
            || $postType === ''
        ) {
            // Return null if any required field is missing
            return null;
        }

        // Return the found post data
        return [
            'post_id' => $postId,
            'post_name' => $postName,
            'post_type' => $postType,
        ];
    }

    /**
     * Finds the post name for a given WordPress post ID by searching the XML export.
     *
     * Uses XpathHelper::findItemByPostId to locate the item, then extracts
     * post name and post type data for return.
     *
     * @param  string  $wordPressPostId  The WordPress post ID to search for
     * @param  string  $xmlPath  The path to the XML export file
     * @return PostData|null Post data if found, or null if not found
     */
    private function findPostNameById(
        string $wordPressPostId,
        string $xmlPath
    ): ?array {
        // Use shared utility method to find the item by post ID
        $item = XpathHelper::findItemByPostId(
            $xmlPath,
            $wordPressPostId
        );

        // Check if item was found
        if (! $item instanceof SimpleXMLElement) {
            // Return null if no matching item found
            return null;
        }

        // Extract post name using XpathHelper for safe extraction
        $wordPressPostName = XpathHelper::getStringValue(
            $item,
            'wp:post_name'
        );

        // Extract post type using XpathHelper for safe extraction
        $wordPressPostType = XpathHelper::getStringValue(
            $item,
            'wp:post_type'
        );

        // ---
        // Check if post type was successfully extracted (post name can be empty for draft
        // posts)
        // ---
        if ($wordPressPostType === '') {
            // Return null if post type is missing
            return null;
        }

        // Return the found post data
        return [
            'post_id' => $wordPressPostId,
            'post_name' => $wordPressPostName,
            'post_type' => $wordPressPostType,
        ];
    }

    /**
     * Fetches HTML content from a URL and extracts the page-id class value.
     *
     * Makes an HTTP request to the given URL and searches the HTML body class
     * for a class matching the pattern `/page-id-\d+/`. If found, extracts
     * the numeric value which represents the WordPress post ID.
     *
     * The URL comes from the WXR being imported, so the fetch is performed by
     * {@see GuardedHttpFetcher}, which refuses any hop targeting a non-public
     * address (wordpress-importer-archive#534).
     *
     * @param  string  $wordPressUrl  The URL to fetch HTML content from
     * @return ?string The extracted post ID if found, null otherwise
     */
    private function extractPageIdFromHtml(
        string $wordPressUrl
    ): ?string {
        // Fetch the body, re-validating every redirect hop against the egress
        // guard before its request is sent
        $html = GuardedHttpFetcher::fetchBody(
            $wordPressUrl,
            'Mozilla/5.0 (compatible; WordPress Import Tool)'
        );

        // Return null if the fetch was refused or failed
        if ($html === null) {
            return null;
        }

        // Search for the page-id class pattern in the HTML body class
        // Handle complex body tags with multiple classes and attributes
        if (
            \preg_match(
                '/<body[^>]*class="[^"]*?\bpage-id-(\d+)\b[^"]*"[^>]*>/i',
                $html,
                $matches
            ) === 1
        ) {
            // Return the extracted post ID
            return $matches[1];
        }

        // Return null if no page-id class was found
        return null;
    }
}
