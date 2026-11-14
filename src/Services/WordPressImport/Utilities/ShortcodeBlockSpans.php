<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;

/**
 * Finds the spans of `wp:shortcode` and `wp:gallery` blocks in content being built
 * for Bard, so text outside them can be matched and replaced on its own, and
 * links inside them can be dropped.
 *
 * Split out of {@see TextProcessor} so each file's mutation run fits the CI limit
 * (#549). {@see TextProcessor::processForBard()} is the entry point.
 *
 * @internal
 */
final class ShortcodeBlockSpans
{
    /**
     * Matches a `wp:shortcode` block, whose shortcodes ShortcodeBlockProcessor
     * converts.
     */
    public const string SHORTCODE_BLOCK_PATTERN = '/<!--\s*wp:shortcode\s*-->.*?<!--\s*\/wp:shortcode\s*-->/is';

    /**
     * Splits content into the text of its `wp:shortcode` blocks and the text
     * between them, in order.
     *
     * @param  string  $content  The content to split
     * @return list<array{string, bool}> Each piece of text, and whether it is a block
     */
    private static function splitOnShortcodeBlocks(
        string $content
    ): array {
        // Initialize the pieces and the offset the next one starts at
        $pieces = [];
        $offset = 0;

        // Take the text before each block, then the block itself
        foreach (self::extractBlockPositions($content, self::SHORTCODE_BLOCK_PATTERN) as [$blockStart, $blockEnd]) {
            $pieces[] = [\substr($content, $offset, $blockStart - $offset), false];
            $pieces[] = [\substr($content, $blockStart, $blockEnd - $blockStart), true];
            $offset = $blockEnd;
        }

        // Take the text after the last block
        $pieces[] = [\substr($content, $offset), false];

        return $pieces;
    }

    /**
     * Checks whether text occurs in content outside every `wp:shortcode` block.
     *
     * @param  string  $content  The content to search
     * @param  string  $needle  The text to find
     * @return bool True when some copy of the text lies between blocks
     */
    public static function occursOutsideShortcodeBlocks(
        string $content,
        string $needle
    ): bool {
        // Look for the text in each piece that is not a block
        foreach (self::splitOnShortcodeBlocks($content) as [$text, $isBlock]) {
            if (! $isBlock && \str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Applies a replacement to the content outside its `wp:shortcode` blocks,
     * leaving the blocks as they are.
     *
     * @param  string  $content  The content to change
     * @param  callable(string): string  $replace  The replacement for a piece between blocks
     * @return string The content with the pieces between blocks replaced
     */
    public static function replaceOutsideShortcodeBlocks(
        string $content,
        callable $replace
    ): string {
        // Replace each piece that is not a block, and rejoin them in order
        return \implode('', \array_map(
            static fn (array $piece): string => $piece[1] ? $piece[0] : $replace($piece[0]),
            self::splitOnShortcodeBlocks($content)
        ));
    }

    /**
     * Extracts block positions from content using a regex pattern.
     *
     * This helper method provides a DRY way to extract block positions for various
     * WordPress block types (e.g., shortcode, gallery) to prevent duplicate
     * processing or unwanted processing by other processors.
     *
     * @param  string  $content  The content to search for blocks
     * @param  string  $pattern  The regex pattern to match blocks
     * @return array<int, array{int, int}> Block start/end offsets
     */
    public static function extractBlockPositions(
        string $content,
        string $pattern
    ): array {
        // Initialize array to store block positions
        $blockPositions = [];

        // Find the blocks matching the pattern; none, or a failed match, leaves no
        // matches to read
        preg_match_all(
            $pattern,
            $content,
            $blockMatches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        // Process each found block
        foreach ($blockMatches as $blockMatch) {
            // Get the start position of the block
            $blockStart = $blockMatch[0][1];

            // Calculate the end position of the block
            $blockEnd = $blockStart + \strlen($blockMatch[0][0]);

            // Store the block position for later reference
            $blockPositions[] = [
                $blockStart,
                $blockEnd,
            ];

            // Clean up variables to free up memory
            unset(
                $blockMatch,
                $blockStart,
                $blockEnd
            );
        }

        // Return the collected block positions
        return $blockPositions;
    }

    /**
     * Removes href attributes from excluded blocks to prevent LinkProcessor from
     * processing them.
     *
     * This method uses the block positions to remove href attributes from <a> tags
     * within those blocks. This prevents the LinkProcessor from treating links
     * within these blocks as file downloads. These blocks should be handled by
     * their specific processors instead.
     *
     * @param  string  $content  The HTML content to process
     * @param  array<int, array{int, int}>  $excludedBlocks  Block offsets
     * @return string The content with href attributes removed from excluded blocks
     */
    public static function removeHrefFromExcludedBlocks(
        string $content,
        array $excludedBlocks
    ): string {
        // Process blocks in reverse order to maintain correct positions
        $excludedBlocks = \array_reverse($excludedBlocks);

        // Loop through each excluded block to remove href attributes
        foreach ($excludedBlocks as $block) {
            // Extract block start and end positions
            [$blockStart, $blockEnd] = $block;

            // Extract the block content
            $blockContent = \substr(
                $content,
                $blockStart,
                $blockEnd - $blockStart
            );

            // Replace the original block with one that holds no href
            $content = substr_replace(
                $content,
                self::withoutHrefs($blockContent),
                $blockStart,
                $blockEnd - $blockStart
            );
        }

        return $content;
    }

    /**
     * Removes href attributes from the shortcodes in `wp:shortcode` blocks, leaving
     * the links in the text beside them for LinkProcessor.
     *
     * @param  string  $content  The HTML content to process
     * @param  array<int, array{int, int}>  $blocks  Block offsets, in order
     * @param  ShortcodeProcessor  $shortcodeProcessor  Finds the shortcodes in a block
     * @param  OutputInterface  $output  Console output for logging
     * @return string The content with href attributes removed from those shortcodes
     */
    public static function removeHrefFromShortcodes(
        string $content,
        array $blocks,
        ShortcodeProcessor $shortcodeProcessor,
        OutputInterface $output
    ): string {
        // Process blocks in reverse order to maintain correct positions
        foreach (\array_reverse($blocks) as [$blockStart, $blockEnd]) {
            $blockContent = \substr(
                $content,
                $blockStart,
                $blockEnd - $blockStart
            );
            $modifiedBlock = $blockContent;

            // Replace each shortcode with a copy that holds no href
            foreach ($shortcodeProcessor->extract($blockContent, $output) as $shortcode) {
                $original = \is_array($shortcode) ? ($shortcode['original'] ?? null) : null;

                if (\is_string($original)) {
                    $modifiedBlock = \str_replace(
                        $original,
                        self::withoutHrefs($original),
                        $modifiedBlock
                    );
                }
            }

            $content = substr_replace(
                $content,
                $modifiedBlock,
                $blockStart,
                $blockEnd - $blockStart
            );
        }

        return $content;
    }

    /**
     * Removes the href attribute from each <a> tag in HTML.
     *
     * @param  string  $html  The HTML
     * @return string The HTML with no href, or as it was if the pattern fails
     */
    private static function withoutHrefs(
        string $html
    ): string {
        return \preg_replace(
            '/<a\s+([^>]*?)href\s*=\s*["\'][^"\']*["\']([^>]*?)>/i',
            '<a $1$2>',
            $html
        ) ?? $html;
    }
}
