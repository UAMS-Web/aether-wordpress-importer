<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Scans an export for content the import cannot carry: Classic, Freeform and Details blocks, knowledge base entries without topics, TablePress tables and ACF block types.
 *
 * Part of the `statamic:import:wordpress` command, split out of
 * {@see ImportWordPress} so that each file's
 * mutation run fits the CI limit (#546). The methods run on the command and use its
 * properties and output.
 */
trait ScansImportContent
{
    /**
     * Scan the selected content type for mixed Classic and Gutenberg block content.
     *
     * Checks each item's <content:encoded> for the presence of both Gutenberg block
     * comments (<!-- wp:) and freeform/classic content to identify mixed content.
     * Only warns when there's a mix - pure freeform/classic content is handled
     * normally with wpautop. Skips scanning for 'tablepress_table' and 'uamspeople'
     * content types, as their content is not intended for Bard parsing. Returns an
     * array of affected pages with details.
     *
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @return list<array{title: string, id: string, link: string}> Pages with mixed Classic and Gutenberg content
     */
    private function scanForClassicBlocks(
        string $contentType
    ): array {
        // Name the pages mixing Gutenberg blocks with freeform content. Content that is
        // all blocks or all freeform imports normally.
        return $this->pagesWhoseContent(
            $contentType,
            fn (string $content): bool => \preg_match(
                '/<!--\s*wp:/',
                $content
            ) === 1
                && $this->hasFreeformContentOutsideBlocks($content)
        );
    }

    /**
     * Check if content contains freeform content outside of Gutenberg blocks.
     *
     * Determines if the content has freeform HTML or text that exists outside of
     * Gutenberg block comments: text, or an element that shows something without
     * text of its own, such as an image. Markup with neither, such as a stray closing
     * tag, a `<br>` or a `<p>&nbsp;</p>`, has nothing to lose, so it does not count.
     * Used for detecting mixed content scenarios where Gutenberg blocks and freeform
     * content coexist.
     *
     * @param  string  $content  The content to analyze
     * @return bool True if freeform content outside blocks is present, false otherwise
     */
    private function hasFreeformContentOutsideBlocks(
        string $content
    ): bool {
        // Remove all Gutenberg blocks using the improved method
        $contentWithoutBlocks = $this->removeAllGutenbergBlocks($content);

        // Return true if what remains after block removal is more than empty markup
        return $this->holdsMoreThanEmptyMarkup($contentWithoutBlocks);
    }

    /**
     * Check if markup holds text, or an element that shows something without text.
     *
     * @param  string  $markup  The markup to check
     * @return bool True if the markup holds something a reader would see
     */
    private function holdsMoreThanEmptyMarkup(
        string $markup
    ): bool {
        // An element that shows something counts, whatever text surrounds it
        if (\preg_match('~<(?:audio|canvas|embed|iframe|img|object|svg|video)\b~i', $markup) === 1) {
            return true;
        }

        // Drop comments and tags. A `<` that opens neither stays, since it may be text.
        $withoutTags = \preg_replace('~<!--.*?-->|</?[a-z][^>]*>~is', '', $markup);

        // Decode entities, keeping the markup if the match failed, since it may hold text
        $decoded = \html_entity_decode($withoutTags ?? $markup);

        // Whitespace, which covers a non-breaking space here, and zero-width spaces are not text
        $text = \preg_replace('~[\s\x{200B}]+~u', '', $decoded);

        // Return true if anything remains, including text the last pass could not read
        return ($text ?? $decoded) !== '';
    }

    /**
     * Remove all Gutenberg blocks from content using a comprehensive approach.
     *
     * This method properly handles all types of Gutenberg blocks including:
     *   - Self-closing blocks: <!-- wp:block /-->
     *   - Opening/closing block pairs: <!-- wp:block --> content <!-- /wp:block -->
     *   - Nested blocks with proper matching of open/close tags
     *
     * IMPORTANT: The removal order is critical for proper nested block handling.
     * Opening/closing block pairs must be removed first, then self-closing blocks.
     * Removing self-closing blocks first would change the positions of
     * opening/closing pairs and break the position calculations for nested blocks.
     *
     * @param  string  $content  The content to process
     * @return string Content with all Gutenberg blocks removed
     */
    private function removeAllGutenbergBlocks(
        string $content
    ): string {
        // Handle opening/closing block pairs with proper nesting support first
        $content = $this->removeNestedGutenbergBlocks($content);

        // ---
        // Then remove, in order, all self-closing blocks (e.g., <!-- wp:acf/uams-content
        // {...} /-->), then any remaining opening and closing block comments (malformed or
        // incomplete). preg_replace() answers null only on a matching error, and with PCRE's
        // JIT on, as it is by default, the backtrack limit does not apply, so the fallback
        // only types the result.
        // ---
        $content = \preg_replace(
            [
                '/<!--\s*wp:(?:(?!-->).)*?\/\s*-->/s',
                '/<!--\s*wp:.*?-->/s',
                '/<!--\s*\/wp:.*?-->/s',
            ],
            '',
            $content
        );

        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return $content ?? '';
    }

    /**
     * Remove nested Gutenberg blocks by properly matching open and close comment tags.
     *
     * This method finds all Gutenberg block comment tags, matches opening tags with
     * their corresponding closing tags, and removes the entire block content
     * including any nested blocks within them. Only the outermost spans are cut: a
     * span lying inside another was measured on content that cutting the other would
     * change.
     *
     * @param  string  $content  The content to process
     * @return string Content with nested Gutenberg blocks removed
     */
    private function removeNestedGutenbergBlocks(
        string $content
    ): string {
        // Find all Gutenberg block comment positions. An unmatched opening tag has
        // length 0, so cutting it removes nothing.
        $blockPositions = $this->findGutenbergBlockPositions($content);

        // Sort positions by start position, so each span's outer block comes first
        usort(
            $blockPositions,
            fn (array $a, array $b): int => $a['start'] - $b['start']
        );

        // ---
        // Merge the spans, so a span inside another, or crossing its end where the
        // tags overlap, is cut as part of it
        // ---

        // Initialize the merged spans, as start and end, in order
        $spans = [];

        // Loop through block positions to merge each into the spans
        foreach ($blockPositions as $block) {
            $end = $block['start'] + $block['length'];
            $last = \array_key_last($spans);

            // Spans that touch are cut alike whether merged or apart
            // @pest-mutate-ignore: GreaterToGreaterOrEqual
            if ($last === null || $block['start'] > $spans[$last]['end']) {
                $spans[] = ['start' => $block['start'], 'end' => $end];
            } else {
                $spans[$last]['end'] = \max($spans[$last]['end'], $end);
            }
        }

        // ---
        // Remove spans from end to beginning to maintain position accuracy
        // ---

        // Loop through the spans to remove each one
        foreach (\array_reverse($spans) as $span) {
            // Remove span from content using substr_replace
            $content = substr_replace(
                $content,
                '',
                $span['start'],
                $span['end'] - $span['start']
            );
        }

        // Return content with nested Gutenberg blocks removed
        return $content;
    }

    /**
     * Find all Gutenberg block positions in content, properly matching open/close
     * tags.
     *
     * This method identifies all Gutenberg block comment tags and matches opening
     * tags with their corresponding closing tags, handling nested blocks correctly.
     * It returns positions that include the entire block content (comments + HTML).
     *
     * @param  string  $content  The content to analyze
     * @return list<array{start: int, length: int, type: string}> Block positions with start, length, and type
     */
    private function findGutenbergBlockPositions(
        string $content
    ): array {
        // Initialize array to store block positions
        $blockPositions = [];

        // Initialize stack to track opening tags for nested blocks
        $openTagStack = [];

        // Find all Gutenberg block comment tags
        preg_match_all(
            '/<!--\s*(\/?wp:[^\s>]+(?:\s+[^>]*)?)\s*(?:\/)?\s*-->/',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        // Loop through all matched block tags
        foreach ($matches[0] as $index => $match) {
            // Extract the full matched comment string
            $fullMatch = $match[0];

            // Extract the position of the match in the content
            $position = $match[1];

            // Extract the block name from the match
            $blockName = $matches[1][$index][0];

            // Check if this is a self-closing tag
            // Self-closing blocks end with " /-->" (space-slash-dash-dash-greater)
            if (
                \preg_match(
                    '/\/\s*-->/',
                    $fullMatch
                ) === 1
            ) {
                // Self-closing block - add to positions immediately
                $blockPositions[] = [
                    'start' => $position,
                    'length' => \strlen($fullMatch),
                    'type' => 'self-closing',
                ];
            } elseif (
                str_contains(
                    $fullMatch,
                    '/wp:'
                )
            ) { // Check if this is a closing tag
                // Closing tag - find matching opening tag. An anchored pattern over a tag
                // name cannot fail, so the fallback only types the result.
                $blockType = \preg_replace(
                    '/^\/wp:/',
                    '',
                    $blockName
                );

                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $blockType = \trim($blockType ?? '');

                // Initialize index for matching opening tag
                $matchingOpenIndex = null;

                // Look for the nearest matching opening tag in the stack
                foreach (\array_reverse($openTagStack, true) as $stackIndex => $openTag) {
                    // Check if this opening tag matches the closing tag type
                    if ($openTag['type'] === $blockType) {
                        // Store index of matching opening tag
                        $matchingOpenIndex = $stackIndex;

                        // Break out of the loop
                        break;
                    }
                }

                // Check if a matching opening tag was found
                if ($matchingOpenIndex !== null) {
                    // Get the matching opening tag from stack
                    $openTag = $openTagStack[$matchingOpenIndex];

                    // Calculate total block length including opening and closing tags
                    $blockLength = $position + \strlen($fullMatch) - $openTag['start'];

                    // Add the complete block to positions (including all content between tags)
                    $blockPositions[] = [
                        'start' => $openTag['start'],
                        'length' => $blockLength,
                        'type' => 'opening-closing',
                    ];

                    // Remove the matched opening tag from stack
                    array_splice(
                        $openTagStack,
                        $matchingOpenIndex,
                        1
                    );
                }
            } else { // Otherwise
                // ---
                // Opening tag - add to stack
                // ---

                // Extract block type by removing wp: prefix. Neither pattern over a tag name
                // can fail, so each fallback only types the result.
                $blockType = \preg_replace(
                    '/^wp:/',
                    '',
                    $blockName
                );

                // Remove attributes
                $blockType = \preg_replace(
                    '/\s+.*$/',
                    '',
                    // @pest-mutate-ignore: EmptyStringToNotEmpty
                    $blockType ?? ''
                );

                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $blockType ??= '';

                // Add opening tag to stack for later matching
                $openTagStack[] = [
                    'start' => $position,
                    'type' => $blockType,
                ];
            }
        }

        // ---
        // Add any unmatched opening tags to positions (malformed blocks)
        // ---

        // Loop through unmatched opening tags
        foreach ($openTagStack as $openTag) {
            // Add unmatched opening tag to positions with zero length
            $blockPositions[] = [
                'start' => $openTag['start'],
                'length' => 0, // Will be handled by the fallback regex cleanup
                'type' => 'unmatched-opening',
            ];
        }

        // Return array of all block positions found
        return $blockPositions;
    }

    /**
     * Scan the selected content type for Details blocks.
     *
     * Checks each item's <content:encoded> for wp:details blocks to identify
     * Details block content. Details blocks create collapsible content sections
     * with summary text. Skips scanning for 'tablepress_table' and 'uamspeople'
     * content types, as their content is not intended for Bard parsing. Returns
     * an array of affected pages with details.
     *
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @return list<array{title: string, id: string, link: string}> Pages with Details blocks
     */
    private function scanForDetailsBlocks(
        string $contentType
    ): array {
        // Name the pages holding a Details block: wp:details
        return $this->pagesWhoseContent(
            $contentType,
            fn (string $content): bool => \preg_match(
                '/<!--\s*wp:details\b/',
                $content
            ) === 1
        );
    }

    /**
     * Scan the selected content type for Freeform blocks.
     *
     * Checks each item's <content:encoded> for wp:freeform blocks to identify
     * Freeform block content. Freeform blocks allow unrestricted HTML content
     * that bypasses Gutenberg's block restrictions. Skips scanning for
     * 'tablepress_table' and 'uamspeople' content types, as their content is
     * not intended for Bard parsing. Returns an array of affected pages with
     * details.
     *
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @return list<array{title: string, id: string, link: string}> Pages with Freeform blocks
     */
    private function scanForFreeformBlocks(
        string $contentType
    ): array {
        // Name the pages holding a Freeform block: wp:freeform
        return $this->pagesWhoseContent(
            $contentType,
            fn (string $content): bool => \preg_match(
                '/<!--\s*wp:freeform\b/',
                $content
            ) === 1
        );
    }

    /**
     * Scan the selected content type for kb entries without associated topics.
     *
     * @param  string  $contentType  The selected content type (e.g., 'kb')
     * @return list<array{title: string, id: string, link: string}> KB entries without associated topics
     */
    private function scanForKbWithoutTopics(
        string $contentType
    ): array {
        // Initialize an array to store entries without associated topics
        $kbWithoutTopics = [];

        // Iterate through each item to check for missing topics
        foreach ($this->xmlParser->getItems($contentType) as $item) {
            // Check for category elements with domain='kbtopic' to identify associated topics
            $categories = $item->xpath("category[@domain='kbtopic']");

            // ---
            // Check if there are no categories with domain='kbtopic', indicating no
            // associated topic
            // ---
            if ($categories === null || $categories === []) {
                // Add the entry details to the list of entries without topics
                $kbWithoutTopics[] = $this->pageSummary($item);
            }
        }

        // Return the list of entries without associated topics
        return $kbWithoutTopics;
    }

    /**
     * Name the pages of a content type whose content passes the given check.
     *
     * Skips 'tablepress_table' and 'uamspeople' content types, as their content is
     * not intended for Bard parsing.
     *
     * @param  string  $contentType  The selected content type (e.g., 'page', 'post')
     * @param  callable(string): bool  $holds  Whether an item's encoded content holds what is looked for
     * @return list<array{title: string, id: string, link: string}> The pages whose content holds it
     */
    private function pagesWhoseContent(
        string $contentType,
        callable $holds
    ): array {
        // Skip content types whose content is not Bard. The list holds only
        // strings, so the strict comparison only matches what a loose one would.
        // @pest-mutate-ignore: TrueToFalse
        if (
            \in_array(
                $contentType,
                [
                    'tablepress_table',
                    'uamspeople',
                ],
                true
            )
        ) {
            return [];
        }

        // Initialize array to store the pages found
        $pages = [];

        // Loop through items to check each one's encoded content
        foreach ($this->xmlParser->getItems($contentType) as $item) {
            // Check the encoded content from the WordPress XML item
            if (
                $holds(XpathHelper::getStringValue(
                    $item,
                    'content:encoded'
                ))
            ) {
                // Add the page to the list
                $pages[] = $this->pageSummary($item);
            }
        }

        // Return the pages found
        return $pages;
    }

    /**
     * Summarize an export item as the title, post ID and permalink a warning names it by.
     *
     * @param  \SimpleXMLElement  $item  The export item
     * @return array{title: string, id: string, link: string} The item's summary
     */
    private function pageSummary(
        \SimpleXMLElement $item
    ): array {
        // Decode HTML entities in the title and provide fallback
        $decodedTitle = html_entity_decode(
            (string) $item->title,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // Name the page by its title, its WordPress post ID and its permalink
        return [
            'title' => $decodedTitle !== '' ? $decodedTitle : 'Untitled',
            'id' => XpathHelper::getStringValue(
                $item,
                'wp:post_id'
            ),
            'link' => (string) $item->link,
        ];
    }

    /**
     * Check if the content type contains TablePress shortcodes or blocks.
     *
     * Scans <content:encoded> for [table id=X /] shortcodes or wp:tablepress/table
     * blocks to determine if table imports are needed for content types like 'page'
     * or 'post'. Returns false for 'tablepress_table' content, as it does not
     * require shortcode/block detection. Normalizes content by decoding HTML
     * entities and trimming whitespace for accurate detection.
     *
     * @param  string  $contentType  The content type to scan (e.g., 'page', 'post')
     * @return bool True if TablePress content is found, false otherwise
     */
    private function hasTablePressContent(
        string $contentType
    ): bool {
        // Check if content type is tablepress_table
        if ($contentType === 'tablepress_table') {
            // Skip scanning for tablepress_table content type
            return false;
        }

        // Retrieve items for the content type
        $items = $this->xmlParser->getItems($contentType);

        // Loop through each item to check for TablePress content
        foreach ($items as $item) {
            // Extract the encoded content from the WordPress XML item
            $contentEncoded = XpathHelper::getStringValue(
                $item,
                'content:encoded'
            );

            // Decode HTML entities in the content
            $contentEncoded = html_entity_decode(
                $contentEncoded,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            // Check for TablePress shortcodes or blocks
            $hasShortcode = \preg_match(
                '/\[table\s+id=/i',
                $contentEncoded
            ) === 1;

            // Check for TablePress Gutenberg blocks
            $hasBlock = \preg_match(
                '/<!--\s*wp:tablepress\/table\b/',
                $contentEncoded
            ) === 1;

            // If TablePress content is found, return true immediately
            if (
                $hasShortcode
                || $hasBlock
            ) {
                // TablePress content detected
                return true;
            }
        }

        // No TablePress content found in any items
        return false;
    }

    /**
     * Check if a specific ACF block type is present in the content of a given
     * content type.
     *
     * Scans the <content:encoded> of items for the specified ACF block
     * (e.g., 'wp:acf/logo-list') to determine if the associated taxonomy is relevant.
     *
     * @param  string  $contentType  The content type to scan (e.g., 'page')
     * @param  string  $acfBlockName  The ACF block name (e.g., 'logo-list', 'fad-providers')
     * @return bool True if the ACF block is found in any item's content, false otherwise
     */
    private function hasAcfBlockType(
        string $contentType,
        string $acfBlockName
    ): bool {
        // Retrieve items for the content type
        $items = $this->xmlParser->getItems($contentType);

        // Loop through each item to check for ACF block content
        foreach ($items as $item) {
            // Extract the encoded content from the WordPress XML item
            $contentEncoded = XpathHelper::getStringValue(
                $item,
                'content:encoded'
            );

            // Check if the content contains the specified ACF block
            if (
                \preg_match(
                    "/<!--\s*wp:acf\/{$acfBlockName}\s/",
                    $contentEncoded
                ) === 1
            ) {
                // ACF block found in content
                return true;
            }
        }

        // No ACF block found in any items
        return false;
    }
}
