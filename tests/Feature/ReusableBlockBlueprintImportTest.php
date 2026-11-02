<?php

declare(strict_types=1);

use Illuminate\Testing\PendingCommand;
use Statamic\Entries\Entry;
use Statamic\Facades\Antlers;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Fields\Blueprint;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Proves a reusable block's blueprint follows its content, and that the host renders
 * the result from either kind of page, through a real import (#239).
 *
 * A block is only ever imported for a page's sake, so only the command runs the whole
 * chain, and the sets that render an entry live in the host app.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/ReusableBlockBlueprintImportTest.php
 */

/**
 * One item of a WordPress export.
 *
 * @param  string  $postType  The post type
 * @param  int  $postId  The post ID
 * @param  string  $slug  The slug
 * @param  string  $content  The item content
 * @return string The item XML
 */
function blueprintImportItem(string $postType, int $postId, string $slug, string $content): string
{
    return '<item>
        <title><![CDATA['.$slug.']]></title>
        <link>https://supplychain.uams.edu/'.$slug.'/</link>
        <dc:creator><![CDATA[uamsonline]]></dc:creator>
        <guid isPermaLink="false">https://supplychain.uams.edu/?p='.$postId.'</guid>
        <content:encoded><![CDATA['.$content.']]></content:encoded>
        <excerpt:encoded><![CDATA[]]></excerpt:encoded>
        <wp:post_id>'.$postId.'</wp:post_id>
        <wp:post_date><![CDATA[2025-01-01 10:00:00]]></wp:post_date>
        <wp:post_date_gmt><![CDATA[2025-01-01 10:00:00]]></wp:post_date_gmt>
        <wp:post_modified><![CDATA[2025-01-01 10:00:00]]></wp:post_modified>
        <wp:post_modified_gmt><![CDATA[2025-01-01 10:00:00]]></wp:post_modified_gmt>
        <wp:post_name><![CDATA['.$slug.']]></wp:post_name>
        <wp:status><![CDATA[publish]]></wp:status>
        <wp:post_parent>0</wp:post_parent>
        <wp:menu_order>0</wp:menu_order>
        <wp:post_type><![CDATA['.$postType.']]></wp:post_type>
    </item>';
}

/**
 * Run the import command over an export.
 *
 * @param  WordPressImportTestCase  $test  The test
 * @param  string  $file  The export path
 */
function blueprintImportRun(WordPressImportTestCase $test, string $file): void
{
    $command = $test->artisan('statamic:import:wordpress', ['file' => $file]);

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('Expected a PendingCommand instance.');
    }

    $command
        ->expectsQuestion('Which site would you like to import the content into?', 'default')
        ->expectsQuestion('Which content type would you like to import?', 'page (2)')
        ->expectsQuestion('How should existing entries in the \'pages\' collection with matching IDs be handled?', 'Overwrite matching files')
        ->expectsQuestion('How should existing assets in the relevant container be handled?', 'Overwrite matching files')
        ->expectsQuestion('Would you like to import more content?', false)
        ->assertExitCode(0);
    $command->run();
}

/**
 * The entry with a slug in a collection.
 *
 * @param  string  $collection  The collection handle
 * @param  string  $slug  The slug
 * @return Entry The entry
 */
function blueprintImportEntry(string $collection, string $slug): Entry
{
    foreach (EntryFacade::whereCollection($collection) as $entry) {
        if ($entry instanceof Entry && $entry->locale() === 'default' && $entry->slug() === $slug) {
            return $entry;
        }
    }

    throw new RuntimeException("No entry '{$slug}' in '{$collection}'.");
}

/**
 * The handle of an entry's blueprint.
 *
 * @param  Entry  $entry  The entry
 * @return ?string The handle
 */
function blueprintImportHandle(Entry $entry): ?string
{
    $blueprint = $entry->blueprint();

    return $blueprint instanceof Blueprint ? $blueprint->handle() : null;
}

/**
 * Render one field of a page through the host's partials.
 *
 * @param  Entry  $page  The page
 * @param  string  $field  The field holding the page's body
 * @param  string  $partials  The partial directory for that field's sets
 * @return string The rendered body
 */
function blueprintImportRender(Entry $page, string $field, string $partials): string
{
    $values = $page->toAugmentedArray([$field]);

    return (string) Antlers::parse(
        "{{ {$field} scope=\"block\" }}{{ partial src=\"{$partials}/{type}\" }}{{ /{$field} }}",
        is_array($values) ? $values : [],
        true
    );
}

it('gives each block the blueprint its content needs, and the host renders it from either kind of page', function (): void {
    $widget = '<!-- wp:html --><div class="lwcw" data-options="id=20&format=html"></div><!-- /wp:html -->';
    $reference = '<!-- wp:block {"ref":94601} /-->';
    $file = $this->createTemporaryXmlFile($this->buildXmlContent([
        blueprintImportItem('wp_block', 94601, 'zz-blueprint-plain', '<!-- wp:paragraph --><p>ReusedWords</p><!-- /wp:paragraph -->'),
        blueprintImportItem('wp_block', 94602, 'zz-blueprint-built', $widget),
        blueprintImportItem('page', 94603, 'zz-blueprint-bard', '<!-- wp:paragraph --><p>Page.</p><!-- /wp:paragraph -->'.$reference.'<!-- wp:paragraph --><p>Page end.</p><!-- /wp:paragraph -->'),
        blueprintImportItem('page', 94604, 'zz-blueprint-landing', $widget.$reference.'<!-- wp:block {"ref":94602} /-->'),
    ]));

    try {
        blueprintImportRun($this, $file);

        $plain = blueprintImportEntry('reusable_content', 'zz-blueprint-plain');
        $built = blueprintImportEntry('reusable_content', 'zz-blueprint-built');

        expect(blueprintImportHandle($plain))->toBe('reusable_content_basic_page')
            ->and($plain->get('content'))->toBeArray()->not->toBeEmpty()
            ->and($plain->has('page_builder'))->toBeFalse()
            ->and(blueprintImportHandle($built))->toBe('reusable_content_landing_page')
            ->and($built->get('page_builder'))->toBeArray()->not->toBeEmpty()
            ->and($built->has('content'))->toBeFalse();

        $bardPage = blueprintImportEntry('pages', 'zz-blueprint-bard');
        $landingPage = blueprintImportEntry('pages', 'zz-blueprint-landing');

        expect(blueprintImportHandle($bardPage))->toBe('basic_page')
            ->and(blueprintImportHandle($landingPage))->toBe('landing_page')
            ->and(blueprintImportRender($bardPage, 'content', 'sets'))->toContain('ReusedWords')
            ->and(blueprintImportRender($landingPage, 'page_builder', 'page_builder'))->toContain('ReusedWords');
    } finally {
        foreach ([['pages', 'zz-blueprint-bard'], ['pages', 'zz-blueprint-landing'], ['reusable_content', 'zz-blueprint-plain'], ['reusable_content', 'zz-blueprint-built']] as [$collection, $slug]) {
            foreach (EntryFacade::whereCollection($collection) as $entry) {
                if ($entry instanceof Entry && $entry->slug() === $slug) {
                    $entry->delete();
                }
            }
        }
    }
});
