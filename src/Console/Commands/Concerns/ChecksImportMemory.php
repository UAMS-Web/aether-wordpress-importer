<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizedStream;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

use function Laravel\Prompts\select;

/**
 * Parses the export and checks that the PHP memory limit can hold it, offering to restart with more.
 *
 * Part of the `statamic:import:wordpress` command, split out of
 * {@see ImportWordPress} so that each file's
 * mutation run fits the CI limit (#546). The methods run on the command and use its
 * properties and output.
 */
trait ChecksImportMemory
{
    /**
     * Parse the XML part(s) using streaming parser for memory efficiency.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     *
     * @throws \Exception If parsing fails
     */
    private function parseXmlFile(
        array $parts
    ): void {
        // Notify user that streaming parser is being used
        $this->info('Using streaming parser for memory efficiency.');

        // Parse large XML part(s) using streaming parser
        $this->parseLargeXmlFile($parts);
    }

    /**
     * Parse large XML part(s) using streaming parser.
     *
     * Streams every part so content-type counts are summed across the whole
     * part-set, while channel-level metadata (base blog URL) is taken from the
     * first part only, since it is duplicated in every part.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     *
     * @throws \Exception If parsing fails
     */
    private function parseLargeXmlFile(
        array $parts
    ): void {
        // Initialize a streaming parser spanning every part for content-type counts
        $streamParser = new XmlStreamParser($parts);

        // Initialize array to track content type counts
        $contentTypes = [];

        // Count all content types dynamically by streaming through items in every part
        foreach ($streamParser->streamAllItems() as $item) {
            // Extract the post type from the WordPress XML item
            $postType = XpathHelper::getStringValue(
                $item,
                'wp:post_type'
            );

            // Check if post type is not empty before counting
            if ($postType !== '') {
                // Increment the count for this post type
                $contentTypes[$postType] = ($contentTypes[$postType] ?? 0) + 1;
            }

            // Clean up variables to free up memory
            unset(
                $item
            );
        }

        // Set the content types in the regular parser for compatibility
        $this->xmlParser->setContentTypes($contentTypes);

        // Set every part path in the regular parser (item readers span all parts)
        $this->xmlParser->setFilePaths($parts);

        // Get base blog URL from a quick XMLReader scan of the first part
        $this->xmlParser->setBaseBlogUrl($this->getBaseBlogUrlFromStream($parts[0]));

        // Clean up variables to free up memory
        unset(
            $streamParser,
            $contentTypes
        );

        // Force garbage collection to reclaim memory from circular references. Collecting
        // changes no result.
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();
    }

    /**
     * Get base blog URL from XML file using streaming.
     *
     * @param  string  $filePath  Path to the XML file
     * @return string Base blog URL
     */
    private function getBaseBlogUrlFromStream(
        string $filePath
    ): string {
        // Open XML file for reading with appropriate flags
        $reader = \XMLReader::open(
            WxrSanitizedStream::wrap($filePath),
            null,
            LIBXML_NOCDATA
        );

        if ($reader === false) {
            return '';
        }

        // Wrap reading in try-finally to ensure cleanup
        try {
            // Loop through XML nodes
            while ($reader->read()) {
                // Check if current node is the base_blog_url element
                if (
                    $reader->nodeType === \XMLReader::ELEMENT
                    && $reader->name === 'wp:base_blog_url'
                ) {
                    // Read the element value
                    $reader->read();

                    // Extract URL value from the element
                    $url = $reader->value;

                    // Return the base blog URL
                    return $url;
                }
            }
        } finally {
            // Close the XMLReader to free resources. Closing changes no result.
            // @pest-mutate-ignore: RemoveMethodCall
            $reader->close();

            // Clean up variables to free up memory
            unset($reader);
        }

        // Return empty string if base blog URL not found
        return '';
    }

