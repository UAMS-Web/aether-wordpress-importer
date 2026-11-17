<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Traits;

use DOMElement;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Replaces a resolved WordPress URL in an entry's fields, for `LinkProcessor`.
 *
 * Rewrites the URL wherever it appears in string, Bard and Replicator values.
 */
trait LinkProcessorUrlReplacementTrait
{
    /**
     * Processes a resolved deferred link in content and page_builder fields.
     *
     * Replaces WordPress URLs with Statamic URLs in both content and
     * page_builder fields. Handles both array (Bard/replicator) and string
     * content types. Returns updated fields and flags indicating which fields
     * were modified.
     *
     * @param  mixed  $content  The content field value (array or string)
     * @param  mixed  $pageBuilder  The page_builder field value (array or string)
     * @param  string  $wordPressUrl  The original WordPress URL to replace
     * @param  string  $statamicUrl  The Statamic URL to replace with
     * @param  ?string  $fragment  The fragment identifier (optional)
     * @param  ?string  $query  The query string (optional)
     * @return array{
     *     content: mixed,
     *     page_builder: mixed,
     *     content_updated: bool,
     *     page_builder_updated: bool
     * }
     */
    public static function processResolvedLinkInFields(
        $content,
        $pageBuilder,
        string $wordPressUrl,
        string $statamicUrl,
        ?string $fragment = null,
        ?string $query = null
    ): array {
        // Initialize flags to track field updates
        $contentUpdated = false;
        $pageBuilderUpdated = false;

        // Check if the content is an array
        if (\is_array($content)) {
            // Recursively replace URLs in the content structure
            $content = self::replaceUrlInBardContent(
                $content,
                $wordPressUrl,
                $statamicUrl,
                $fragment,
                $query
            );

            // Mark that content has been updated
            $contentUpdated = true;
        } elseif (\is_string($content)) { // Otherwise, for string content
            // Use simple string replacement
            $content = self::replaceUrlInString(
                $wordPressUrl,
                $statamicUrl,
                $content
            );

            // Mark that content has been updated
            $contentUpdated = true;
        }

        // Check if the page_builder is an array
        if (\is_array($pageBuilder)) {
            // Recursively replace URLs in the page_builder replicator structure
            $pageBuilder = self::replaceUrlInReplicatorContent(
                $pageBuilder,
                $wordPressUrl,
                $statamicUrl,
                $fragment,
                $query
            );

            // Mark that page_builder has been updated
            $pageBuilderUpdated = true;
        }

        // Return updated fields and flags
        return [
            'content' => $content,
            'page_builder' => $pageBuilder,
            'content_updated' => $contentUpdated,
            'page_builder_updated' => $pageBuilderUpdated,
        ];
    }

    /**
     * Recursively replaces URLs in Bard content structure.
     *
     * This method traverses the Bard content array structure and replaces
     * WordPress URLs with Statamic URLs in text content and link attributes.
     * Also handles fragment and query attributes for link marks.
     *
     * @param  array<int|string, mixed>  $content  The Bard content array to process
     * @param  string  $oldUrl  The WordPress URL to replace
     * @param  string  $newUrl  The Statamic URL to replace with
     * @param  ?string  $fragment  The fragment identifier (optional)
     * @param  ?string  $query  The query string (optional)
     * @return array<int|string, mixed> The modified Bard content array
     */
    public static function replaceUrlInBardContent(
        array $content,
        string $oldUrl,
        string $newUrl,
        ?string $fragment = null,
        ?string $query = null
    ): array {
        // Loop through the content array
        foreach ($content as $key => $value) {
            // Check if the value is an array
            if (\is_array($value)) {
                // Check if this is a link mark with href attribute
                if (
                    isset($value['type'])
                    && $value['type'] === 'link'
                    && \is_array($value['attrs'] ?? null)
                    && isset($value['attrs']['href'])
                ) {
                    $attrs = $value['attrs'];

                    // Check if this link matches the old URL
                    if ($attrs['href'] === $oldUrl) {
                        // Replace the href with the new URL
                        $attrs['href'] = $newUrl;

                        // Add fragment attribute if fragment identifier exists
                        if ($fragment !== null && $fragment !== '') {
                            // Set the fragment attribute in the link mark
                            $attrs['fragment'] = $fragment;
                        }

                        // Add query attribute if query string exists
                        if ($query !== null && $query !== '') {
                            // Set the query attribute in the link mark
                            $attrs['query'] = $query;
                        }

                        $value['attrs'] = $attrs;
                        $content[$key] = $value;
                    }
                } elseif ( // Check if this is a text node with marks array containing links
                    isset($value['type'])
                    && $value['type'] === 'text'
                    && isset($value['marks'])
                    && \is_array($value['marks'])
                ) {
                    // Process each mark in the marks array
                    foreach ($value['marks'] as $markIndex => $mark) {
                        if (! \is_array($mark)) {
                            continue;
                        }

                        // Check if this mark is a link mark with href attribute
                        if (
                            isset($mark['type'])
                            && $mark['type'] === 'link'
                            && \is_array($mark['attrs'] ?? null)
                            && isset($mark['attrs']['href'])
                        ) {
                            $markAttrs = $mark['attrs'];

                            if ($markAttrs['href'] !== $oldUrl) {
                                continue;
                            }

                            // Replace the href with the new URL
                            $markAttrs['href'] = $newUrl;

                            // Add fragment attribute if fragment identifier exists
                            if ($fragment !== null && $fragment !== '') {
                                // Set the fragment attribute in the link mark
                                $markAttrs['fragment'] = $fragment;
                            }

                            // Add query attribute if query string exists
                            if ($query !== null && $query !== '') {
                                // Set the query attribute in the link mark
                                $markAttrs['query'] = $query;
                            }

                            $mark['attrs'] = $markAttrs;
                            $value['marks'][$markIndex] = $mark;
                            $content[$key] = $value;
                        }
                    }
                } else { // Otherwise
                    // Recursively process nested arrays
                    $content[$key] = self::replaceUrlInBardContent(
                        $value,
                        $oldUrl,
                        $newUrl,
                        $fragment,
                        $query
                    );
                }
            } elseif (\is_string($value)) { // Check if the value is a string
                // Replace URLs in string values
                $content[$key] = self::replaceUrlInString(
                    $oldUrl,
                    $newUrl,
                    $value
                );
            }
        }

        // Return the modified content array
        return $content;
    }

