<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns;

use DateTime;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Collection as EntryCollection;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Sites\Site;
use Statamic\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SlugGenerator;

/**
 * Saves an entry under the configured overwrite behavior and finds the entry an item already produced.
 *
 * Split out of {@see BaseEntryCreator} so that each file's mutation run fits
 * the CI limit (#550). The methods run on the creator and use its properties
 * and static caches, which stay declared on that class.
 */
trait SavesEntries
{
    /**
     * Save an entry with date handling and overwrite behavior.
     *
     * Handles entry saving based on the configured overwrite behavior:
     *   - "Overwrite": Deletes existing entry and saves new one.
     *   - "Skip": Skips saving if entry exists.
     *   - "Create an additional file": Saves a new entry under `{slug}_copy-{n}`, with
     *     a new ID if the existing entry holds the one given.
     *
     * Determines the published status from status_and_visibility if $published is
     * null, defaulting to false for drafts or pending entries.
     *
     * Checks the collection configuration to determine if dates are enabled
     * (`date: true`). If the collection is dated, sets the entry date to ensure
     * entries are created with dated filenames (e.g., `2025-11-05.foo.md`). If the
     * collection is not dated, entries are created with non-dated filenames (e.g.,
     * `foo.md`).
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  string  $entrySlug  The entry slug
     * @param  array<int|string, mixed>  $data  Entry data
     * @param  OutputInterface  $output  Console output interface
     * @param  ?DateTime  $entryDate  The entry date object or null
     * @param  ?bool  $published  Published status, or null to infer from status_and_visibility
     * @param  ?string  $postDateStr  Optional WordPress post date string to use if collection is dated but
     *                                $entryDate is null
     * @return ?Entry The created entry object, the existing entry if found with
     *                "Skip matching files" behavior, or null if skipped for other reasons
     */
    protected function saveEntry(
        string $collectionHandle,
        string $site,
        string $entrySlug,
        array $data,
        OutputInterface $output,
        ?DateTime $entryDate = null,
        ?bool $published = null,
        ?string $postDateStr = null
    ): ?Entry {
        $entryIdValue = $data['id'] ?? null;
        if (! \is_string($entryIdValue) || $entryIdValue === '') {
            $entryIdValue = $entrySlug;
        }

        $entryId = $entryIdValue;

        // Determine published status if not explicitly set
        if ($published === null) {
            // Check status_and_visibility field (e.g., for pages/posts)
            if (isset($data['status_and_visibility'])) {
                $published = \in_array(
                    $data['status_and_visibility'],
                    [
                        'published',
                        'private',
                        'scheduled',
                    ],
                    true
                );
            } elseif (isset($data['published'])) { // Check if published is explicitly set in data array (e.g., for reusable_content)
                $published = $data['published'];
            } else { // Default to false if neither is set
                $published = false;
            }
        }

        // Check if an entry already exists
        $existingEntry = $this->findExistingEntry(
            $collectionHandle,
            $site,
            $entryId,
            $entrySlug
        );

        // Check if an existing entry was found
        if ($existingEntry instanceof Entry) {
            // Handle the entry based on the configured overwrite behavior
            switch ($this->entryOverwriteBehavior) {
                // Handle case where existing entries should be skipped
                case 'Skip matching files':
                    // Display skip message to user
                    $output->writeln("<comment>Skipped entry '$entrySlug' as it already exists.</comment>");

                    // Return the existing entry so the caller can add it to pageData
                    return $existingEntry;

                    // Handle case where additional entries should be created with unique slugs
                case 'Create an additional file':
                    $entrySlug = $this->additionalEntrySlug(
                        $collectionHandle,
                        $site,
                        $entrySlug
                    );

                    // Post and people data carry the slug as a field of its own
                    if (isset($data['slug'])) {
                        $data['slug'] = $entrySlug;
                    }

                    // ---
                    // The entry drops `id` from its data below, so `id()` is the only id
                    // it has. One the matched entry holds would save over that entry.
                    // ---
                    if ($existingEntry->id() === $entryId) {
                        $entryId = $entrySlug.'-'.Str::uuid();
                    }

                    // Exit switch statement after handling additional file creation
                    break;

                    // Handle case where existing entries should be overwritten
                case 'Overwrite matching files':
                    // ---
                    // Adopt the id the matched entry already carries, rather than the
                    // one `SlugGenerator` produced for this run.
                    //
                    // `SlugGenerator` appends a fresh `Str::random()` per run, so the
                    // replacement used to land on a different id every time. Two
                    // importer surfaces write an entry id INTO content during the
                    // `page` pass — `LinkProcessor::convertInternalPageLink()`'s
                    // `statamic://entry::<id>` and `NewsParser::resolveArticleId()`'s
                    // `article_id` — and `ImportWordPress::$supportedContentTypes`
                    // lists `page` before `post`. So within one run the page pass
                    // resolved post N to an id, the post pass replaced that entry
                    // with another, and the run ended with the page naming an entry
                    // that no longer existed. Re-running never converged, because
                    // every run repeated the delete-and-recreate.
                    //
                    // Preserving the id fixes references written by PREVIOUS runs
                    // too, which re-resolving at the end of a run would not: those
                    // ids stay valid because the entry keeps them.
                    //
                    // `PostCategoryEntryResolver`'s ids were never affected for the
                    // mirror-image reason — `StructuredTermImporter::createOrUpdateEntry()`
                    // re-saves in place. The difference was the entry-creation model,
                    // not the lookups (wordpress-importer-archive#767).
                    // ---
                    $existingEntryId = $existingEntry->id();
                    $entryId = \is_string($existingEntryId) ? $existingEntryId : $entryId;

                    // Delete the existing entry to make room for the new one
                    $existingEntry->delete();

                    // Exit switch statement after handling overwrite
                    break;
            }
        }

        // The facade always makes an Entry; the check only narrows the type
        $entry = EntryFacade::make();
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn
        if (! $entry instanceof Entry) {
            return null;
        }

        $entry->collection($collectionHandle);
        $entry->locale($site);
        $entry->published($published);
        $entry->slug($entrySlug);

        // Set the custom ID on the entry object
        $entry->id($entryId);

        // Remove ID from data array since it's set on the entry object
        unset($data['id']);

        // Check if the collection is dated based on configuration
        $entryCollection = $entry->collection();
        // ---
        // The && already yields a bool, so the cast only satisfies the type. An entry
        // set to a collection always has it, so the check only narrows.
        // ---
        // @pest-mutate-ignore: RemoveBooleanCast, InstanceOfToTrue
        $isCollectionDated = $entryCollection instanceof EntryCollection && (bool) $entryCollection->dated();

        // Determine the date to use for the entry
        $finalEntryDate = $entryDate;

        // If collection is dated but no entry date is provided, create one
        if (
            $isCollectionDated
            && ! $finalEntryDate instanceof DateTime
        ) {
            // ---
            // Try to create date from post date string if provided. A blank string
            // would read as now, which the fallback below gives anyway.
            // ---
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            if ($postDateStr !== null && $postDateStr !== '') {
                try {
                    $finalEntryDate = new DateTime($postDateStr);
                } catch (Exception $e) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'collection_handle' => $collectionHandle,
                            'post_date_str' => $postDateStr,
                            'error' => $e->getMessage(),
                        ]
                    );

                    // Log warning for failed date parsing
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'BaseEntryCreator::saveEntry: Failed to parse post date string',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            }