    /**
     * Check memory requirements and prompt user if needed.
     *
     * Analyzes the XML file to find the largest content items and current PHP
     * memory limit to determine if the import process might encounter memory
     * issues. Prompts the user to restart the command with increased memory
     * if needed.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     */
    private function checkMemoryRequirements(
        array $parts
    ): void {
        // Get current memory limit
        $currentMemoryLimit = $this->memoryLimit();

        // Convert memory limit string to bytes for comparison
        $currentMemoryBytes = $this->convertToBytes($currentMemoryLimit);

        // Display current memory limit to user
        $this->info("Current memory limit: {$currentMemoryLimit}");

        // ---
        // Calculate dynamic threshold based on current memory and multiplier
        // ---

        // Set multiplier for calculating recommended memory based on largest item
        $largestItemSizeMultiplier = 500;

        // Calculate threshold in bytes for considering items "large"; whole bytes,
        // since analyzeContentItemSizes() takes an int
        $dynamicThresholdBytes = \intdiv($currentMemoryBytes, $largestItemSizeMultiplier);

        // ---
        // Analyze content item sizes using streaming parser
        // ---

        // Notify user that content analysis is starting
        $this->info('Analyzing content item sizes...');

        // ---
        // Analyze every part, tracking the largest single item and total large-item
        // count. Only one part is read at a time, so peak memory stays bounded by the
        // largest single part rather than the combined part-set size.
        // ---

        // Initialize the largest item size seen across all parts. Every part is measured,
        // and a measurement is never negative, so a start above zero shows only below a
        // byte, which reads as 0MB either way.
        // @pest-mutate-ignore: IncrementInteger
        $largestItemSize = 0;

        // Initialize the total count of large items across all parts
        $largeItemsCount = 0;

        // Analyze each part in turn, folding its results into the running totals
        foreach ($parts as $part) {
            // Analyze this part's content item sizes to find its largest items
            $contentAnalysis = $this->analyzeContentItemSizes(
                $part,
                $dynamicThresholdBytes
            );

            // Keep the largest single item seen across every analyzed part
            $largestItemSize = max(
                $largestItemSize,
                $contentAnalysis['largestItemSize']
            );

            // Accumulate the count of large items across every analyzed part
            $largeItemsCount += $contentAnalysis['largeItemsCount'];
        }

        // ---
        // Calculate recommended memory based on largest content items
        // ---

        // Convert largest item size to megabytes for display
        $largestItemSizeMB = round(
            $largestItemSize / 1024 / 1024,
            2
        );

        // Calculate recommended memory in bytes based on largest item
        $recommendedMemoryBytes = $largestItemSize * $largestItemSizeMultiplier; // Multiplied for XML parsing overhead and processing

        // Convert recommended memory to megabytes for display
        $recommendedMemoryMB = round(
            $recommendedMemoryBytes / 1024 / 1024,
            2
        );

        // Convert dynamic threshold to megabytes for display
        $dynamicThresholdMB = round(
            $dynamicThresholdBytes / 1024 / 1024,
            2
        );

        // Display largest content item size to user
        $this->info("Largest content item: {$largestItemSizeMB}MB");

        // Check if we need more memory
        if ($currentMemoryBytes < $recommendedMemoryBytes) {
            // ---
            // Warn user about the large items found. An item needing more than the limit
            // is larger than the limit's threshold, so at least one was counted.
            // ---
            $this->warn("Found {$largeItemsCount} content items larger than {$dynamicThresholdMB}MB.");

            // Warn user about potential memory issues
            $this->warn('These items may cause memory exhaustion during XML parsing and processing.');

            // Warn user about large content items and recommended memory
            $this->warn("Large content items detected. Recommended memory: {$recommendedMemoryMB}MB");

            // Warn user that current memory limit may be insufficient
            $this->warn("Current memory limit ({$currentMemoryLimit}) may be insufficient for this import.");

            // Calculate suggested memory
            $suggestedMemory = $this->getSuggestedMemory($recommendedMemoryMB);

            // ---
            // Provide options to the user
            // ---

            // Define options for user to choose from
            $options = [
                'Restart with increased memory',
                'Continue with current memory limit',
                'Exit',
            ];

            // Prompt user to select how to proceed
            $choice = $this->isPromptsSupported() && $this->input->isInteractive()
                ? select(
                    label: 'How would you like to proceed?',
                    options: $options,
                    default: $options[0]
                )
                : $this->choice(
                    'How would you like to proceed?',
                    $options,
                    0
                );

            // Check if user chose to restart with increased memory
            if ($choice === 'Restart with increased memory') {
                // Inform user that command will restart
                $this->info('Restarting command with increased memory limit...');

                // Restart command with increased memory limit, pointing at the first
                // part — the locator re-discovers the whole part-set from it on rerun
                $this->restartWithIncreasedMemory(
                    $suggestedMemory,
                    $parts[0]
                );
            } elseif ($choice === 'Continue with current memory limit') { // Check if user chose to continue with current memory
                // Warn user to monitor for memory exhaustion
                $this->warn('Proceeding with current memory limit. Monitor for memory exhaustion.');
            } else { // Otherwise
                // Inform user that command is exiting
                $this->info('Exiting. Please restart the command with increased memory limit when ready.');

                // Exit with error status code
                $this->terminate(1);
            }
        } else { //  Otherwise
            // Inform user that memory limit appears sufficient
            $this->info('Memory limit appears sufficient for this import.');
        }
    }

