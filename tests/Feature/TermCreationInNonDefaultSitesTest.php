<?php

declare(strict_types=1);

use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\MockInterface;
use Statamic\Contracts\Taxonomies\Taxonomy;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Sites\Site;
use Statamic\Taxonomies\Term;
use Statamic\Taxonomies\TermCollection;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;

uses(TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * Tests the existing-term preload in
 * `BaseEntryCreator::createTaxonomyTermsFromStoredData()` for a non-default site.
 *
 * What a term created for a non-default site holds (its slug, its title, and the
 * localization it is read in) is asserted against a saved term in
 * `tests/Integration/NonDefaultSiteTermCreationTest.php` (#304).
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/TermCreationInNonDefaultSitesTest.php
 */

/**
 * Covers the issue wordpress-importer-archive#46 performance fix: createTaxonomyTermsFromStoredData()
 * preloads all existing terms for the taxonomy with a single whereTaxonomy()
 * query and tests membership in-memory, rather than a per-term find(). An
 * already-existing term is reported as already-present and never passed to
 * TermFacade::make().
 */
it('preloads existing terms via a single whereTaxonomy query instead of per-term find', function (): void {
    $taxonomyHandle = 'test_taxonomy';
    $site = 'non-default-site';
    $output = new BufferedOutput;
    $siteSpecificData = [
        [
            'original' => 'term-1',
            'name' => 'Term 1',
        ],
    ];

    $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug('term-1', $site);
    $cacheKey = "{$taxonomyHandle}::{$siteSpecificSlug}";

    termCreationMockSiteFacade($site);
    termCreationMockTaxonomyFacade($taxonomyHandle);

    $existingTerm = Mockery::mock(Term::class);
    termCreationSingleExpectation($existingTerm, 'id')->andReturn($cacheKey);

    termCreationExpectTermFacade('whereTaxonomy')
        ->with($taxonomyHandle)
        ->once()
        ->andReturn(TermCollection::make([$existingTerm]));

    termCreationExpectTermFacade('find')->never();
    termCreationExpectTermFacade('make')->never();

    $testCreator = termCreationMakeTestCreator();
    termCreationResetBaseEntryCreatorCaches($testCreator, 'Skip matching terms');

    $testCreator->test_create_taxonomy_terms_from_stored_data(
        $siteSpecificData,
        $taxonomyHandle,
        $site,
        $output
    );

    // Existing terms are reported as already-present, never re-created.
    expect($output->fetch())->toContain('already exists');
});

/**
 * Exposes createTaxonomyTermsFromStoredData() for term-creation tests.
 */
interface TermCreationTaxonomyTermsTestCreator
{
    /**
     * @param  list<array{original: string, name: string}>  $siteSpecificData
     */
    public function test_create_taxonomy_terms_from_stored_data(
        array $siteSpecificData,
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output
    ): void;
}

/**
 * Forwards Mockery facade expectation chains for static analysis.
 */
final class TermCreationFacadeExpectationChain
{
    private Expectation $expectation;

    public function __construct(ExpectationInterface $composite)
    {
        if (! $composite instanceof CompositeExpectation) {
            throw new RuntimeException('Expected a Mockery CompositeExpectation from the facade.');
        }

        $this->expectation = termCreationExtractSingleExpectation($composite);
    }

    public function with(mixed ...$args): self
    {
        $this->expectation->with(...$args);

        return $this;
    }

    public function once(): self
    {
        $this->expectation->once();

        return $this;
    }

    public function never(): self
    {
        $this->expectation->never();

        return $this;
    }

    public function andReturn(mixed ...$args): self
    {
        $this->expectation->andReturn(...$args);

        return $this;
    }
}

/**
 * Return the sole Expectation from a mock shouldReceive() call.
 */
function termCreationSingleExpectation(MockInterface $mock, string $method): Expectation
{
    // Mockery declares an Expectation, but shouldReceive() returns a CompositeExpectation;
    // reading its _expectations property throws if that ever changes
    return termCreationExtractSingleExpectation($mock->shouldReceive($method));
}

/**
 * Unwrap the single Expectation held inside a CompositeExpectation.
 */
function termCreationExtractSingleExpectation(ExpectationInterface $composite): Expectation
{
    $reflection = new ReflectionClass(CompositeExpectation::class);
    $property = $reflection->getProperty('_expectations');
    $property->setAccessible(true);
    $rawExpectations = $property->getValue($composite);

    expect($rawExpectations)->toBeArray();

    if (! is_array($rawExpectations)) {
        throw new RuntimeException('Expected the Mockery _expectations property to be an array.');
    }

    expect($rawExpectations)->toHaveCount(1);

    $expectation = $rawExpectations[0] ?? null;
    expect($expectation)->toBeInstanceOf(Expectation::class);

    if (! $expectation instanceof Expectation) {
        throw new RuntimeException('Expected a Mockery Expectation instance.');
    }

    return $expectation;
}

/**
 * Return a chainable wrapper around a Term facade shouldReceive() call.
 */
function termCreationExpectTermFacade(string $method): TermCreationFacadeExpectationChain
{
    return new TermCreationFacadeExpectationChain(TermFacade::shouldReceive($method));
}

/**
 * Return a chainable wrapper around a Site facade shouldReceive() call.
 */
function termCreationExpectSiteFacade(string $method): TermCreationFacadeExpectationChain
{
    return new TermCreationFacadeExpectationChain(SiteFacade::shouldReceive($method));
}

/**
 * Return a chainable wrapper around a Taxonomy facade shouldReceive() call.
 */
function termCreationExpectTaxonomyFacade(string $method): TermCreationFacadeExpectationChain
{
    return new TermCreationFacadeExpectationChain(TaxonomyFacade::shouldReceive($method));
}

/**
 * Mock SiteFacade::get() for a non-default site handle.
 */
function termCreationMockSiteFacade(string $site): void
{
    $mockSite = Mockery::mock(Site::class);
    termCreationSingleExpectation($mockSite, 'handle')->andReturn($site);
    termCreationSingleExpectation($mockSite, 'name')->andReturn('Non Default Site');

    termCreationExpectSiteFacade('get')
        ->with($site)
        ->andReturn($mockSite);
}

/**
 * Mock TaxonomyFacade::find() for the given taxonomy handle.
 */
function termCreationMockTaxonomyFacade(string $taxonomyHandle): void
{
    $mockTaxonomy = Mockery::mock(Taxonomy::class);
    termCreationExpectTaxonomyFacade('find')
        ->with($taxonomyHandle)
        ->andReturn($mockTaxonomy);
}

/**
 * Reset BaseEntryCreator static caches and optional overwrite behavior.
 */
function termCreationResetBaseEntryCreatorCaches(
    ?BaseEntryCreator $creator = null,
    ?string $overwriteBehavior = null
): void {
    $reflection = new ReflectionClass(BaseEntryCreator::class);

    foreach (['processedTermsCache', 'siteNamesCache'] as $staticProperty) {
        $property = $reflection->getProperty($staticProperty);
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    if ($overwriteBehavior !== null && $creator !== null) {
        $overwriteProperty = $reflection->getProperty('taxonomyOverwriteBehavior');
        $overwriteProperty->setAccessible(true);
        $overwriteProperty->setValue($creator, $overwriteBehavior);
    }
}

/**
 * Build a concrete BaseEntryCreator subclass exposing the protected
 * createTaxonomyTermsFromStoredData() method for testing.
 *
 * @return TermCreationTaxonomyTermsTestCreator&BaseEntryCreator
 */
function termCreationMakeTestCreator(): TermCreationTaxonomyTermsTestCreator
{
    return new class extends BaseEntryCreator implements TermCreationTaxonomyTermsTestCreator
    {
        public function test_create_taxonomy_terms_from_stored_data(
            array $siteSpecificData,
            string $statamicTaxonomyHandle,
            string $site,
            OutputInterface $output
        ): void {
            $this->createTaxonomyTermsFromStoredData(
                $siteSpecificData,
                $statamicTaxonomyHandle,
                $site,
                $output
            );
        }

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

        public function getPostCategories(): array
        {
            return [];
        }

        public function getPostTags(): array
        {
            return [];
        }

        public function hasShowPeople(): bool
        {
            return false;
        }

        public function validateTaxonomies(
            string $collectionHandle,
            string $site,
            OutputInterface $output
        ): void {
            // No-op for testing
        }
    };
}
