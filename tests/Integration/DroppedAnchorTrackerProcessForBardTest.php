<?php

declare(strict_types=1);

/**
 * Tests that `TextProcessor::processForBard()` hands `DroppedAnchorTracker` what each
 * call drops and links to, so a link to a quote's `id` is reported and a link to a
 * paragraph's is not (#502).
 *
 * The real processors run, with the `id` attribute mapped for paragraphs and headings
 * as the host app maps it.
 *
 * @command  php vendor/bin/pest tests/Integration/DroppedAnchorTrackerProcessForBardTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\DroppedAnchorTracker;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    config()->set('statamic.bard_texstyle.attributes', ['paragraph' => ['id' => []], 'heading' => ['id' => []]]);
    DroppedAnchorTracker::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::setWordPressPageLink('https://example.edu/a/');
});

afterEach(function (): void {
    DroppedAnchorTracker::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
    (new ReflectionProperty(TextProcessor::class, 'cachedProcessors'))->setValue(null, []);
});

it('reports a link to a quote\'s id once processForBard() has dropped it, and not a link to a paragraph\'s', function (): void {
    $content = TextProcessor::processForBard(
        '<p id="intro"><a href="#quote">To the quote</a> and <a href="#intro">to the top</a></p>'
        .'<!-- wp:quote --><blockquote id="quote" class="wp-block-quote"><p>Quoted</p></blockquote><!-- /wp:quote -->',
        'default',
        app(AssetImporter::class),
        new BufferedOutput,
    );

    DroppedAnchorTracker::reportLinksToDroppedIds();

    expect(json_encode($content))->toContain('"id":"intro"')
        ->and(array_column(ImportWarningManager::getWarnings('content', 'links_to_dropped_ids'), 'link'))->toBe(['#quote'])
        ->and(array_column(ImportWarningManager::getWarnings('content', 'links_to_dropped_ids'), 'element'))->toBe(['<blockquote id="quote">']);
});
