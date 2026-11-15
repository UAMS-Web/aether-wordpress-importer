<?php

declare(strict_types=1);

/**
 * Tests what a taxonomy term created for a non-default site holds, on each of the
 * three paths that create one: `BaseEntryCreator::createTaxonomyTerm()`,
 * `BaseEntryCreator::createTaxonomyTermsFromStoredData()` and
 * `AudioSeriesManager::createAudioSeriesTerm()` (#304).
 *
 * The term is saved for real, in a taxonomy enabled on two sites and kept in a
 * directory of the test's own, then read back from that directory. Each case
 * asserts the term's slug, its title, that its data lives in the default site's
 * localization, and that the non-default site reads it through that localization.
 *
 * None of the three paths calls `Term::in($site)`. That call answers a
 * `LocalizedTerm` and changes nothing on the term, so a term created with it
 * holds what a term created without it holds.
 *
 * @command  php vendor/bin/pest tests/Integration/NonDefaultSiteTermCreationTest.php
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Stache\Query\TermQueryBuilder;
use Statamic\Stache\Stores\Store;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * What the probe `BaseEntryCreator` adds, so the batch path can be called.
 */
interface NonDefaultSiteTermCreationProbe
{
    /**
     * Call `createTaxonomyTermsFromStoredData()`.
     *
     * @param  list<array{original: string, name: string}>  $siteSpecificData  The terms
     * @param  string  $statamicTaxonomyHandle  The taxonomy
     * @param  string  $site  The site
     * @param  OutputInterface  $output  The output
     */
    public function createFromStoredData(
        array $siteSpecificData,
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output
    ): void;
}

/**
 * Build a `BaseEntryCreator` that exposes the batch term path.
 *
 * @return BaseEntryCreator&NonDefaultSiteTermCreationProbe The probe
 */
function nonDefaultSiteTermProbe(): BaseEntryCreator&NonDefaultSiteTermCreationProbe
{
    return new class extends BaseEntryCreator implements NonDefaultSiteTermCreationProbe
    {
        /**
         * {@inheritDoc}
         */
        public function createFromStoredData(
            array $siteSpecificData,
            string $statamicTaxonomyHandle,
            string $site,
            OutputInterface $output
        ): void {
            $this->createTaxonomyTermsFromStoredData($siteSpecificData, $statamicTaxonomyHandle, $site, $output);
        }

        /**
         * {@inheritDoc}
         */
        public function createEntries(
            string $site,
            string $collectionHandle,
            XmlParser $xmlParser,
            AssetImporter $assetImporter,
            OutputInterface $output,
            MetadataMapper $metadataMapper,
            ContentProcessor $contentProcessor,
            ?array $minervaKbSettings = null
        ): array {
            return [];
        }

        /**
         * {@inheritDoc}
         */
        public function getPostCategories(): array
        {
            return [];
        }

        /**
         * {@inheritDoc}
         */
        public function getPostTags(): array
        {
            return [];
        }

        /**
         * {@inheritDoc}
         */
        public function hasShowPeople(): bool
        {
            return false;
        }

        /**
         * {@inheritDoc}
         */
        public function validateTaxonomies(string $collectionHandle, string $site, OutputInterface $output): void {}
    };
}

/**
 * Read a Stache store's directory, or point it at another one.
 *
 * @param  string  $store  The store
 * @param  ?string  $directory  The directory to point it at, or null to leave it
 * @return string The directory
 */
function nonDefaultSiteTermDirectory(string $store, ?string $directory = null): string
{
    $stacheStore = Stache::store($store);

    if (! $stacheStore instanceof Store) {
        throw new RuntimeException("No {$store} store.");
    }

    if ($directory !== null) {
        $stacheStore->directory($directory);
    }

    return $stacheStore->directory();
}

/**
 * Save a taxonomy enabled on both sites.
 *
 * @param  string  $handle  The taxonomy
 */
function nonDefaultSiteTermTaxonomy(string $handle): void
{
    $taxonomy = TaxonomyFacade::make($handle);

    if (! $taxonomy instanceof Taxonomy) {
        throw new RuntimeException('Expected a taxonomy.');
    }

    $taxonomy->sites(['default', 'second']);
    $taxonomy->save();
}

/**
 * Read a saved term back from disk.
 *
 * @param  string  $id  The term id, `<taxonomy>::<slug>`
 * @return Term The term
 */
function nonDefaultSiteTermRead(string $id): Term
{
    // Drop what the store holds in memory, so the term is the one written to disk
    $store = Stache::store('terms');

    if (! $store instanceof Store) {
        throw new RuntimeException('No terms store.');
    }

    $store->clear();

    $found = TermFacade::find($id);

    if (! $found instanceof LocalizedTerm) {
        throw new RuntimeException("Expected a localized term for {$id}.");
    }

    $term = $found->term();

    if (! $term instanceof Term) {
        throw new RuntimeException("Expected the term {$id} localizes.");
    }

    return $term;
}

/**
 * Read a localization of a term.
 *
 * @param  Term  $term  The term
 * @param  string  $site  The site
 * @return LocalizedTerm The localization
 */