    /**
     * Recursively replaces URLs in replicator fieldtype content structure.
     *
     * This method traverses replicator fieldtype arrays (like page_builder) and
     * replaces WordPress URLs with Statamic URLs. Handles all field types within
     * replicator sets:
     *   - String fields (button_url, more_button_url, etc.) — simple string
     *     replacement
     *   - Bard fieldtype fields (body, block_description, etc.) — processed via
     *     replaceUrlInBardContent() to handle links in marks arrays
     *   - Nested replicator fieldtype fields (items arrays, etc.) — recursively
     *     processed
     *   - Other field types (text, textarea, etc.) — string replacement if applicable
     *
     * The method first checks if the current array is Bard content (single node or
     * array of nodes) and processes it accordingly. Otherwise, it processes the array
     * as a replicator structure, recursively handling nested fields.
     *
     * @param  array<int|string, mixed>  $content  The replicator content array to process (can be the entire page_builder array,
     *                                             a single replicator set, a field value, or nested structures)
     * @param  string  $oldUrl  The WordPress URL to replace
     * @param  string  $newUrl  The Statamic URL to replace with
     * @param  ?string  $fragment  The fragment identifier (optional)
     * @param  ?string  $query  The query string (optional)
     * @return array<int|string, mixed> The modified replicator content array
     */
    public static function replaceUrlInReplicatorContent(
        array $content,
        string $oldUrl,
        string $newUrl,
        ?string $fragment = null,
        ?string $query = null
    ): array {
        // ---
        // Check if this array is a single Bard node (has type/content or type/marks).
        // This handles individual Bard nodes that may be nested within replicator sets.
        // ---
        if (
            isset($content['type'])
            && (
                isset($content['content'])
                || isset($content['marks'])
            )
        ) {
            // Process as Bard content for proper link mark handling
            return self::replaceUrlInBardContent(
                $content,
                $oldUrl,
                $newUrl,
                $fragment,
                $query
            );
        }

        // ---
        // Check if this array is a Bard content array (array of Bard nodes). This handles
        // Bard fieldtype fields (like 'body' in uamsweb_block_content sets) which are
        // stored as arrays of nodes with numeric indices.
        // ---
        if (
            \array_is_list($content)
            && \is_array($content[0] ?? null)
            && isset($content[0]['type'])
        ) {
            // Process as Bard content array for proper link mark handling
            return self::replaceUrlInBardContent(
                $content,
                $oldUrl,
                $newUrl,
                $fragment,
                $query
            );
        }

        // ---
        // Otherwise, process as replicator structure (replicator sets or field values).
        //
        // This handles:
        //   - The top-level page_builder array (array of replicator sets)
        //   - Individual replicator sets (associative arrays with id, type, and field
        //     values)
        //   - Nested replicator fields (like 'items' arrays within sets)
        //   - Field values that are arrays but not Bard content
        // ---
        foreach ($content as $key => $value) {
            // ---
            // Check if the value is an array (replicator set, nested replicator, or Bard
            // field)
            // ---
            if (\is_array($value)) {
                // Recursively process nested arrays (will detect and handle Bard content,
                // nested replicators, etc.)
                $content[$key] = self::replaceUrlInReplicatorContent(
                    $value,
                    $oldUrl,
                    $newUrl,
                    $fragment,
                    $query
                );
            } elseif (\is_string($value)) { // Check if the value is a string (string field like button_url, text field, etc.)
                // Replace URLs in string values (handles URL fields like button_url,
                // more_button_url, text fields, textarea fields, etc.)
                $content[$key] = self::replaceUrlInString(
                    $oldUrl,
                    $newUrl,
                    $value
                );
            }
        }

        // Return the modified content array
        return $content;
    }