            $finalEntryDate ??= new DateTime;
        }

        // Set the date on the entry if we have one
        if ($finalEntryDate instanceof DateTime) {
            // Only set date if the collection is dated
            if ($isCollectionDated) {
                $entry->date(Carbon::instance($finalEntryDate));
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'collection_handle' => $collectionHandle,
                        'collection_dated' => $isCollectionDated,
                    ]
                );

                // Log warning for skipping date setting
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'BaseEntryCreator::saveEntry: Skipping date setting - collection not dated',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Unset published from data array if it's true (only want false in YAML)
        if (
            isset($data['published'])
            && $data['published'] === true
        ) {
            unset($data['published']);
        }

        // Save the entry with all data
        $entry->data($data);
        $entry->save();

        // ---
        // Refresh Stache cache to ensure the entry is available for immediate queries.
        // This is necessary because file watchers may not have updated the cache yet;
        // the Stache in a test indexes the entry on save, so nothing here can show it.
        // ---
        // @pest-mutate-ignore: InstanceOfToTrue, InstanceOfToFalse, IfNegated, RemoveMethodCall, RemoveArrayItem
        if ($entryCollection instanceof EntryCollection) {
            $entryCollection->updateEntryUris([$entry->id()]);
        }

        $output->writeln(
            "  - <info>Created entry '$entrySlug'".($finalEntryDate instanceof DateTime ? ' (date: '.$finalEntryDate->format('Y-m-d H:i:s').')' : '')." in Statamic for the '$site' site in the '$collectionHandle' collection.</info>"
        );

        return $entry;
    }

    /**
     * Read a slug as Statamic stores it, slugged in its site's language.
     *
     * @param  string  $entrySlug  The slug
     * @param  string  $site  The site handle
     * @return string The stored slug
     */
    private function storedEntrySlug(string $entrySlug, string $site): string
    {
        $siteModel = SiteFacade::get($site);

        return self::mixedToStringValue(Str::slug(
            $entrySlug,
            '-',
            $siteModel instanceof Site ? $siteModel->lang() : null
        ));
    }

    /**
     * Find the slug for an additional copy of an entry.
     *
     * The search runs on the slug Statamic stores: a dot would not survive it, so a
     * copy of `12` named `12.1` would land on `121`. An underscore survives it, and
     * WordPress slugs do not read as a copy of another.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  string  $entrySlug  The slug of the entry being copied
     * @return string The first free `{slug}_copy-{n}`
     */
    private function additionalEntrySlug(
        string $collectionHandle,
        string $site,
        string $entrySlug
    ): string {
        $storedSlug = $this->storedEntrySlug($entrySlug, $site);
        $counter = 1;

        do {
            $copySlug = "{$storedSlug}_copy-$counter";
            $counter++;

            $query = $this->newEntryQuery();
            $query->where(
                'collection',
                $collectionHandle
            );
            $query->where(
                'site',
                $site
            );
            $query->where(
                'slug',
                $copySlug
            );
        } while ($query->first() !== null);

        return $copySlug;
    }

    /**
     * Check if an entry already exists, handling both exact ID matches and slug/post
     * ID matches.
     *
     * This method checks for existing entries using two approaches:
     *   1. Exact ID match (for entries with the same random string)
     *   2. Slug and WordPress post ID match (for entries where the random string has
     *      changed)
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  string  $entryId  The entry ID to check
     * @param  string  $entrySlug  The entry slug to check
     * @return ?Entry The existing entry if found, null otherwise
     */
    protected function findExistingEntry(
        string $collectionHandle,
        string $site,
        string $entryId,
        string $entrySlug
    ): ?Entry {
        // Check if an entry with the same ID exists in this collection and site
        $existingEntryQuery = $this->newEntryQuery();
        $existingEntryQuery->where(
            'collection',
            $collectionHandle
        );
        $existingEntryQuery->where(
            'site',
            $site
        );
        $existingEntryQuery->where(
            'id',
            $entryId
        );
        $existingEntry = $existingEntryQuery->first();

        if ($existingEntry instanceof Entry) {
            return $existingEntry;
        }

        // ---
        // If no exact ID match, check for existing entry with same slug and WordPress
        // post ID. This handles cases where the random part of the ID has changed
        // between imports. The entry ID's format is slug-postId-random.
        // ---
        $idParts = \explode(
            '-',
            $entryId
        );

        // An ID with no post ID and random part has nothing to match on
        if (\count($idParts) < 3) {
            return null;
        }

        // Extract the WordPress post ID from the second-to-last part
        $wpPostId = $idParts[\count($idParts) - 2];

        // Reconstruct the base slug from all parts except the last two (postId and random)
        $baseSlug = \implode(
            '-',
            \array_slice(
                $idParts,
                0,
                -2
            )
        );

        // Look for existing entries whose slug starts with the base slug
        $matchingEntryQuery = $this->newEntryQuery();
        $matchingEntryQuery->where(
            'collection',
            $collectionHandle
        );
        $matchingEntryQuery->where(
            'site',
            $site
        );
        $matchingEntryQuery->where(
            'slug',
            'like',
            $baseSlug.'%'
        );

        // ---
        // Take the first whose ID has a 16-character random part (Statamic's random
        // string length), the same WordPress post ID, and the same base slug, by ID or
        // by slug with trailing numbers removed. A query's get() returns a collection;
        // the check only narrows the type.
        // ---
        $matchingEntries = $matchingEntryQuery->get();
        // @pest-mutate-ignore: InstanceOfToTrue
        $sameEntries = $matchingEntries instanceof Collection ? $matchingEntries->filter(
            static function (mixed $entry) use ($wpPostId, $baseSlug): bool {
                // A query for entries returns entries; this only narrows the type
                // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn, FalseToTrue
                if (! $entry instanceof Entry) {
                    return false;
                }

                // Split the existing entry's ID into parts using hyphens
                $entryIdParts = \explode(
                    '-',
                    self::mixedToStringValue($entry->id())
                );

                // An existing ID with no post ID and random part does not match
                if (\count($entryIdParts) < 3) {
                    return false;
                }

                // Reconstruct the base slug from the existing entry's ID
                $entryBaseSlug = \implode(
                    '-',
                    \array_slice(
                        $entryIdParts,
                        0,
                        -2
                    )
                );

                // Remove trailing numbers from both slugs for comparison
                $entrySlugBase = \preg_replace(
                    '/\.?\d+$/',
                    '',
                    self::mixedToStringValue($entry->slug())
                );
                $baseSlugClean = \preg_replace(
                    '/\.?\d+$/',
                    '',
                    $baseSlug
                );

                return \strlen(end($entryIdParts)) === 16
                    && $entryIdParts[\count($entryIdParts) - 2] === $wpPostId
                    && (
                        $entryBaseSlug === $baseSlug
                        || $entrySlugBase === $baseSlugClean
                    );
            }
        ) : null;

        // ---
        // An additional copy carries the post ID of the entry it copies, so the entry
        // on the slug itself goes first, or a later run would take the copy for it
        // ---
        $storedSlug = $this->storedEntrySlug($entrySlug, $site);
        $matchingEntry = $sameEntries?->first(
            static fn (mixed $entry): bool => $entry instanceof Entry && $entry->slug() === $storedSlug
        ) ?? $sameEntries?->first();

        // ---
        // Return the found existing entry or null if none exists. A collection's
        // first() gives null when nothing matches, so the check only narrows the type.
        // ---
        // @pest-mutate-ignore: InstanceOfToTrue
        return $matchingEntry instanceof Entry ? $matchingEntry : null;
    }

    /**
     * Read a value an entry accessor returns as a string, or '' when it is not one.
     *
     * @param  mixed  $value  The value
     * @return string The string, or ''
     */
    private static function mixedToStringValue(
        mixed $value
    ): string {
        // An entry with no slug answers null, which reads as an empty slug
        return \is_string($value) ? $value : '';
    }
}