function nonDefaultSiteTermLocalization(Term $term, string $site): LocalizedTerm
{
    $localized = $term->in($site);

    if (! $localized instanceof LocalizedTerm) {
        throw new RuntimeException("Expected a localization in {$site}.");
    }

    return $localized;
}

/**
 * Read the data a term holds for one site.
 *
 * @param  Term  $term  The term
 * @param  string  $site  The site
 * @return array<array-key, mixed> The data
 */
function nonDefaultSiteTermData(Term $term, string $site): array
{
    $data = $term->dataForLocale($site);

    if (! $data instanceof Collection) {
        throw new RuntimeException("Expected the {$site} data as a collection.");
    }

    return $data->all();
}

/**
 * Read the ids of a taxonomy's terms in one site.
 *
 * @param  string  $taxonomy  The taxonomy
 * @param  string  $site  The site
 * @return list<mixed> The ids
 */
function nonDefaultSiteTermIdsIn(string $taxonomy, string $site): array
{
    $query = TermFacade::query();

    // Narrow each step, since the query's where() is untyped
    foreach (['taxonomy' => $taxonomy, 'site' => $site] as $column => $value) {
        $query = $query->where($column, $value);

        if (! $query instanceof TermQueryBuilder) {
            throw new RuntimeException('Expected a term query.');
        }
    }

    $terms = $query->get();

    if (! $terms instanceof Collection) {
        throw new RuntimeException('Expected the query to answer a collection.');
    }

    return array_values($terms->map(fn (mixed $found): mixed => $found instanceof LocalizedTerm ? $found->id() : null)->all());
}

/**
 * Assert what a term created for the `second` site holds.
 *
 * @param  string  $taxonomy  The taxonomy
 * @param  string  $slug  The slug the term was saved under
 * @param  string  $title  The title it was saved with
 */
function nonDefaultSiteTermAssert(string $taxonomy, string $slug, string $title): void
{
    $term = nonDefaultSiteTermRead("{$taxonomy}::{$slug}");

    // Its data is held by the default site's localization
    expect($term->slug())->toBe($slug)
        ->and($term->defaultLocale())->toBe('default')
        ->and(nonDefaultSiteTermData($term, 'default')['title'] ?? null)->toBe($title);

    // The second site holds none of its own, and reads the title through the default
    expect(nonDefaultSiteTermData($term, 'second'))->toBe([])
        ->and(nonDefaultSiteTermLocalization($term, 'second')->value('title'))->toBe($title);

    // A query for the second site's terms finds it
    expect(nonDefaultSiteTermIdsIn($taxonomy, 'second'))->toBe(["{$taxonomy}::{$slug}"]);
}

beforeEach(function (): void {
    config(['statamic.search.indexes' => [], 'statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'second' => ['name' => 'Second Site', 'locale' => 'en_US', 'url' => '/second/'],
    ]);
    BaseEntryCreator::clearTaxonomyCaches();

    // Keep the taxonomy and its terms in a directory of this test's own
    $this->taxonomyDirectories = [nonDefaultSiteTermDirectory('taxonomies'), nonDefaultSiteTermDirectory('terms')];
    $this->root = sys_get_temp_dir().'/non-default-site-terms-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);
    nonDefaultSiteTermDirectory('taxonomies', $this->root);
    nonDefaultSiteTermDirectory('terms', $this->root);
});

afterEach(function (): void {
    BaseEntryCreator::clearTaxonomyCaches();

    [$taxonomies, $terms] = $this->taxonomyDirectories;

    nonDefaultSiteTermDirectory('taxonomies', $taxonomies);
    nonDefaultSiteTermDirectory('terms', $terms);

    $root = $this->root ?? null;

    if (is_string($root)) {
        File::deleteDirectory($root);
    }
});

it('creates a single term for a non-default site under the site\'s slug and title', function (): void {
    nonDefaultSiteTermTaxonomy('probe_tax');
    $output = new BufferedOutput;

    $created = BaseEntryCreator::createTaxonomyTerm('probe_tax', 'x-term', 'second', $output);

    expect($created)->toBeTrue()
        ->and($output->fetch())->toContain("Created 'probe_tax::second_x-term'");
    nonDefaultSiteTermAssert('probe_tax', 'second_x-term', "Second Site \u{2014} X Term");
});

it('creates batched terms for a non-default site under the site\'s slug and title', function (): void {
    nonDefaultSiteTermTaxonomy('probe_tax');
    $output = new BufferedOutput;

    nonDefaultSiteTermProbe()->createFromStoredData(
        [['original' => 'term-1', 'name' => 'Term 1']],
        'probe_tax',
        'second',
        $output
    );

    expect($output->fetch())->toContain("Created 'probe_tax::second_term-1'");
    nonDefaultSiteTermAssert('probe_tax', 'second_term-1', "Second Site \u{2014} Term 1");
});

it('creates an audio series term for a non-default site under the series\' own slug and title', function (): void {
    $config = AudioSeriesConfig::createHeresToYourHealth();
    nonDefaultSiteTermTaxonomy($config->getTaxonomyHandle());
    $output = new BufferedOutput;

    $created = AudioSeriesManager::createAudioSeriesTerm($config, 'second', $output);

    expect($created)->toBeTrue();
    nonDefaultSiteTermAssert($config->getTaxonomyHandle(), $config->getTermSlug(), $config->getTermName());
});
