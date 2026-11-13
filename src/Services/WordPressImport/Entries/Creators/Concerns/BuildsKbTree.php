<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\Concerns;

use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Sites\Site;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\Tree;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Builds and saves the knowledge base collection tree.
 *
 * Part of {@see KbEntryCreator},
 * split out so that each file's mutation run fits the CI limit (#553). The methods run
 * on the creator and use its properties.
 */
trait BuildsKbTree
{
    /**
     * Create the initial tree structure for the knowledge_bases collection.
     *
     * This method ensures the collection has a structure defined and creates
     * an empty tree for the site. This is step 1 of the import process.
     *
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     */
    protected function createInitialTreeStructure(
        OutputInterface $output,
        string $site,
        string $collectionHandle
    ): void {
        // Display progress message to inform user of current operation
        $output->writeln('Creating initial tree structure for knowledge_bases...');

        // Get the knowledge_bases collection from Statamic's collection registry
        $collection = CollectionFacade::findByHandle($collectionHandle); // Attempt to retrieve the collection object by its handle
        if ($collection === null) {
            // ---
            // If the collection does not exist, display an error message to the user in the
            // console
            // ---
            $output->writeln("<error>Collection '{$collectionHandle}' not found.</error>");

            // Log the error for debugging and monitoring purposes, including the site context
            Log::error("KbEntryCreator::createInitialTreeStructure: Collection '{$collectionHandle}' not found for site {$site}.");

            // Exit the method early since the collection is required for further processing
            return;
        }

        // Give the collection a structure if it has none - required for hierarchical content
        $structure = $collection->structure();
        if (! $structure instanceof CollectionStructure) {
            // Inform user that structure is being created
            $output->writeln("<comment>Collection '{$collectionHandle}' has no structure defined. Creating one.</comment>");

            // ---
            // Create new CollectionStructure instance and assign it to the collection,
            // which gives it the collection's handle
            // ---
            $structure = new CollectionStructure;
            $collection->structure($structure);

            // Save the collection with the new structure
            $collection->save();
        }

        // Get the tree for the specific site
        $collectionTree = $structure->in($site);

        if ($collectionTree === null) {
            // Inform user that tree is being created for the site
            $output->writeln("<comment>No tree exists for '{$collectionHandle}' in site '$site'. Creating a new one.</comment>");

            // Create a new empty tree for the site
            $collectionTree = $structure->makeTree(
                $site,
                []
            );

            // Save the new tree to persist it; makeTree() always makes a Tree, so the
            // check only narrows the type
            // @pest-mutate-ignore: InstanceOfToTrue
            if ($collectionTree instanceof Tree) {
                $collectionTree->save();
            }
        }

        // Display success message confirming tree structure creation
        $output->writeln("<info>Initial tree structure created for '{$collectionHandle}' in site '$site'.</info>");
    }

    /**
     * Add an entry to the collection tree.
     *
     * This helper method adds an entry to the collection tree structure.
     * It optimizes performance by caching the tree instance to avoid
     * repeated lookups for the same collection and site.
     *
     * @param  string  $entryId  The ID of the entry to add to the tree
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  ?string  $parentId  The parent entry ID (null for top-level entries)
     */
    protected function addEntryToTree(
        string $entryId,
        string $collectionHandle,
        string $site,
        ?string $parentId = null
    ): void {
        // Record the parent/child relationship only. The tree is assembled and
        // saved once, at the end of the import, by buildAndSaveKbTree() — see the
        // $pendingTreeNodes docblock and wordpress-importer-archive#464 for why the build is deferred.
        $this->pendingTreeCollectionHandle = $collectionHandle;
        $this->pendingTreeSite = $site;
        $this->pendingTreeNodes[] = [
            'entry' => $entryId,
            'parent' => $parentId,
        ];
    }

