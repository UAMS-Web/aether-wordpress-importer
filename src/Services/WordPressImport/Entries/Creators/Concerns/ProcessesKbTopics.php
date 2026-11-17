<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\Concerns;

use DateTime;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Statamic\Sites\Site;
use Statamic\Structures\Tree;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\FieldDefaultHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Creates the knowledge base topic entries and finds an article's topic.
 *
 * Part of {@see KbEntryCreator},
 * split out so that each file's mutation run fits the CI limit (#553). The methods run
 * on the creator and use its properties.
 */
trait ProcessesKbTopics
{
    /**
     * Process topics from wp:term elements in the XML.
     *
     * This method extracts all kbtopic terms from the WordPress XML export,
     * builds a hierarchical structure based on parent-child relationships,
     * and creates topic entries in Statamic. Statamic will automatically
     * handle tree updates when entries are saved with parent fields set.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     */
    protected function processTopics(
        XmlParser $xmlParser,
        OutputInterface $output,
        string $site,
        string $collectionHandle,
        ?array $minervaKbSettings = null
    ): void {
        // Display progress message to inform user of current operation
        $output->writeln('Processing topics...');

        // ---
        // The term map holds only kbtopic terms (see process()), each an array from
        // TermMapBuilder; the filter only narrows the type
        // ---
        $kbtopicTermMap = \array_filter(
            $this->termMap,
            \is_array(...)
        );

        // Check if any kbtopic terms were found in the XML
        if ($kbtopicTermMap === []) {
            // If no kbtopic terms were found, inform the user via console output
            $output->writeln('No kbtopic terms found in XML.');

            // Exit the method early since there are no topics to process
            return;
        }

        // ---
        // Display the number of kbtopic terms found to the user in the console for
        // immediate feedback
        // ---
        $output->writeln('Found '.\count($kbtopicTermMap).' kbtopic terms.');

        // Step 2: Recursively create topics in parent-before-child order
        // This ensures parent topics exist before creating child topics
        $created = []; // Track which topics have already been created to avoid duplicates
        $inProgress = []; // Track the topics waiting on their parent, so a parent cycle ends

        // ---
        // Define a recursive closure to create topics in parent-before-child order,
        // ensuring the hierarchy is built correctly
        // ---
        $createTopicRecursively = function (int|string $termId, string $parentId) use (
            &$createTopicRecursively,
            &$created,
            &$inProgress,
            $kbtopicTermMap,
            $output,
            $site,
            $collectionHandle,
            $xmlParser,
            $minervaKbSettings
        ): string {
            // If topic already created, return its ID to avoid duplicates
            if (isset($created[$termId])) {
                return $created[$termId];
            }

            // Get the current term data from the map using its ID
            $termData = $kbtopicTermMap[$termId];

            // ---
            // Get the parent term ID from the term data (already resolved by TermMapBuilder).
            // No term has the fallback's ID either way.
            // ---
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $parentTermId = self::mixedToString(
                $termData['parent_id'] ?? null,
                ''
            );

            // Mark this topic as waiting on its parent
            $inProgress[$termId] = true;

            // A parent still waiting on this topic can never be created first (#281)
            $inCycle = isset($inProgress[$parentTermId]);

            if ($inCycle) {
                $this->reportTopicParentCycle(
                    (string) $termId,
                    $parentTermId,
                    $site
                );
            }

            // ---
            // Create a parent topic first and file this topic under it. A term whose
            // parent is not a topic, or is in a cycle with it, is filed under the
            // knowledge base instead.
            // ---
            $parentEntryId = ! $inCycle && isset($kbtopicTermMap[$parentTermId])
                ? $createTopicRecursively(
                    $parentTermId,
                    $parentId
                )
                : $parentId;

            // A later topic may name this one as an ordinary parent
            unset($inProgress[$termId]);

            // Create this topic, and store its entry ID to avoid duplicates
            $created[$termId] = $this->createTopicEntryFromData(
                $termData,
                $parentEntryId,
                $output,
                $site,
                $collectionHandle,
                $xmlParser,
                $minervaKbSettings
            );

            // ---
            // Return the entry ID of the topic just created, so it can be used as a parent
            // for any child topics
            // ---
            return $created[$termId];
        };

        // Process each topic term, starting the recursive creation process
        foreach (ImportProgress::track($output, 'kb topics', $kbtopicTermMap) as $termId => $termData) {
            // Start recursive creation with knowledge base as the parent
            $createTopicRecursively(
                $termId,
                $this->knowledgeBaseId
            );
        }

        // ---
        // Statamic will automatically update the tree when entries are saved with parent
        // fields set.
        // ---

        // Display completion message with counts for user feedback
        $output->writeln('Processed '.\count($kbtopicTermMap).' kbtopic terms, created '.\count($this->topicIdMap).' topic entries.');
    }