    /**
     * The PHP memory limit the memory check compares against, as `ini_get()` gives it.
     *
     * A method of its own so a test can supply a limit without lowering the process's own,
     * which a test worker may already exceed.
     */
    protected function memoryLimit(): string
    {
        // Read the process's memory limit
        return ini_get('memory_limit');
    }

    /**
     * Convert memory limit string to bytes.
     *
     * @param  string  $memoryLimit  Memory limit string (e.g., "128M", "1G", "-1")
     * @return int Memory limit in bytes
     */
    private function convertToBytes(
        string $memoryLimit
    ): int {
        // Check if memory limit is unlimited
        if ($memoryLimit === '-1') {
            // Return maximum integer value for unlimited memory
            return PHP_INT_MAX;
        }

        // Extract unit character from end of string
        $unit = \strtolower(\substr(
            $memoryLimit,
            -1
        ));

        // Extract numeric value from beginning of string. A cast reads only the
        // leading digits, so the unit needs no stripping first.
        $value = (int) $memoryLimit;

        // Convert to bytes based on unit
        return match ($unit) {
            'k' => $value * 1024,
            'm' => $value * 1024 * 1024,
            'g' => $value * 1024 * 1024 * 1024,
            default => $value,
        };
    }

    /**
     * Analyze content item sizes in the XML file.
     *
     * Uses streaming parser to examine individual content items and identify
     * the largest ones that might cause memory issues during import.
     *
     * @param  string  $filePath  Path to the XML file
     * @param  int  $thresholdBytes  Threshold in bytes for considering items "large"
     * @return array{largestItemSize: int, largeItemsCount: int} Largest item size and large-item count
     */
    private function analyzeContentItemSizes(
        string $filePath,
        int $thresholdBytes = 50 * 1024
    ): array {
        // Open XML file for reading with appropriate flags
        $reader = \XMLReader::open(
            WxrSanitizedStream::wrap($filePath),
            null,
            LIBXML_NOCDATA
        );

        if ($reader === false) {
            return [
                'largestItemSize' => 0,
                'largeItemsCount' => 0,
            ];
        }

        // Initialize variable to track largest item size found
        $largestItemSize = 0;

        // Initialize counter for items exceeding threshold
        $largeItemsCount = 0;

        // Initialize flag to track if we're currently inside content:encoded element
        $inContentEncoded = false;

        // Initialize buffer to accumulate content for size calculation. Each item resets
        // it before it is measured, so content outside an item is never counted, and nor
        // is the start.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $contentBuffer = '';

        // Loop through XML nodes
        while ($reader->read()) {
            // Get current node type
            $nodeType = $reader->nodeType;

            // Get current node name
            $nodeName = $reader->name;

            // Check if current node is an element
            if ($nodeType === \XMLReader::ELEMENT) {
                // Check if this is the start of an item element
                if ($nodeName === 'item') {
                    // Reset content buffer for new item
                    $contentBuffer = '';
                } elseif ($nodeName === 'content:encoded') { // Check if this is the start of content:encoded element
                    // Set flag to indicate we're inside content:encoded
                    $inContentEncoded = true;
                }
            } elseif ($nodeType === \XMLReader::END_ELEMENT) { // Check if current node is an end element
                // Check if this is the end of an item element
                if ($nodeName === 'item') {
                    // Finished processing an item, check its size
                    $itemSize = \strlen($contentBuffer);

                    // Keep the largest item size seen
                    $largestItemSize = max(
                        $largestItemSize,
                        $itemSize
                    );

                    // Check if this item exceeds the threshold
                    if ($itemSize > $thresholdBytes) {
                        // Increment counter for large items
                        $largeItemsCount++;
                    }
                } elseif ($nodeName === 'content:encoded') { // Check if this is the end of content:encoded element
                    // Reset flag to indicate we're no longer inside content:encoded
                    $inContentEncoded = false;
                }
            } elseif (
                $nodeType === \XMLReader::TEXT
                && $inContentEncoded
            ) { // Check if current node is text content
                // ---
                // Accumulate content size (limit buffer size to prevent memory issues)
                // ---

                // Check if buffer hasn't exceeded maximum size
                if (\strlen($contentBuffer) < 50 * 1024 * 1024) { // Max 50MB buffer
                    // Append text content to buffer
                    $contentBuffer .= $reader->value;
                }
            }
        }

        // Close the XMLReader to free resources. Closing changes no result.
        // @pest-mutate-ignore: RemoveMethodCall
        $reader->close();

        // Clean up variables to free up memory
        unset(
            $contentBuffer,
            $reader
        );

        // Force garbage collection to reclaim memory from circular references. Collecting
        // changes no result.
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();

        // Return analysis results
        return [
            'largestItemSize' => $largestItemSize,
            'largeItemsCount' => $largeItemsCount,
        ];
    }

