<?php

declare(strict_types=1);

/**
 * Tests that a link in a table cell reaches its entry as a
 * `statamic://entry::{id}` reference, by both of the paths that write one
 * (aether-wordpress-importer#395).
 *
 * A table becomes a `table` set whose `table_data` keeps each cell's HTML as a
 * string. A link that resolves while the page is imported is rewritten by
 * {@see LinkProcessor::process()} before {@see TableProcessor::renderSet()}
 * reads the cells, so the cell keeps the reference. A link to an entry not yet
 * imported is deferred, and the deferred pass replaces the WordPress URL inside
 * the cell string, where an `&` in the query is stored escaped as `&amp;`.
 *
 * Rendering the reference is the host app's: its table cell sanitizer allows
 * only `http`, `https`, `mailto` and `tel`, so it must resolve the reference to
 * the entry's URL before sanitizing, or the cell renders as text with no link.
 *
 * Standalone (no host Statamic app required): the immediate path resolves a kb
 * link through the seedable {@see KbLinkRegistry}, as
 * {@see EntryLinkQueryFragmentPipelineTest} does.
 *
 * @command  php vendor/bin/pest tests/Unit/TableCellEntryLinkTest.php
 */

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\TableProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

// Start each case from an empty registry and empty conversion caches, and seed
// the kb slug the immediate path resolves.
beforeEach(function (): void {
    tableCellLinkResetState();

    KbLinkRegistry::registerKbPostMapping('cell-article', '123');
});

afterEach(function (): void {
    tableCellLinkResetState();
});

it('keeps an entry reference a cell link resolves to while the page is imported', function (string $href, string $expected): void {
    $html = '<table><tbody><tr>'
        .'<td><a href="'.$href.'">Article</a></td>'
        .'<td><a href="https://example.org/elsewhere/?a=1&amp;b=2#top">Elsewhere</a></td>'
        .'</tr></tbody></table>';

    // Rewrite the links as the import does, then render the table that results
    $processed = (new LinkProcessor)->process(
        $html,
        'default',
        tableCellLinkAssetImporter(),
        $this::createStub(OutputInterface::class),
    );

    expect(tableCellLinkCells($processed))->toBe([
        '<a href="'.$expected.'">Article</a>',
        '<a href="https://example.org/elsewhere/?a=1&amp;b=2#top">Elsewhere</a>',
    ]);
})->with([
    'no query, no fragment' => [
        'https://cells.uams.test/kb/cell-article/',
        'statamic://entry::123',
    ],
    'a query and a fragment' => [
        'https://cells.uams.test/kb/cell-article/?utm=foo#section',
        'statamic://entry::123?utm=foo#section',
    ],
    'a query with two parameters' => [
        'https://cells.uams.test/kb/cell-article/?a=1&amp;b=2#section',
        'statamic://entry::123?a=1&amp;b=2#section',
    ],
]);

it('points a deferred cell link at its entry, keeping the query and fragment', function (string $cellHref, string $deferredUrl, string $reference, string $expected): void {
    $set = tableCellLinkSet([
        ['cells' => ['<a href="'.$cellHref.'">Article</a>', 'Plain text']],
        ['cells' => ['<a href="https://example.org/elsewhere/">Elsewhere</a>', '<a href="https://cells.uams.test/other/">Other</a>']],
    ]);

    // The deferred pass searches for the URL as the DOM read it, unescaped
    $replaced = LinkProcessor::replaceUrlInBardContent([$set], $deferredUrl, $reference);

    expect($replaced)->toBe([tableCellLinkSet([
        ['cells' => ['<a href="'.$expected.'">Article</a>', 'Plain text']],
        ['cells' => ['<a href="https://example.org/elsewhere/">Elsewhere</a>', '<a href="https://cells.uams.test/other/">Other</a>']],
    ])]);
})->with([
    'no query, no fragment' => [
        'https://cells.uams.test/page/',
        'https://cells.uams.test/page/',
        'statamic://entry::abc',
        'statamic://entry::abc',
    ],
    'a query and a fragment' => [
        'https://cells.uams.test/page/?utm=foo#section',
        'https://cells.uams.test/page/?utm=foo#section',
        'statamic://entry::abc?utm=foo#section',
        'statamic://entry::abc?utm=foo#section',
    ],
    'a query with two parameters, escaped in the cell' => [
        'https://cells.uams.test/page/?a=1&amp;b=2#section',
        'https://cells.uams.test/page/?a=1&b=2#section',
        'statamic://entry::abc?a=1&b=2#section',
        'statamic://entry::abc?a=1&amp;b=2#section',
    ],
]);

