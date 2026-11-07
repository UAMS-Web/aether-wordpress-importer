<?php

declare(strict_types=1);

/**
 * Tests that `DroppedAnchorTracker` warns for a link whose fragment names an `id` the
 * import dropped from its target page, on the same page or another, and stays quiet
 * for an `id` the import kept or a fragment that names nothing (#502).
 *
 * The tracker, `ProcessingContext` and `ImportWarningManager` are process-wide static
 * state, so every case starts and ends with all three cleared.
 *
 * @command  php vendor/bin/pest tests/Unit/DroppedAnchorTrackerTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\DroppedAnchorTracker;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

beforeEach(function (): void {
    DroppedAnchorTracker::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    DroppedAnchorTracker::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Record one piece of content as the given page.
 *
 * @param  array<int|string, mixed>  $bardContent  The Bard content the HTML became
 */
function droppedAnchorRecord(string $pageLink, string $html, array $bardContent = []): void
{
    ProcessingContext::setWordPressPageLink($pageLink);
    DroppedAnchorTracker::record($html, $bardContent);
}

/**
 * Run the report and return its warnings.
 *
 * @return list<mixed>
 */
function droppedAnchorWarnings(): array
{
    DroppedAnchorTracker::reportLinksToDroppedIds();

    return array_values(ImportWarningManager::getWarnings('content', 'links_to_dropped_ids'));
}

it('warns for a same-page link to an id the import dropped, naming the page, the link and the element', function (string $tag, string $element): void {
    droppedAnchorRecord('https://example.edu/a/', "<p><a href=\"#anchor\">Jump</a></p>{$element}", [
        ['type' => 'paragraph', 'attrs' => ['id' => null], 'content' => [['type' => 'text', 'text' => 'Jump']]],
    ]);

    expect(droppedAnchorWarnings())->toBe([[
        'page_link' => 'https://example.edu/a/',
        'link' => '#anchor',
        'target_page' => 'https://example.edu/a/',
        'element' => "<{$tag} id=\"anchor\">",
        'reason' => 'The link names an id the import dropped, so it lands at the top of the page.',
    ]]);
})->with([
    'a quote' => ['blockquote', '<blockquote id="anchor" class="wp-block-quote"><p>Q</p></blockquote>'],
    'an image' => ['img', '<img id="anchor" src="a.jpg">'],
    'an image\'s figure' => ['figure', '<figure id="anchor" class="wp-block-image"><img src="a.jpg"></figure>'],
]);

it('warns for a link from another imported page, however the URL names the target', function (string $target, string $href, string $from = 'https://example.edu/b/'): void {
    droppedAnchorRecord($target, '<blockquote id="anchor"><p>Q</p></blockquote>');
    droppedAnchorRecord($from, "<p><a href=\"{$href}\">See</a></p>");

    expect(array_column(droppedAnchorWarnings(), 'target_page'))->toBe([$target])
        ->and(array_column(droppedAnchorWarnings(), 'link'))->toBe([$href]);
})->with([
    'an absolute URL' => ['https://example.edu/a/', 'https://example.edu/a/#anchor'],
    'no trailing slash' => ['https://example.edu/a/', 'https://example.edu/a#anchor'],
    'another scheme and case' => ['https://example.edu/a/', 'http://EXAMPLE.edu/a/#anchor'],
    'a root-relative URL' => ['https://example.edu/a/', '/a/#anchor'],
    'a relative URL' => ['https://example.edu/a/', '../a/#anchor'],
    'a page id' => ['https://example.edu/?page_id=5', 'https://example.edu/?page_id=5#anchor'],
    'a page id, query only, from the root' => ['https://example.edu/?page_id=5', '?page_id=5#anchor', 'https://example.edu/?page_id=7'],
    'www. on the link only' => ['https://example.edu/a/', 'https://www.example.edu/a/#anchor'],
    'www. on the page only' => ['https://www.example.edu/a/', 'https://example.edu/a/#anchor'],
    'percent-encoding in another case' => ['https://example.edu/caf%c3%a9/', 'https://example.edu/caf%C3%A9/#anchor'],
    'the default port named' => ['https://example.edu/a/', 'https://example.edu:443/a/#anchor'],
    'a root-relative URL on a page with a port' => ['https://example.edu:8443/a/', '/a/#anchor', 'https://example.edu:8443/b/'],
    'the default http port named' => ['http://example.edu/a/', 'http://example.edu:80/a/#anchor'],
    'percent-encoding in another case, the other way' => ['https://example.edu/caf%C3%A9/', 'https://example.edu/caf%c3%a9/#anchor'],
]);