    /**
     * Assemble and persist the knowledge_bases collection tree from the
     * parent/child relationships collected during the import.
     *
     * Builds the full nested tree (parent-first) once, from the now-complete set
     * of created entries, and saves it a single time. Deferring the build to the
     * end — instead of appending each entry as it is created — avoids Statamic's
     * CollectionStructure::validateTree() pruning entries whose creation
     * post-dated its Blink-cached collection entry list, and keeps the tree
     * consistent for the host app's ValidateKnowledgeBaseTree listener. See wordpress-importer-archive#464.
     *
     * @param  OutputInterface  $output  Console output for progress feedback
     */
    protected function buildAndSaveKbTree(OutputInterface $output): void
    {
        if (
            $this->pendingTreeNodes === []
            || $this->pendingTreeCollectionHandle === null
            || $this->pendingTreeSite === null
        ) {
            return;
        }

        $collectionHandle = $this->pendingTreeCollectionHandle;
        $site = $this->pendingTreeSite;

        // Group child entries by parent id (top-level entries key under ''), and
        // detect "external" parents — ids referenced as a parent but never
        // recorded here, chiefly the knowledge base root, which may already exist
        // and so is not re-added to the tree during this import. Anchor those at
        // the top level so their descendants nest beneath them.
        $recorded = \array_flip(\array_column(
            $this->pendingTreeNodes,
            'entry'
        ));

        $childrenByParent = [];
        $externalRoots = [];
        foreach ($this->pendingTreeNodes as $node) {
            $parentKey = $node['parent'] ?? '';
            if (
                $parentKey !== ''
                && ! isset($recorded[$parentKey])
                && ! isset($externalRoots[$parentKey])
            ) {
                $externalRoots[$parentKey] = $parentKey;
            }

            $childrenByParent[$parentKey][] = $node['entry'];
        }

        foreach ($externalRoots as $rootId) {
            $childrenByParent[''][] = $rootId;
        }

        // Assemble the nested [['entry' => id, 'children' => [...]]] structure,
        // parent-first, guarding against cycles and duplicate references.
        $visited = [];
        $nestedTree = $this->assembleTreeBranches(
            '',
            $childrenByParent,
            $visited
        );

        // CollectionStructure::validateTree() prunes the tree against a
        // request-scoped (Blink) cache of the collection's entries that predates
        // the entries just created; flush Blink so the save — and the host
        // ValidateKnowledgeBaseTree listener that fires on it — validate against
        // the complete, current entry set. This runs once per KB import, immediately
        // before the tree save, so the cost is negligible. See wordpress-importer-archive#464. (It is no longer
        // the import's last write: the deferred related-articles pass re-saves entries
        // after it, which is deliberate and load-bearing — see createEntries() / wordpress-importer-archive#761.)
        //
        // Tree::save() forgets the tree-entries cache itself, so only the host's listener,
        // which this package's tests do not run, can read the cache this flush clears.
        // @pest-mutate-ignore: RemoveMethodCall
        Blink::flush();

        $structure = CollectionFacade::findByHandle($collectionHandle)?->structure();
        if (! $structure instanceof CollectionStructure) {
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'collection_handle' => $collectionHandle,
                    'site' => $site,
                ]
            );
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "KbEntryCreator::buildAndSaveKbTree: Collection '{$collectionHandle}' not found or has no structure for site {$site}.",
                    $logData['page_url']
                ),
                $logData['context']
            );

            return;
        }

        // A site the collection has no tree for has nothing to save into
        $tree = $structure->in($site);
        if (! $tree instanceof Tree) {
            return;
        }

        $tree->tree($nestedTree);
        $tree->save();

        $output->writeln('<info>Knowledge base tree structure saved.</info>');
    }

    /**
     * Recursively assemble tree branches under a parent id, parent-first.
     *
     * @param  string  $parentKey  Parent entry id, or '' for top-level entries
     * @param  array<string, list<string>>  $childrenByParent  Child entry ids keyed by parent id
     * @param  array<string, string>  $visited  Entry ids already placed, keyed by id (guards cycles/duplicates)
     * @return list<array<string, mixed>> The nested branch structure
     */
    private function assembleTreeBranches(
        string $parentKey,
        array $childrenByParent,
        array &$visited
    ): array {
        $branches = [];

        foreach ($childrenByParent[$parentKey] ?? [] as $entryId) {
            if (isset($visited[$entryId])) {
                continue;
            }

            $visited[$entryId] = $entryId;

            $branch = ['entry' => $entryId];
            $children = $this->assembleTreeBranches(
                $entryId,
                $childrenByParent,
                $visited
            );
            if ($children !== []) {
                $branch['children'] = $children;
            }

            $branches[] = $branch;
        }

        return $branches;
    }
}