it('replaces an escaped URL in a replicator string and in string content', function (): void {
    $cell = '<a href="https://cells.uams.test/page/?a=1&amp;b=2">Article</a>';
    $expected = '<a href="statamic://entry::abc?a=1&amp;b=2">Article</a>';

    $fields = LinkProcessor::processResolvedLinkInFields(
        $cell,
        [['id' => 's', 'type' => 'table', 'table_data' => [['cells' => [$cell]]]]],
        'https://cells.uams.test/page/?a=1&b=2',
        'statamic://entry::abc?a=1&b=2',
    );

    expect($fields['content'])->toBe($expected)
        ->and($fields['page_builder'])->toBe([['id' => 's', 'type' => 'table', 'table_data' => [['cells' => [$expected]]]]]);
});

it('writes the new URL unescaped where the old one needed no escaping', function (): void {
    // A plain URL field is not markup, so an `&` in the new URL stays as it is
    $replaced = LinkProcessor::replaceUrlInReplicatorContent(
        [['id' => 's', 'button_url' => 'https://cells.uams.test/file/']],
        'https://cells.uams.test/file/',
        'https://assets.uams.test/file.pdf?v=1&w=2',
    );

    expect($replaced)->toBe([['id' => 's', 'button_url' => 'https://assets.uams.test/file.pdf?v=1&w=2']]);
});

it('replaces a URL only where it ends, leaving a longer URL that starts with it', function (string $cell, string $expected): void {
    $replaced = LinkProcessor::replaceUrlInBardContent(
        [tableCellLinkSet([['cells' => [$cell]]])],
        'https://cells.uams.test/page/',
        'statamic://entry::abc',
    );

    expect($replaced)->toBe([tableCellLinkSet([['cells' => [$expected]]])]);
})->with([
    'a child page' => [
        '<a href="https://cells.uams.test/page/child/">Child</a>',
        '<a href="https://cells.uams.test/page/child/">Child</a>',
    ],
    'a page whose path continues the name' => [
        'https://cells.uams.test/page/2024-report and https://cells.uams.test/page/~x and https://cells.uams.test/page/%20',
        'https://cells.uams.test/page/2024-report and https://cells.uams.test/page/~x and https://cells.uams.test/page/%20',
    ],
    'the page itself beside its child' => [
        '<a href="https://cells.uams.test/page/">Page</a> <a href="https://cells.uams.test/page/child/">Child</a>',
        '<a href="statamic://entry::abc">Page</a> <a href="https://cells.uams.test/page/child/">Child</a>',
    ],
    'the page followed by a query, a fragment and the end of the string' => [
        'https://cells.uams.test/page/?x=1 https://cells.uams.test/page/#top https://cells.uams.test/page/',
        'statamic://entry::abc?x=1 statamic://entry::abc#top statamic://entry::abc',
    ],
]);

it('leaves a URL that a path character continues, and replaces one anything else ends', function (string $next, bool $replaced): void {
    $value = 'see https://cells.uams.test/page'.$next.'x';

    expect(LinkProcessor::replaceUrlInReplicatorContent([['id' => 's', 'text' => $value]], 'https://cells.uams.test/page', 'statamic://entry::abc'))
        ->toBe([['id' => 's', 'text' => $replaced ? 'see statamic://entry::abc'.$next.'x' : $value]]);
})->with(function (): array {
    $cases = [];

    // Each character that continues a path, at both ends of every range
    foreach (\str_split('azAZ09-._~%/') as $character) {
        $cases['continued by '.$character] = [$character, false];
    }

    // Each character that ends a URL in a query, a fragment, an attribute or prose
    foreach (\str_split('?#"\' <&,') as $character) {
        $cases['ended by '.$character] = [$character, true];
    }

    return $cases;
});

