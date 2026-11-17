<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Concerns;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;

/**
 * Chooses the slug for an item WordPress exported with an empty `wp:post_name` (#588).
 *
 * WordPress leaves the name empty on many drafts, and the importer then builds the
 * slug from the title, which a published item can already hold. Two entries on
 * one slug share a URI, and Statamic serves whichever it finds first, so the
 * published one can stop resolving.
 *
 * A trait of its own rather than part of {@see SavesEntries}, so that a change
 * here does not put that whole file through the mutation run (#550).
 */
trait ChoosesUnnamedItemSlugs
{
    /**
     * Choose the slug for an item WordPress exported with an empty `wp:post_name`.
     *
     * The title slug is kept when no other item holds it, counting every name the
     * export sets and every entry in the collection and site that belongs to a
     * different WordPress item. Otherwise the WordPress ID is appended, and then a
     * counter. Both checks give the same answer on every run of the same export,
     * whatever order the items come in, so a re-import finds the entry the last
     * one created.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  string  $titleSlug  The slug built from the title
     * @param  string  $postId  The WordPress post ID
     * @param  array<string, string>  $exportSlugs  The WordPress post ID that sets each non-empty `wp:post_name` in
     *                                              the export, keyed by that name
     * @return string The slug to give the entry
     */
    protected function slugForUnnamedItem(
        string $collectionHandle,
        string $site,
        string $titleSlug,
        string $postId,
        array $exportSlugs
    ): string {
        // Every slug another item holds, from the export and from the collection
        $held = $exportSlugs + $this->slugsHeldByOtherItems(
            $collectionHandle,
            $site,
            $titleSlug,
            $postId
        );

        // Keep the title slug when it is free
        if (! isset($held[$titleSlug])) {
            return $titleSlug;
        }

        // Otherwise append the WordPress ID, then count on from 2 past any held slug
        $candidate = "$titleSlug-$postId";
        $counter = 2;

        while (isset($held[$candidate])) {
            $candidate = "$titleSlug-$postId-$counter";
            $counter++;
        }

        return $candidate;
    }

    /**
     * Read the slugs that entries for other WordPress items hold.
     *
     * Only slugs that begin with the title slug can matter, so the read is narrowed
     * to those. The WordPress ID of an entry is read from its ID, whose format is
     * `slug-postId-random`, as `findExistingEntry()` reads it. An entry whose ID
     * does not have that shape was not made from this item, so it counts as
     * another's.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  string  $titleSlug  The slug built from the title
     * @param  string  $postId  The WordPress post ID of the item asking
     * @return array<string, string> The ID of the entry holding each slug, keyed by slug
     */
    private function slugsHeldByOtherItems(
        string $collectionHandle,
        string $site,
        string $titleSlug,
        string $postId
    ): array {
        $query = EntryQueryHelper::query();
        $query->where(
            'collection',
            $collectionHandle
        );
        $query->where(
            'site',
            $site
        );

        // ---
        // Narrows the read to slugs that can matter. Without it, or with a bare `%`
        // for the pattern, every entry in the collection and site is read and the
        // same slugs are found among them, so the answer cannot change, only the cost.
        // ---
        // @pest-mutate-ignore: RemoveMethodCall, ConcatRemoveLeft
        $query->where(
            'slug',
            'like',
            $titleSlug.'%'
        );

        // A query's get() returns a collection; the check only narrows the type
        $entries = $query->get();
        // @pest-mutate-ignore: InstanceOfToTrue
        $entries = $entries instanceof Collection ? $entries->all() : [];

        $held = [];

        foreach ($entries as $entry) {
            // A query for entries returns entries; this only narrows the type
            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $entry instanceof Entry) {
                continue;
            }

            $entryId = $entry->id();
            $entrySlug = $entry->slug();

            // ---
            // An entry's ID is a string, and one the slug filter matched has a slug,
            // so this only narrows the types
            // ---
            // @pest-mutate-ignore: BooleanOrToBooleanAnd, ContinueToBreak
            if (! \is_string($entryId) || ! \is_string($entrySlug)) {
                continue;
            }

            // Count every entry but this item's own, by the WordPress ID in its entry ID
            $idParts = \explode(
                '-',
                $entryId
            );
            $count = \count($idParts);

            if ($count < 3 || $idParts[$count - 2] !== $postId) {
                $held[$entrySlug] = $entryId;
            }
        }

        return $held;
    }
}