    /**
     * Report a topic that is filed under the knowledge base because of a parent cycle.
     *
     * @param  string  $termId  The WordPress term ID of the topic
     * @param  string  $parentTermId  The WordPress term ID of the parent it names
     * @param  string  $site  Selected site handle
     */
    private function reportTopicParentCycle(
        string $termId,
        string $parentTermId,
        string $site
    ): void {
        // Log the cycle for debugging
        Log::warning(
            "KbEntryCreator::processTopics: kbtopic term {$termId} names parent term {$parentTermId} in a parent cycle, so it is filed under the knowledge base",
            [
                'term_id' => $termId,
                'parent_term_id' => $parentTermId,
                'site' => $site,
            ]
        );

        // Report it in the run's summary
        ImportWarningManager::addWarning(
            'content',
            'kb_topic_parent_cycles',
            [
                'term_id' => $termId,
                'parent_term_id' => $parentTermId,
                'site' => $site,
            ]
        );
    }

    /**
     * Create a single topic entry from term data.
     *
     * This method creates a Statamic entry for a topic from term data
     * provided by TermMapBuilder. It extracts the topic name, slug, and
     * description, generates a unique entry ID, and saves the entry with
     * appropriate metadata.
     *
     * @param  array<int|string, mixed>  $termData  The term data from TermMapBuilder
     * @param  string  $parentId  Parent entry ID
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle (e.g., 'knowledge_bases')
     * @param  ?XmlParser  $xmlParser  Optional XML parser for extracting term descriptions
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return string The created topic entry ID
     */
    protected function createTopicEntryFromData(
        array $termData,
        string $parentId,
        OutputInterface $output,
        string $site,
        string $collectionHandle,
        ?XmlParser $xmlParser = null,
        ?array $minervaKbSettings = null
    ): string {
        // Extract the unique WordPress term ID from the term data
        $termId = self::mixedToString(
            $termData['term_id'] ?? null,
            ''
        );
        if ($termId === '') {
            return '';
        }

        // Extract the URL-friendly slug from the term data
        $termSlug = self::mixedToString(
            $termData['term_slug'] ?? null,
            ''
        );
        if ($termSlug === '') {
            return '';
        }

        // Extract and strip HTML tags from the display name
        $termName = HtmlTagStripper::strip(
            self::mixedToString(
                $termData['term_name'] ?? null,
                ''
            )
        );

        // ---
        // Extract description from term XML element (wp:term_description or termmeta)
        // ---

        // Initialize description as empty; will be set if found in XML
        $description = '';

        // Check if XML parser is available
        if ($xmlParser instanceof XmlParser) {
            // Get the term element from XML by term ID
            $termElement = $this->findTermElementById(
                $xmlParser,
                $termId
            );

            // Check if term element was found
            if ($termElement instanceof SimpleXMLElement) {
                // Extract description from the term element (wp:term_description or termmeta)
                $description = $this->extractTermDescription($termElement);
            }
        }

        // ---
        // Strip HTML tags from description and truncate to excerpt field limit if it exceeds
        // 160 characters
        // ---
        $description = $this->truncateToExcerptLimit(HtmlTagStripper::strip($description));

        // ---
        // Generate slug and ID using BaseEntryCreator for consistency with other entry
        // types
        // ---
        [$entrySlug, $entryId] = $this->generateSlugAndId(
            $termSlug,
            $termId,
            $termName
        );

        // Create topic entry data with required fields
        $entryData = [
            'blueprint' => 'topic', // Specify the blueprint for topic entries
            'title' => $termName, // Set the topic title from WordPress term name
            'excerpt' => $description, // Set the excerpt from the description
            'parent' => $parentId, // Set the parent relationship for hierarchical structure
        ];

        // Get the blueprint name for the topic entry
        $blueprint = 'topic';

        // Get the default template
        $templateDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.{$blueprint}",
            'template'
        );