it('strips www. only from the start of a host (#505)', function (): void {
    droppedAnchorRecord('https://a.example.edu/a/', '<blockquote id="anchor"><p>Q</p></blockquote>');
    droppedAnchorRecord('https://example.edu/b/', '<p><a href="https://a.www.example.edu/a/#anchor">See</a></p>');

    expect(droppedAnchorWarnings())->toBe([]);
});

it('names the element with the id when an <a name> carries the same value, as a browser scrolls to the id (#505)', function (): void {
    droppedAnchorRecord('https://example.edu/a/', '<a name="anchor"></a><blockquote id="anchor"><p>Q</p></blockquote><a href="#anchor">Up</a>');

    expect(array_column(droppedAnchorWarnings(), 'element'))->toBe(['<blockquote id="anchor">']);
});

it('matches an anchor\'s value as a browser does, without trimming it (#505)', function (string $html, int $warnings): void {
    droppedAnchorRecord('https://example.edu/a/', $html);

    expect(droppedAnchorWarnings())->toHaveCount($warnings);
})->with([
    'a padded name and a bare fragment' => ['<a name=" anchor "></a><a href="#anchor">Up</a>', 0],
    'a padded name and a padded fragment' => ['<a name=" anchor "></a><a href="#%20anchor%20">Up</a>', 1],
    'an <a> with an id and another name, linked by the name' => ['<a id="first" name="anchor"></a><a href="#anchor">Up</a>', 1],
]);

it('warns for a link to an <a name> anchor, which the import keeps no trace of (#505)', function (string $html): void {
    droppedAnchorRecord('https://example.edu/a/', $html.'<p><a href="#anchor">Up</a></p>');

    expect(array_column(droppedAnchorWarnings(), 'element'))->toBe(['<a name="anchor">']);
})->with([
    'an empty anchor' => ['<p><a name="anchor"></a>Q</p>'],
    'an anchor around text' => ['<p><a name="anchor">Q</a></p>'],
    'single quotes' => ["<p><a name='anchor'>Q</a></p>"],
]);

it('records an <a name> anchor from a piece of content that holds no link or id (#505)', function (): void {
    droppedAnchorRecord('https://example.edu/a/', '<p><a name="anchor"></a>Q</p>');
    droppedAnchorRecord('https://example.edu/a/', '<p><a href="#anchor">Up</a></p>');

    expect(array_column(droppedAnchorWarnings(), 'element'))->toBe(['<a name="anchor">']);
});

it('raises no warning for a name on an element other than <a>, or an <a name> whose value the import keeps as an id (#505)', function (string $html, array $bardContent): void {
    droppedAnchorRecord('https://example.edu/a/', $html.'<p><a href="#anchor">Up</a></p>', $bardContent);

    expect(droppedAnchorWarnings())->toBe([]);
})->with([
    'a form field' => ['<input name="anchor">', []],
    'a form field with an id' => ['<input id="field" name="anchor">', []],
    'kept as an id' => ['<h2 id="anchor"><a name="anchor"></a>Head</h2>', [['type' => 'heading', 'attrs' => ['id' => 'anchor']]]],
]);

it('raises no warning for an id the import keeps, in the same piece of content or another on its page', function (): void {
    droppedAnchorRecord('https://example.edu/a/', '<p id="intro">Intro</p><p><a href="#intro">Up</a></p>', [
        ['type' => 'paragraph', 'attrs' => ['id' => 'intro']],
    ]);
    droppedAnchorRecord('https://example.edu/a/', '<div id="kept">Body</div>');
    droppedAnchorRecord('https://example.edu/a/', '<h2 id="kept">Body</h2><p><a href="#kept">Body</a></p>', [
        ['type' => 'heading', 'attrs' => ['id' => 'kept']],
    ]);

    expect(droppedAnchorWarnings())->toBe([]);
});

