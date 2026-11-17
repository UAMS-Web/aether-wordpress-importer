<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins the slug a post exported with an empty `wp:post_name` is given (#588).
 *
 * WordPress leaves the name empty on many drafts, and the importer builds the
 * slug from the title. A draft whose title matches a published post used to take
 * the published post's slug, and so its URI, and the published post stopped
 * resolving. These cases drive `PostEntryCreator::createEntries()` over a WXR file
 * on disk, the way `PostReimportPreservesEntryIdTest` does, and read the slugs of
 * the entries it saved.
 *
 * `ContentProcessor` is mocked for the reason that file's docblock gives: its
 * constructor needs the host app's `BardContentSanitizer`, and content does not
 * bear on the slug.
 *
 * Base class: `StatamicTestCase` (`tests/Integration`).
 *
 * @command  php vendor/bin/pest tests/Integration/UnnamedPostSlugTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // Dated, as the host app's `posts` collection is
    $collection = CollectionFacade::make('posts');
    $collection->sites(['default']);
    $collection->dated(true);
    $collection->save();

    $blueprint = BlueprintFacade::make('post');
    $blueprint->setNamespace('collections.posts');
    $blueprint->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ],
    ]);
    $blueprint->save();
});

afterEach(function (): void {
    Mockery::close();

    // Process-wide state each pass writes, reset as `PostReimportPreservesEntryIdTest` resets it
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    ProcessingContext::clearXmlPath();

    // Blueprints are not Stache items, so the trait does not keep them off disk
    $blueprint = BlueprintFacade::find('collections.posts.post');

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }

    TemporaryFileRegistry::sweep();
});

it('moves an unnamed draft off the slug of a published post later in the export', function (): void {
    // The draft comes first, as five of the seven news.uams.edu pairs do
    $export = unnamedPostExport([
        unnamedPostItem('158427', 'Gardner Admitted', '', 'draft'),
        unnamedPostItem('158429', 'Gardner Admitted', 'gardner-admitted', 'publish'),
    ]);

    unnamedPostRun($export);

    expect(unnamedPostSlugs())->toBe([
        '158427' => 'gardner-admitted-158427',
        '158429' => 'gardner-admitted',
    ]);
});

it('moves an unnamed draft off the slug of a published post earlier in the export', function (): void {
    $export = unnamedPostExport([
        unnamedPostItem('142163', 'Celebration', 'celebration', 'publish'),
        unnamedPostItem('142175', 'Celebration', '', 'draft'),
    ]);

    unnamedPostRun($export);

    expect(unnamedPostSlugs())->toBe([
        '142163' => 'celebration',
        '142175' => 'celebration-142175',
    ]);
});

it('gives an unnamed draft the same slug again on a re-import', function (): void {
    $export = unnamedPostExport([
        unnamedPostItem('158427', 'Gardner Admitted', '', 'draft'),
        unnamedPostItem('158429', 'Gardner Admitted', 'gardner-admitted', 'publish'),
        unnamedPostItem('158430', 'Only Title', '', 'draft'),
    ]);

    unnamedPostRun($export);
    $first = unnamedPostSlugs();
    $console = unnamedPostRun($export);

    // The second pass updated the three entries in place rather than adding copies
    expect(unnamedPostSlugs())->toBe($first)
        ->and(\substr_count($console, 'Created entry'))->toBe(3)
        ->and(unnamedPostEntryCount())->toBe(3)
        ->and($first)->toMatchArray(['158430' => 'only-title']);
});

it('keeps the title slug of an unnamed post nothing else holds', function (): void {
    $export = unnamedPostExport([
        unnamedPostItem('300', 'A Lone Draft', '', 'draft'),
    ]);

    unnamedPostRun($export);

    expect(unnamedPostSlugs())->toBe(['300' => 'a-lone-draft']);
});

it('moves an unnamed post off a slug an entry outside the export holds', function (): void {
    // An entry an editor made in the Control Panel, with an ID of no WordPress shape
    $existing = EntryFacade::make();

    if (! $existing instanceof Entry) {
        throw new RuntimeException('Could not make an entry.');
    }

    $existing->collection('posts');
    $existing->locale('default');
    $existing->id('cp-authored');
    $existing->slug('a-lone-draft');
    $existing->date('2024-01-01');
    $existing->data(['title' => 'A Lone Draft']);
    $existing->save();

    $export = unnamedPostExport([
        unnamedPostItem('300', 'A Lone Draft', '', 'draft'),
    ]);

    unnamedPostRun($export);

    expect(unnamedPostSlugs())->toBe(['300' => 'a-lone-draft-300']);
});