        // Assign the template to the entry data
        $entryData['template'] = $templateDefault;

        // Fetch the default value for the title_options field from the topic blueprint
        $titleOptionsDefault = FieldDefaultHelper::getDefaultValueForBlueprintField(
            "collections.{$collectionHandle}.{$blueprint}",
            'title_options'
        );

        // Check if title options default value is available from the blueprint
        if ($titleOptionsDefault !== null) {
            // Assign the default title options value to the entry data
            $entryData['title_options'] = $titleOptionsDefault;

            // ---
            // Check if the title options value is set to 'graphic' mode, which requires
            // additional header graphic fields
            // ---
            if ($titleOptionsDefault === 'graphic') {
                // ---
                // Initialize the header_graphic field with empty body and image values for
                // graphic title mode
                // ---
                $entryData['header_graphic'] = [
                    'body' => null,
                    'image' => null,
                ];
            }
        }

        // Set the custom entry ID for consistency
        $entryData['id'] = $entryId;

        // Create current date for the topic entry
        $currentDate = new DateTime;

        // ---
        // Save topic entry using BaseEntryCreator's saveEntry method for consistent
        // handling
        // ---
        $savedTopic = $this->saveEntry(
            $collectionHandle,
            $site,
            $entrySlug,
            $entryData,
            $output,
            $currentDate,
            true
        );

        // ---
        // Adopt the id the entry was SAVED under, before anything downstream reads
        // `$entryId`. Overwriting a matched entry preserves its existing id (wordpress-importer-archive#767),
        // so the lookup below, `addEntryToTree()`, `topicIdMap` and the two
        // `KbLinkRegistry` registrations would otherwise all name an entry that was
        // just deleted — and `LinkProcessor::convertKbLink()` reads that registry to
        // emit `statamic://entry::<id>`, so the dangling reference wordpress-importer-archive#767 exists to
        // remove would simply reappear for knowledge-base content.
        //
        // Reassigning `$entryId` rather than introducing a second variable is
        // deliberate: every downstream use in this method is below this point, so
        // there is no path left that can still read the generated id.
        //
        // A topic that was not saved keeps the generated id and stays out of the tree.
        // ---
        if ($savedTopic instanceof Entry) {
            $entryId = self::mixedToString(
                $savedTopic->id(),
                $entryId
            );

            // Add the entry to the tree as a child of the specified parent
            $this->addEntryToTree(
                $entryId,
                $collectionHandle,
                $site,
                $parentId
            );
        }

        // ---
        // Store the mapping from WordPress term ID to Statamic entry ID for future
        // reference
        // ---
        $this->topicIdMap[$termId] = $entryId;

        // Register the kbtopic slug mapping for link processing
        KbLinkRegistry::registerKbtopicMappingBySlug(
            $termSlug,
            $entryId
        );

        // Register the kbtopic term ID mapping for block processing
        KbLinkRegistry::registerKbtopicMappingByTermId(
            $termId,
            $entryId
        );

        // Clean up variables to free up memory
        unset(
            $termId,
            $termSlug,
            $termName,
            $description,
            $entrySlug,
            $entryData,
            $blueprint,
            $templateDefault,
            $titleOptionsDefault,
            $currentDate
        );

