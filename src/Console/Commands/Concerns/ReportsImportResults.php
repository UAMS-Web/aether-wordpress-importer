<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File as LaravelFile;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Stache\Query\EntryQueryBuilder;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;

/**
 * Sanitizes the imported Bard content and reports how the content directory size changed.
 *
 * Part of the `statamic:import:wordpress` command, split out of
 * {@see ImportWordPress} so that each file's
 * mutation run fits the CI limit (#546). The methods run on the command and use its
 * properties and output.
 */
trait ReportsImportResults
{
    /**
     * Run Bard content sanitization on all collection entries.
     *
     * Ensures YAML is formatted properly when the import is completed by
     * double-quoting text values containing `\_` (non-breaking space) and
     * quoting YAML document separators in Bard text nodes. Processes all
     * collections and only rewrites files that need sanitization.
     */
    private function runBardContentSanitization(): void
    {
        $this->info('Sanitizing Bard content in entries...');

        $entriesUpdated = 0;
        $collections = Collection::all();

        foreach ($collections as $collection) {
            // Collection::all() answers only collections, an entry query is always the
            // Stache's builder and answers a collection of entries, and an entry's path is
            // never empty, so each check below only types what it reads.
            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $collection instanceof StatamicCollection) {
                continue;
            }

            $entryQuery = Entry::query();

            // @pest-mutate-ignore: InstanceOfToTrue, RemoveFunctionCall
            \assert($entryQuery instanceof EntryQueryBuilder);

            $query = $entryQuery->where(
                'collection',
                $collection->handle()
            );

            // @pest-mutate-ignore: InstanceOfToTrue, RemoveFunctionCall
            \assert($query instanceof EntryQueryBuilder);

            $entries = $query->get();

            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $entries instanceof \Illuminate\Support\Collection) {
                continue;
            }

            foreach ($entries as $entry) {
                // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
                if (! $entry instanceof StatamicEntry) {
                    continue;
                }

                $filePath = $entry->path();

                // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty, ContinueToBreak
                if (! is_string($filePath) || $filePath === '') {
                    continue;
                }

                if (! LaravelFile::exists($filePath)) {
                    continue;
                }

                $fileContent = LaravelFile::get($filePath);
                $needsSanitization = $this->bardContentSanitizer
                    ->containsYamlSeparatorsInRawFile($fileContent)
                    || $this->bardContentSanitizer
                        ->containsUnquotedNbspTextInRawFile($fileContent);

                if ($needsSanitization) {
                    $sanitizedContent = $this->bardContentSanitizer
                        ->sanitizeRawFileContent($fileContent);
                    LaravelFile::put($filePath, $sanitizedContent);
                    $entriesUpdated++;
                }
            }
        }

        if ($entriesUpdated > 0) {
            $this->line("  Sanitized {$entriesUpdated} entr".($entriesUpdated === 1 ? 'y' : 'ies').'.');
        } else {
            $this->line('  No Bard content required sanitization.');
        }

        unset(
            $collections,
            $entriesUpdated
        );
    }

    /**
     * Calculate the total filesize of a directory recursively.
     *
     * Recursively traverses the specified directory and calculates the total
     * size of all files within it. Uses decimal conversion (1 MB = 1000 KB)
     * as per project standards.
     *
     * @param  string  $directory  The directory path to calculate filesize for
     * @return int Total filesize in bytes
     */
    private function calculateDirectoryFilesize(
        string $directory
    ): int {
        // Initialize total size counter to zero
        $totalSize = 0;

        // Check if the directory exists and is accessible
        if (! \is_dir($directory)) {
            // Return zero if directory doesn't exist or isn't accessible
            return 0;
        }

        // Create recursive iterator to traverse directory and all subdirectories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        // Loop through all files and directories recursively
        foreach ($iterator as $file) {
            // Check if current item is a file (not a directory). The iterator yields
            // only SplFileInfo, so the instanceof check only types it.
            // @pest-mutate-ignore: InstanceOfToTrue
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                // Add file size to total size counter
                $totalSize += $file->getSize();
            }
        }

        // Clean up variables to free up memory
        unset(
            $iterator
        );

        // Return the total calculated size in bytes
        return $totalSize;
    }

    /**
     * Format filesize in multiple units for display.
     *
     * Converts bytes to kilobytes, megabytes, and gigabytes using decimal
     * conversion (1 MB = 1000 KB) as per project standards.
     *
     * @param  int  $bytes  The filesize in bytes
     * @return array{bytes: int, kb: float, mb: float, gb: float} Filesize in multiple units
     */
    private function formatFilesize(
        int $bytes
    ): array {
        // Return array with filesize converted to multiple units using decimal conversion
        return [
            'bytes' => $bytes,
            'kb' => round(
                $bytes / 1000,
                2
            ),
            'mb' => round(
                $bytes / (1000 * 1000),
                2
            ),
            'gb' => round(
                $bytes / (1000 * 1000 * 1000),
                2
            ),
        ];
    }

    /**
     * Display filesize difference between initial and final measurements.
     *
     * Calculates the difference between the initial and final filesize
     * measurements and displays the result in bytes, kilobytes, megabytes,
     * and gigabytes.
     *
     * @param  int  $finalFilesize  The final filesize in bytes
     */
    private function displayFilesizeDifference(
        int $finalFilesize
    ): void {
        // Calculate the difference between final and initial filesize measurements
        $difference = $finalFilesize - $this->initialFilesize;

        // Format the difference for display in multiple units
        $formatted = $this->formatFilesize($difference);

        // Display the filesize difference to the user in all units
        $this->line('');
        $this->info('Filesize difference after import:');
        $this->line("  Bytes: {$formatted['bytes']}");
        $this->line("  Kilobytes: {$formatted['kb']} KB");
        $this->line("  Megabytes: {$formatted['mb']} MB");
        $this->line("  Gigabytes: {$formatted['gb']} GB");
        $this->line('');

        // Clean up variables to free up memory
        unset(
            $formatted
        );
    }
}