it('counts on from the ID when the suffixed slug is taken too', function (): void {
    $export = unnamedPostExport([
        unnamedPostItem('300', 'A Lone Draft', '', 'draft'),
        unnamedPostItem('301', 'Taken', 'a-lone-draft', 'publish'),
        unnamedPostItem('302', 'Also Taken', 'a-lone-draft-300', 'publish'),
    ]);

    unnamedPostRun($export);

    expect(unnamedPostSlugs())->toMatchArray(['300' => 'a-lone-draft-300-2']);
});

/**
 * Perform one post-import pass over an export on disk.
 *
 * @param  string  $export  Path to the WXR file
 * @return string The console output of the pass
 */
function unnamedPostRun(string $export): string
{
    $parser = new XmlParser;
    $parser->parse($export);

    $contentProcessor = Mockery::mock(ContentProcessor::class, [
        'process' => [
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]],
            'page_builder' => [],
        ],
    ]);

    if (! $contentProcessor instanceof ContentProcessor) {
        throw new RuntimeException('Could not build the content processor stub.');
    }

    $output = new BufferedOutput;

    new PostEntryCreator()->createEntries(
        'default',
        'posts',
        $parser,
        new AssetImporter,
        $output,
        new MetadataMapper,
        $contentProcessor
    );

    return $output->fetch();
}

/**
 * The slug of each imported post entry, keyed by WordPress post ID.
 *
 * The ID is read from the entry ID, `slug-postId-random`. An entry with another
 * ID shape was not imported, and is left out.
 *
 * @return array<string, string> Slugs keyed by WordPress post ID, sorted by ID
 */
function unnamedPostSlugs(): array
{
    $slugs = [];

    foreach (unnamedPostEntries() as $entry) {
        $id = $entry instanceof Entry ? $entry->id() : null;
        $slug = $entry instanceof Entry ? $entry->slug() : null;

        if (! \is_string($id) || ! \is_string($slug)) {
            continue;
        }

        $parts = \explode('-', $id);

        if (\count($parts) < 3) {
            continue;
        }

        $slugs[$parts[\count($parts) - 2]] = $slug;
    }

    \ksort($slugs, \SORT_STRING);

    return $slugs;
}

/**
 * The number of entries in the `posts` collection.
 *
 * @return int The count
 */
function unnamedPostEntryCount(): int
{
    return \count(unnamedPostEntries());
}

/**
 * The entries in the `posts` collection.
 *
 * @return list<mixed> The entries
 */
function unnamedPostEntries(): array
{
    $query = EntryQueryHelper::query();
    $query->where('collection', 'posts');

    $entries = $query->get();

    // A query's get() returns a collection; the check only narrows the type
    return $entries instanceof Collection ? \array_values($entries->all()) : [];
}

/**
 * Build one WXR `<item>` for a post.
 *
 * @param  string  $postId  The WordPress post ID
 * @param  string  $title  The post title
 * @param  string  $postName  The `wp:post_name`, '' for none
 * @param  string  $status  The `wp:status`
 * @return string The item XML
 */
function unnamedPostItem(string $postId, string $title, string $postName, string $status): string
{
    return <<<XML
<item>
  <title>$title</title>
  <link>https://example.test/?p=$postId</link>
  <content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>
  <wp:post_id>$postId</wp:post_id>
  <wp:post_name>$postName</wp:post_name>
  <wp:post_date>2024-02-01 10:00:00</wp:post_date>
  <wp:status>$status</wp:status>
  <wp:post_type>post</wp:post_type>
</item>
XML;
}

/**
 * Write a WXR export holding the given items.
 *
 * @param  list<string>  $items  The item XML
 * @return string The path to the written export
 */
function unnamedPostExport(array $items): string
{
    $path = TemporaryFileRegistry::create('unnamed-post-slug');
    $body = \implode("\n", $items);

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Unnamed post fixture</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
$body
</channel></rss>
XML);

    return $path;
}