        // Return the created entry ID for use in parent-child relationships
        return $entryId;
    }

    /**
     * Find the topic ID for an article based on its categories.
     *
     * Articles are associated with only one topic: the first kbtopic
     * category in the article's category list.
     *
     * @param  SimpleXMLElement  $item  The wp:post element
     * @return ?string The topic entry ID or null if no topic found
     */
    protected function findArticleTopic(
        SimpleXMLElement $item
    ): ?string {
        // Find categories with domain="kbtopic", and take the first
        $categories = $item->xpath('category[@domain="kbtopic"]');
        $firstCategory = \is_array($categories) ? ($categories[0] ?? null) : null;

        // An article with no topic category has no topic
        if (! $firstCategory instanceof SimpleXMLElement) {
            return null;
        }

        // Resolve the topic entry the category's nicename (slug) names
        return $this->findTopicEntryId((string) $firstCategory['nicename']);
    }

    /**
     * Find the entry ID of the topic created for a kbtopic nicename.
     *
     * A topic's entry slug is its term's slug, and every kbtopic term is in
     * the nicename map, so a nicename the map does not name names no topic.
     *
     * @param  string  $nicename  The kbtopic category nicename (slug)
     * @return ?string The topic entry ID, or null when no topic was created for it
     */
    private function findTopicEntryId(
        string $nicename
    ): ?string {
        // Resolve the nicename to its WordPress term ID
        $termId = self::getNestedMapString(
            $this->nicenameToTermIdMap,
            'kbtopic',
            $nicename
        );

        // Map the term ID to the topic entry created for it
        return $termId === null ? null : ($this->topicIdMap[$termId] ?? null);
    }

    /**
     * Extract term description from XML term element.
     *
     * This method attempts to extract description from two sources in priority order:
     *   1. wp:term_description element (standard WordPress term description)
     *   2. wp:termmeta with wp:meta_key = 'description' (custom meta field)
     *
     * @param  SimpleXMLElement  $termElement  The XML term element
     * @return string The extracted description or empty string if not found
     */
    protected function extractTermDescription(
        SimpleXMLElement $termElement
    ): string {
        // First, try to get description from wp:term_description element
        $description = XpathHelper::getStringValue(
            $termElement,
            'wp:term_description'
        );

        // Check if wp:term_description has content
        if (\trim($description) !== '') {
            // Return the description value from wp:term_description
            return $description;
        }

        // ---
        // Fall back to wp:termmeta with wp:meta_key = 'description'
        // ---

        // Get all termmeta elements for the term
        $termmeta = $termElement->xpath('wp:termmeta');

        // Check if no termmeta elements were found
        if (\is_array($termmeta)) {
            // Loop through each termmeta element to find description
            foreach ($termmeta as $meta) {
                // Extract the meta key
                $metaKey = XpathHelper::getStringValue(
                    $meta,
                    'wp:meta_key'
                );

                // Return the first 'description' meta value; a blank one strips to empty
                if ($metaKey === 'description') {
                    return XpathHelper::getStringValue(
                        $meta,
                        'wp:meta_value'
                    );
                }
            }
        }

        // Return empty string if no description was found
        return '';
    }

    /**
     * Find a term element in XML by term ID.
     *
     * This method searches through all terms in the XML parser to find
     * the term element with the matching term ID.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  string  $termId  The WordPress term ID to find
     * @return ?SimpleXMLElement The found term element or null if not found
     */
    protected function findTermElementById(
        XmlParser $xmlParser,
        string $termId
    ): ?SimpleXMLElement {
        // Get all terms from the XML parser
        $terms = $xmlParser->getTerms();

        // Iterate through all terms to find the one with matching term ID
        foreach ($terms as $term) {
            // Every term the parser returns is an element; this only narrows the type
            // @pest-mutate-ignore: InstanceOfToTrue, ContinueToBreak
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term ID from the current term element
            $currentTermId = XpathHelper::getStringValue(
                $term,
                'wp:term_id'
            );

            // Check if the term ID matches
            if ($currentTermId === $termId) {
                // Return the matching term element
                return $term;
            }
        }

        // Return null if no matching term was found
        return null;
    }
}