it('points a deferred cell link at its entry whatever libxml escapes or encodes in its href', function (string $href): void {
    // The cell as the import stores it, and the URL as the deferred pass holds it
    $cellHtml = '<table><tbody><tr><td><a href="'.\htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8').'">Article</a></td></tr></tbody></table>';
    $deferredUrl = tableCellLinkHref($cellHtml);
    $reference = 'statamic://entry::abc'.\strstr($deferredUrl, '?');

    $replaced = LinkProcessor::replaceUrlInBardContent(
        [tableCellLinkSet([['cells' => tableCellLinkCells($cellHtml)]])],
        $deferredUrl,
        $reference,
    );

    // Expect the cell libxml writes for a link to the reference
    $expectedHtml = '<table><tbody><tr><td><a href="'.\htmlspecialchars($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8').'">Article</a></td></tr></tbody></table>';

    expect($replaced)->toBe([tableCellLinkSet([['cells' => tableCellLinkCells($expectedHtml)]])])
        ->and(tableCellLinkCells($expectedHtml)[0])->toContain('href=', 'statamic://entry::abc?');
})->with([
    'an ampersand' => ['https://cells.uams.test/page/?a=1&b=2#s'],
    'an ampersand and angle brackets' => ['https://cells.uams.test/page/?a=1&b=<x>#s'],
    'an ampersand and double quotes' => ['https://cells.uams.test/page/?a=1&b="x"#s'],
    'an ampersand and single quotes' => ["https://cells.uams.test/page/?a=1&b='x'#s"],
    'a space' => ['https://cells.uams.test/page/?q=a b'],
    'a non-ASCII character in the path' => ["https://cells.uams.test/caf\u{e9}/?a=1&b=2"],
    'a non-ASCII character in the query' => ["https://cells.uams.test/page/?q=caf\u{e9}&b=2"],
]);

it('leaves a URL that a non-ASCII character continues', function (): void {
    expect(LinkProcessor::replaceUrlInReplicatorContent([['id' => 's', 'text' => "https://cells.uams.test/caf\u{e9}/"]], 'https://cells.uams.test/caf', 'statamic://entry::abc'))
        ->toBe([['id' => 's', 'text' => "https://cells.uams.test/caf\u{e9}/"]]);
});

it('replaces nothing for an empty URL', function (): void {
    expect(LinkProcessor::replaceUrlInReplicatorContent([['id' => 's', 'text' => 'ab']], '', 'statamic://entry::abc'))
        ->toBe([['id' => 's', 'text' => 'ab']]);
});

/**
 * Build a Bard `table` set node holding the given rows.
 *
 * @param  list<array{cells: list<string>}>  $rows  The set's `table_data`
 * @return array<string, mixed> The set node
 */
function tableCellLinkSet(array $rows): array
{
    return ['type' => 'set', 'attrs' => ['id' => 'table-1', 'values' => ['type' => 'table', 'table_data' => $rows]]];
}

/**
 * Render the first table in the HTML as a `table` set and return the cells of
 * its first row, decoded from the set's `table_data` attribute.
 *
 * @param  string  $html  The HTML holding the table
 * @return list<string> The first row's cell HTML
 */
function tableCellLinkCells(string $html): array
{
    // Load the HTML as the import does, so the cells hold what libxml writes
    $table = TextProcessor::loadHtml($html)->getElementsByTagName('table')->item(0);
    if (! $table instanceof DOMElement) {
        throw new RuntimeException('The HTML holds no table');
    }

    $markup = (new TableProcessor)->renderSet($table);
    if ($markup === null || \preg_match("/data-set-attr-table_data='([^']*)'/", $markup, $matches) !== 1) {
        throw new RuntimeException('The table rendered no table_data');
    }

    $rows = \json_decode(\html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
    if (! \is_array($rows) || ! \is_array($rows[0] ?? null) || ! \is_array($rows[0]['cells'] ?? null)) {
        throw new RuntimeException('The table_data has no first row');
    }

    return \array_values(\array_map(static fn (mixed $cell): string => \is_string($cell) ? $cell : '', $rows[0]['cells']));
}

/**
 * Return the href of the first link in the HTML, as the DOM reads it when the
 * import loads the page.
 *
 * @param  string  $html  The HTML holding the link
 * @return string The link's href
 */
function tableCellLinkHref(string $html): string
{
    $link = TextProcessor::loadHtml($html)->getElementsByTagName('a')->item(0);
    if (! $link instanceof DOMElement) {
        throw new RuntimeException('The HTML holds no link');
    }

    return $link->getAttribute('href');
}

/**
 * Build an AssetImporter with only its base blog URL set, bypassing the
 * constructor, which reads `config()` and so needs a booted app.
 */
function tableCellLinkAssetImporter(): AssetImporter
{
    $assetImporter = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();

    $property = new ReflectionProperty(AssetImporter::class, 'baseBlogUrl');
    $property->setValue($assetImporter, 'https://cells.uams.test');

    return $assetImporter;
}

/**
 * Clear the kb registry, the LinkProcessor conversion caches and any XML path
 * left in the processing context.
 */
function tableCellLinkResetState(): void
{
    KbLinkRegistry::clear();
    ProcessingContext::clearXmlPath();

    foreach (['processedLinksCache', 'unresolvedLinksCache'] as $name) {
        $property = new ReflectionProperty(LinkProcessor::class, $name);
        $property->setValue(null, []);
    }
}