    /**
     * Replaces a URL in a string, in the form an `href` attribute holds it as
     * well as its raw one.
     *
     * A string can hold markup: each cell of a `table` set's `table_data` is
     * the cell's HTML as libxml wrote it. libxml writes an `href` escaped and
     * percent-encoded, so a query of `?a=1&b=2` is stored as `?a=1&amp;b=2`
     * and a space as `%20`, and a search for the URL as the DOM read it misses
     * it (aether-wordpress-importer#395). That attribute form is replaced with
     * the new URL in its own attribute form, so the markup stays well formed.
     * A URL whose attribute form is the URL itself is replaced only in its raw
     * form, and the new URL goes in as it is.
     *
     * An occurrence is replaced only where the URL ends: one followed by a
     * character that continues a path, such as a link to a child page, is a
     * different URL, and replacing its start would leave the rest of the path
     * behind the reference. A query or fragment after the URL still follows
     * the reference.
     *
     * @param  string  $oldUrl  The URL to replace
     * @param  string  $newUrl  The URL to replace it with
     * @param  string  $value  The string to search
     * @return string The string with every occurrence replaced
     */
    private static function replaceUrlInString(
        string $oldUrl,
        string $newUrl,
        string $value
    ): string {
        // Write the old URL as libxml writes it in an href attribute
        $attributeOldUrl = self::hrefAttributeForm($oldUrl);

        // Replace that form, where it differs from the URL
        if ($attributeOldUrl !== $oldUrl) {
            $value = self::replaceWholeUrl(
                $attributeOldUrl,
                self::hrefAttributeForm($newUrl),
                $value
            );
        }

        // Replace the raw form
        return self::replaceWholeUrl(
            $oldUrl,
            $newUrl,
            $value
        );
    }

    /**
     * Returns a URL as libxml writes it in an `href` attribute, the form a
     * table cell's HTML holds it in.
     *
     * A link to the URL is loaded with {@see TextProcessor::loadHtml()}, the
     * loader a page's tables are read through, and serialized, so the result
     * is byte for byte what `TableProcessor` stores in a cell. How libxml
     * writes a non-ASCII character depends on the document's encoding and on
     * the libxml build, so a document of any other kind can disagree with the
     * cell. So can the quoting: a URL holding `"` is written `&quot;` inside
     * double quotes by some builds and raw inside single quotes by others
     * (measured on Windows CI), so either quote is read. Where the
     * serialization is not the expected `<a href="...">` or `<a href='...'>`,
     * the URL is returned unchanged, and only its raw form is searched for.
     *
     * @param  string  $url  The URL as the DOM reads it
     * @return string The URL as an href attribute value
     */
    private static function hrefAttributeForm(
        string $url
    ): string {
        // Load a link to the URL as a page is loaded
        $document = TextProcessor::loadHtml(
            '<a href="'.\htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"></a>'
        );

        $link = $document->getElementsByTagName('a')->item(0);

        // Serialize the link as a cell's HTML is serialized
        $html = $link instanceof DOMElement ? $document->saveHTML($link) : false;

        // Take the attribute value out of the markup, in whichever quotes it is in
        if (
            ! \is_string($html)
            || \preg_match('/\A<a href=(["\'])(.*)\1><\/a>\z/s', $html, $matches) !== 1
        ) {
            return $url;
        }

        return $matches[2];
    }

    /**
     * Replaces each occurrence of a URL that is not followed by a character
     * continuing its path: a letter, a digit, a byte of a non-ASCII
     * character, or one of `-._~%/`.
     *
     * @param  string  $oldUrl  The URL to replace
     * @param  string  $newUrl  The URL to replace it with
     * @param  string  $value  The string to search
     * @return string The string with every whole occurrence replaced
     */
    private static function replaceWholeUrl(
        string $oldUrl,
        string $newUrl,
        string $value
    ): string {
        // An empty URL matches nowhere in particular, so it replaces nothing
        if ($oldUrl === '') {
            return $value;
        }

        // Match the URL literally, unless a path character follows it
        $replaced = \preg_replace_callback(
            '/'.\preg_quote($oldUrl, '/').'(?![A-Za-z0-9\x80-\xFF\-._~%\/])/',
            static fn (): string => $newUrl,
            $value
        );

        // A regex error, which a literal pattern cannot raise, leaves the string as it was
        return $replaced ?? $value;
    }
}