it('raises no warning for a fragment that names nothing on its target, a page this run did not import, or a link that is not to a page', function (string $href): void {
    droppedAnchorRecord('https://example.edu/a/', "<blockquote id=\"anchor\"><p>Q</p></blockquote><p><a href=\"{$href}\">x</a></p>");

    expect(droppedAnchorWarnings())->toBe([]);
})->with([
    'a fragment naming nothing' => ['#elsewhere'],
    'an empty fragment' => ['#'],
    'another page' => ['https://example.edu/other/#anchor'],
    'another host' => ['https://elsewhere.example/a/#anchor'],
    'another port' => ['https://example.edu:8443/a/#anchor'],
    'the http port on an https URL' => ['https://example.edu:80/a/#anchor'],
    'a mailto link' => ['mailto:a@example.edu#anchor'],
]);

it('reports the same link on the same page once, and on two pages twice', function (): void {
    droppedAnchorRecord('https://example.edu/a/', '<blockquote id="anchor"><p>Q</p></blockquote><a href="#anchor">One</a>');
    droppedAnchorRecord('https://example.edu/a/', '<a href="#anchor">Again</a>');
    droppedAnchorRecord('https://example.edu/b/', '<a href="/a/#anchor">From b</a>');
    droppedAnchorRecord('https://example.edu/c/', '<a href="/a/#anchor">From c</a>');

    expect(array_column(droppedAnchorWarnings(), 'page_link'))->toBe([
        'https://example.edu/a/',
        'https://example.edu/b/',
        'https://example.edu/c/',
    ]);
});

it('reads ids and links past libxml\'s default nesting depth, as real pages nest unclosed tags hundreds deep', function (): void {
    droppedAnchorRecord('https://example.edu/a/', str_repeat('<a><strong>', 300).'<blockquote id="anchor"><p>Q</p></blockquote><p><a href="#anchor">x</a></p>');

    expect(array_column(droppedAnchorWarnings(), 'element'))->toBe(['<blockquote id="anchor">']);
});

it('raises no warning for an id kept inside a set\'s nested Bard content', function (): void {
    droppedAnchorRecord('https://example.edu/a/', '<div><p id="inner">In</p></div><p><a href="#inner">x</a></p>', [
        ['type' => 'set', 'attrs' => ['id' => 'row-1', 'values' => ['content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'inner']],
        ]]]],
    ]);

    expect(droppedAnchorWarnings())->toBe([]);
});

it('keeps one record of a link an outer call and a nested call both carry', function (): void {
    droppedAnchorRecord('https://example.edu/a/', '<blockquote id="anchor"><p>Q</p></blockquote><a href="#anchor">One</a><a href="#anchor">Two</a>');
    droppedAnchorRecord('https://example.edu/a/', '<a href="#anchor">Two</a>');

    $links = (new ReflectionProperty(DroppedAnchorTracker::class, 'links'))->getValue();

    expect($links)->toHaveCount(1)
        ->and(droppedAnchorWarnings())->toHaveCount(1);
});

it('decodes an escaped fragment before matching it', function (): void {
    droppedAnchorRecord('https://example.edu/a/', '<blockquote id="a b"><p>Q</p></blockquote><a href="#a%20b">x</a>');

    expect(array_column(droppedAnchorWarnings(), 'element'))->toBe(['<blockquote id="a b">']);
});

it('records nothing while no page is being imported', function (): void {
    ProcessingContext::clearWordPressPageLink();
    DroppedAnchorTracker::record('<blockquote id="anchor"><p>Q</p></blockquote><a href="#anchor">x</a>', []);

    expect(droppedAnchorWarnings())->toBe([]);
});

it('forgets what it recorded when cleared', function (): void {
    droppedAnchorRecord('https://example.edu/a/', '<blockquote id="anchor"><p>Q</p></blockquote><a href="#anchor">x</a>');

    DroppedAnchorTracker::clear();

    expect(droppedAnchorWarnings())->toBe([]);
});

it('reads an id or a fragment link however its attribute is quoted, and skips content with only an entity', function (string $html, int $warnings): void {
    droppedAnchorRecord('https://example.edu/a/', $html);

    expect(droppedAnchorWarnings())->toHaveCount($warnings);
})->with([
    'double quotes' => ['<blockquote id="anchor"><p>Q</p></blockquote><a href="#anchor">x</a>', 1],
    'single quotes' => ["<blockquote id='anchor'><p>Q</p></blockquote><a href='#anchor'>x</a>", 1],
    'unquoted' => ['<blockquote id=anchor><p>Q</p></blockquote><a href=#anchor>x</a>', 1],
    'an entity only' => ['<p>It&#8217;s here</p>', 0],
]);