    /**
     * Provide restart instructions with increased memory.
     *
     * @param  string  $memoryLimit  The memory limit to use
     * @param  string  $filePath  The XML file path
     */
    private function restartWithIncreasedMemory(
        string $memoryLimit,
        string $filePath
    ): void {
        // Display instructions to user for restarting with increased memory
        $this->line('');
        $this->info('Run this command with increased memory:');
        $this->line("php artisan cache:clear && > storage/logs/laravel.log && php -d memory_limit={$memoryLimit} artisan statamic:import:wordpress {$filePath}");
        $this->line('');
        $this->info('Exiting. Please run the command above with the increased memory limit.');

        // Exit with success status code
        $this->terminate(0);
    }

    /**
     * End the process with the given status, as the memory check's choices do.
     *
     * A method of its own so a test can stand in for ending the process.
     *
     * @param  int  $status  The exit status
     */
    protected function terminate(
        int $status
    ): never {
        // End the process with the status
        exit($status);
    }

    /**
     * Get suggested memory limit based on recommended memory.
     *
     * @param  float  $recommendedMemoryMB  Recommended memory in MB
     * @return string Suggested memory limit string
     */
    private function getSuggestedMemory(
        float $recommendedMemoryMB
    ): string {
        // Round up to nearest multiple of 128MB
        $roundedUp = ceil($recommendedMemoryMB / 128) * 128;

        // Return suggested memory limit as string with M suffix
        return "{$roundedUp}M";
    }
}
