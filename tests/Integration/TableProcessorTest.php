<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\TableProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for TableProcessor, which turns a core `wp:table` block into a Bard `table` set.
 *
 * `canProcess()` accepts a `wp:table` comment, with or without a JSON object.
 * `process()` reads the first `wp-block-table` figure after the comment: header,
 * body and footer rows become `table_data`, and the table's classes, the figure's
 * classes and its caption become the set's flags, alignment and caption. A missing
 * figure, a figure with no table and a table with no usable rows each log a warning
 * and return null.
 *
 * Equivalent mutations preserve the centered/unstriped figure fallback, private
 * string/boolean values, unused empty-grid width, sequential group keys, full-row
 * output/state, same-row zero/one hold expiry, nonnumeric tag membership, and
 * native cell ownership. The full-row equivalent may perform extra iterations.
 * Synthetic document fixtures cover failed serialization and invalid UTF-8;
 * they do not characterize ordinary WXR data.
 *
 * Linux/PHP 8.4.27/PCOV: 316 default-set candidates, 314 tested, and two uncovered
 * MAX_COLUMNS declaration mutations. Direct controls kill both constant changes
 * and all eight boundary candidates; the unchanged child passes 127 tests and
 * 174 assertions. With the nine bounded equivalents explained, every killable
 * mutant is dead.
 *
 * @command  php vendor/bin/pest tests/Integration/TableProcessorTest.php
 */
// cspell:ignore alignwide alignfull aligncenter
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Parse an HTML fragment and return its first top-level node.
 *
 * libxml does not know `figure` or `figcaption`, so its errors are collected
 * rather than raised.
 *
 * @param  string  $html  The fragment to parse
 * @return DOMNode The fragment's first node
 */
function tableOpeningNode(string $html): DOMNode
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $first = $document->getElementById('root')?->firstChild;

    if (! $first instanceof DOMNode) {
        throw new RuntimeException('Fixture has no nodes');
    }

    return $first;
}

/**
 * Run the processor over a node.
 *
 * @param  DOMNode  $node  The opening comment
 * @param  array<int|string, mixed>  $attributes  The block's attributes
 * @param  ?string  $pageSlug  The page slug
 * @return array<int|string, mixed>|string|null The processor's result
 */
function processTable(DOMNode $node, array $attributes = [], ?string $pageSlug = null): array|string|null
{
    return (new TableProcessor)->process(
        $node,
        $attributes,
        'default',
        new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor(),
        new BufferedOutput,
        [],
        [],
        $pageSlug
    );
}

/**
 * Collect every Log::warning() call as a [message, context] pair.
 *
 * @return ArrayObject<int, array{0: string, 1: array<string, mixed>}> The calls, filled as they happen
 */
function tableWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        /** @var array<string, mixed> $context */
        $warnings->append([$message, $context]);
    });

    return $warnings;
}

it('accepts only a wp:table comment', function (string $html, bool $expected): void {
    expect((new TableProcessor)->canProcess(tableOpeningNode($html), 'default', null))->toBe($expected);
})->with([
    'bare block' => ['<!-- wp:table -->', true],
    'block with attributes' => ['<!-- wp:table {"hasFixedLayout":true} -->', true],
    'attributes over several lines' => ["<!-- wp:table {\"className\":\n\"is-style-stripes\"} -->", true],
    'empty object' => ['<!-- wp:table {} -->', false],
    'closing comment' => ['<!-- /wp:table -->', false],
    'another block with the same prefix' => ['<!-- wp:table-of-contents -->', false],
    'TablePress block' => ['<!-- wp:tablepress/table {"id":"1"} -->', false],
    'element, not comment' => ['<p>wp:table</p>', false],
]);

it('rejects a synthetic comment with a null node value', function (): void {
    // Exercise the nullable DOM contract, not a value observed in WXR exports.
    $node = new class extends DOMComment
    {
        /**
         * @var ?string Synthetic value unavailable from an ordinary comment.
         */
        public ?string $nodeValue = null;
    };

    // Verify the comment reaches the value guard rather than the node-type guard.
    expect($node->nodeType)->toBe(XML_COMMENT_NODE)
        ->and($node->nodeValue)->toBeNull()
        ->and((new TableProcessor)->canProcess($node, 'default', null))->toBeFalse();
});

it('maps every section, flag and the caption into a table set', function (): void {
    $node = tableOpeningNode(
        '<!-- wp:table {"align":"wide"} -->'
        .'<figure class="wp-block-other"><table><tbody><tr><td>wrong figure</td></tr></tbody></table></figure>'
        .'<figure class="wp-block-table is-style-stripes">'
        .'<table class="has-fixed-layout">'
        .'<thead><tr><th>Name</th><th>Role</th></tr></thead>'
        ."<tbody><tr><td> <strong>Ann</strong> O'Hara </td><td>Chair</td></tr><tr><td>Bo</td><td>Member</td></tr></tbody>"
        .'<tfoot><tr><td>Total</td><td>2</td></tr></tfoot>'
        .'</table>'
        .'<table><tbody><tr><td>second table</td></tr></tbody></table>'
        .'<figcaption>  The committee  </figcaption>'
        .'</figure>'
    );

    $result = processTable($node, ['align' => 'wide']);

    expect($result)->toBeArray()
        ->and($result['html'] ?? null)->toBe(
            '<div data-set-type="table"'.SetMarkupNonce::attribute()
            .' data-set-attr-type="table" data-set-attr-align="alignwide" data-set-attr-striped="true"'
            .' data-set-attr-first_row_headers="true" data-set-attr-first_column_headers="false"'
            .' data-set-attr-last_row_footers="true" data-set-attr-fixed_width_table_cells="true"'
            ." data-set-attr-table_data='[{&quot;cells&quot;:[&quot;Name&quot;,&quot;Role&quot;]},"
            .'{&quot;cells&quot;:[&quot;&lt;strong&gt;Ann&lt;\/strong&gt; O&#039;Hara&quot;,&quot;Chair&quot;]},'
            .'{&quot;cells&quot;:[&quot;Bo&quot;,&quot;Member&quot;]},'
            ."{&quot;cells&quot;:[&quot;Total&quot;,&quot;2&quot;]}]'"
            .' data-set-attr-caption="The committee"'
            .' data-set-boolean-fields="first_row_headers,first_column_headers,last_row_footers,striped,fixed_width_table_cells"></div>'
        )
        ->and($result['skipCount'] ?? null)->toBe(3)
        ->and($result['processedNode'] ?? null)->toBeInstanceOf(DOMElement::class)
        ->and(($result['processedNode'] ?? null) instanceof DOMElement ? $result['processedNode']->getAttribute('class') : null)->toBe('wp-block-table is-style-stripes');
});

it('reads alignment from the figure class and leaves every flag off for a plain body', function (): void {
    $result = processTable(tableOpeningNode(
        '<!-- wp:table -->'
        .'<figure class="wp-block-table alignfull"><table class="has-fixed-layouts"><tbody><tr><td>A</td></tr></tbody></table>'
        .'<figcaption>   </figcaption></figure>'
    ));

    expect($result['html'] ?? null)->toBe(
        '<div data-set-type="table"'.SetMarkupNonce::attribute()
        .' data-set-attr-type="table" data-set-attr-align="alignwide" data-set-attr-striped="false"'
        .' data-set-attr-first_row_headers="false" data-set-attr-first_column_headers="false"'
        .' data-set-attr-last_row_footers="false" data-set-attr-fixed_width_table_cells="false"'
        ." data-set-attr-table_data='[{&quot;cells&quot;:[&quot;A&quot;]}]'"
        .' data-set-boolean-fields="first_row_headers,first_column_headers,last_row_footers,striped,fixed_width_table_cells"></div>'
    );
});

it('centers a table whose align attribute is not a string', function (): void {
    $result = processTable(
        tableOpeningNode('<!-- wp:table --><figure class="wp-block-table alignwide"><table><tbody><tr><td>A</td></tr></tbody></table></figure>'),
        ['align' => ['wide']]
    );

    expect($result['html'] ?? null)->toContain(' data-set-attr-align="aligncenter" ');
});

it('centers a left-aligned table and escapes its caption', function (): void {
    $result = processTable(
        tableOpeningNode(
            '<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>A</td></tr></tbody></table>'
            .'<figcaption>"A" &amp; B</figcaption></figure>'
        ),
        ['align' => 'left']
    );

    expect($result['html'] ?? null)->toContain(' data-set-attr-align="aligncenter" ')
        ->toContain(' data-set-attr-caption="&quot;A&quot; &amp; B" ');
});

it('keeps every row that has cells, of either kind, and drops the ones that do not', function (): void {
    $result = processTable(tableOpeningNode(
        '<!-- wp:table --><figure class="wp-block-table"><table>'
        .'<thead><tr><td>not a header cell</td></tr><tr><th>H</th></tr></thead>'
        .'<tbody><tr></tr><tr><th>not a body cell</th></tr><tr><td>B</td></tr></tbody>'
        .'<tfoot><tr><th>not a footer cell</th></tr><tr><td>F</td></tr></tfoot>'
        .'</table></figure>'
    ));

    expect($result['html'] ?? null)->toContain(
        " data-set-attr-table_data='[{&quot;cells&quot;:[&quot;not a header cell&quot;]},{&quot;cells&quot;:[&quot;H&quot;]},{&quot;cells&quot;:[&quot;not a body cell&quot;]},{&quot;cells&quot;:[&quot;B&quot;]},{&quot;cells&quot;:[&quot;not a footer cell&quot;]},{&quot;cells&quot;:[&quot;F&quot;]}]'"
    )->not->toContain('data-set-attr-caption');
});

it('warns and returns null when no rows have cells', function (): void {
    $warnings = tableWarnings();
    $node = tableOpeningNode(
        '<!-- wp:table --><figure class="wp-block-table">'
        .'<table class="a table with rows but no cells, long enough to pass the snippet limit"><thead><tr></tr></thead>'
        .'<tbody><tr></tr></tbody><tfoot><tr></tr></tfoot></table></figure>'
    );
    $figure = $node->nextSibling;
    $snippet = $figure instanceof DOMNode ? (string) $node->ownerDocument?->saveHTML($figure) : '';

    expect(processTable($node, [], 'about'))->toBeNull()
        ->and(strlen($snippet))->toBeGreaterThan(100)
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No valid table rows parsed in wp:table block',
            ['site' => 'default', 'pageSlug' => 'about', 'node_html' => substr($snippet, 0, 100).'...'],
        ]]);
});

/**
 * Render the first table in the HTML as a set, returning what the set's markup says.
 *
 * @return array{rows: mixed, html: string}
 */
function renderedTable(string $html): array
{
    $document = TextProcessor::loadHtml($html);
    $table = $document->getElementsByTagName('table')->item(0);

    if (! $table instanceof DOMElement) {
        throw new RuntimeException('No table in the HTML.');
    }

    $set = (new TableProcessor)->renderSet($table) ?? '';
    $rendered = TextProcessor::loadHtml($set)->getElementsByTagName('div')->item(1);
    $data = $rendered instanceof DOMElement ? json_decode($rendered->getAttribute('data-set-attr-table_data'), true) : [];

    return [
        'rows' => data_get($data, '*.cells'),
        'html' => $set,
    ];
}

it('reads a plain table whatever its sections', function (string $html, array $rows): void {
    expect(renderedTable($html)['rows'])->toBe($rows);
})->with([
    'rows with no section' => ['<table><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></table>', [['1', '2'], ['3', '4']]],
    'every body' => ['<table><tbody><tr><td>1</td></tr></tbody><tbody><tr><td>2</td></tr></tbody></table>', [['1'], ['2']]],
    'a foot written ahead of the body' => ['<table><tfoot><tr><td>F</td></tr></tfoot><tbody><tr><td>B</td></tr></tbody><thead><tr><th>H</th></tr></thead></table>', [['H'], ['B'], ['F']]],
    'a br in a cell' => ['<table><tbody><tr><th>Weight<br>(kg)</th><th>Dose</th></tr></tbody></table>', [['Weight<br>(kg)', 'Dose']]],
    'a nested table, left in its cell' => ['<table><tbody><tr><td><table><tbody><tr><td>in</td></tr></tbody></table></td><td>out</td></tr></tbody></table>', [['<table><tbody><tr><td>in</td></tr></tbody></table>', 'out']]],
    'rows of different widths' => ['<table><tbody><tr><td>1</td><td>2</td></tr><tr><td>3</td></tr></tbody></table>', [['1', '2'], ['3', '']]],
    'a colspan' => ['<table><tbody><tr><td colspan="2">1</td><td>2</td></tr><tr><td>3</td><td>4</td><td>5</td></tr></tbody></table>', [['1', '', '2'], ['3', '4', '5']]],
    'a rowspan' => ['<table><tbody><tr><td rowspan="2">1</td><td>2</td></tr><tr><td>3</td></tr><tr><td>4</td><td>5</td></tr></tbody></table>', [['1', '2'], ['', '3'], ['4', '5']]],
    'a finite rowspan across three rows' => ['<table><tbody><tr><td rowspan="3">A</td><td>B</td></tr><tr><td>C</td></tr><tr><td>D</td></tr><tr><td>E</td><td>F</td></tr></tbody></table>', [['A', 'B'], ['', 'C'], ['', 'D'], ['E', 'F']]],
    'a rowspan in the last column' => ['<table><tbody><tr><td>1</td><td rowspan="2">2</td></tr><tr><td>3</td></tr></tbody></table>', [['1', '2'], ['3', '']]],
    'a span of both kinds' => ['<table><tbody><tr><td rowspan="2" colspan="2">1</td><td>2</td></tr><tr><td>3</td></tr></tbody></table>', [['1', '', '2'], ['', '', '3']]],
    'a rowspan past the last row' => ['<table><tbody><tr><td rowspan="9">1</td><td>2</td></tr></tbody></table>', [['1', '2']]],
    'an empty row a rowspan covers' => ['<table><tbody><tr><td rowspan="2">A</td><td>B</td></tr><tr></tr><tr><td>C</td><td>D</td></tr></tbody></table>', [['A', 'B'], ['C', 'D']]],
    'a rowspan that ends with its group' => ['<table><tbody><tr><td rowspan="5">a</td><td>b</td></tr></tbody><tbody><tr><td>c</td><td>d</td></tr></tbody></table>', [['a', 'b'], ['c', 'd']]],
    'a rowspan that does not reach the foot' => ['<table><tbody><tr><td rowspan="5">a</td><td>b</td></tr></tbody><tfoot><tr><td>f</td><td>g</td></tr></tfoot></table>', [['a', 'b'], ['f', 'g']]],
    'a rowspan of zero, to the end of its group' => ['<table><tbody><tr><td rowspan="0">a</td><td>b</td></tr><tr><td>c</td></tr><tr><td>d</td></tr></tbody><tbody><tr><td>e</td><td>f</td></tr></tbody></table>', [['a', 'b'], ['', 'c'], ['', 'd'], ['e', 'f']]],
    'rows with no section around a body' => ['<table><tr><td rowspan="2">a</td><td>b</td></tr><tr><td>c</td></tr><tbody><tr><td>d</td><td>e</td></tr></tbody><tr><td>f</td><td>g</td></tr></table>', [['a', 'b'], ['', 'c'], ['d', 'e'], ['f', 'g']]],
    'a span that is not a number' => ['<table><tbody><tr><td colspan="x" rowspan="0">1</td><td>2</td></tr></tbody></table>', [['1', '2']]],
]);

it('ignores direct non-element children without PHP diagnostics', function (): void {
    // Preserve comment and text children through the native XML parser.
    $document = new DOMDocument;
    $document->loadXML('<table><!-- before row -->text<tr><td>A</td><td>B</td></tr></table>');

    $table = $document->documentElement;
    if (! $table instanceof DOMElement) {
        throw new RuntimeException('Fixture has no table.');
    }

    // Make an attempted tagName read on either non-element fail observably.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $set = (new TableProcessor)->renderSet($table);
    } finally {
        restore_error_handler();
    }

    // Keep the valid row unchanged despite the preceding siblings.
    expect($set)->toContain(" data-set-attr-table_data='[{&quot;cells&quot;:[&quot;A&quot;,&quot;B&quot;]}]'");
});

it('keeps a later cell child when a synthetic serializer fails for the first', function (): void {
    // Simulate saveHTML failure without claiming ordinary exports produce it.
    $document = new class extends DOMDocument
    {
        /**
         * Fail only the first child element and serialize the rest normally.
         *
         * @param  ?DOMNode  $node  The node to serialize
         * @return string|false The native HTML, or the synthetic failure
         */
        public function saveHTML(?DOMNode $node = null): string|false
        {
            return $node?->nodeName === 'strong' ? false : parent::saveHTML($node);
        }
    };
    $document->loadXML('<table><tr><td><strong>lost</strong><em>kept</em></td></tr></table>');

    $table = $document->documentElement;
    if (! $table instanceof DOMElement) {
        throw new RuntimeException('Fixture has no table.');
    }

    // Assert the public set retains the second child's complete markup.
    expect((new TableProcessor)->renderSet($table))->toContain(
        " data-set-attr-table_data='[{&quot;cells&quot;:[&quot;&lt;em&gt;kept&lt;\/em&gt;&quot;]}]'"
    );
});

it('encodes synthetic cell serialization safely with a valid control', function (string $serialized, string $expected): void {
    // Inject serializer bytes rather than asking libxml to parse invalid UTF-8.
    $document = new class extends DOMDocument
    {
        /**
         * @var string Synthetic serializer result for a cell child.
         */
        public string $serialized = '';

        /**
         * Return caller-controlled bytes at the document serialization boundary.
         *
         * @param  ?DOMNode  $node  The node to serialize
         * @return string The synthetic serialized child
         */
        public function saveHTML(?DOMNode $node = null): string
        {
            return $this->serialized;
        }
    };
    $document->serialized = $serialized;
    $document->loadXML('<table><tr><td><span>A</span></td></tr></table>');

    $table = $document->documentElement;
    if (! $table instanceof DOMElement) {
        throw new RuntimeException('Fixture has no table.');
    }

    // Assert the exact public attribute, including the fallback on encoding failure.
    expect((new TableProcessor)->renderSet($table))->toContain(" data-set-attr-table_data='{$expected}'");
})->with([
    'invalid UTF-8' => ["\xB1", '[]'],
    'valid serializer control' => ['A', '[{&quot;cells&quot;:[&quot;A&quot;]}]'],
]);

it('holds a colspan to the most HTML allows', function (): void {
    expect(data_get(renderedTable('<table><tbody><tr><td colspan="99999">1</td></tr></tbody></table>'), 'rows.0'))->toHaveCount(1000);
});

it('holds a table to the most columns however its spans add up', function (): void {
    $cells = str_repeat('<td colspan="1000">x</td>', 3);

    expect(data_get(renderedTable("<table><tbody><tr>{$cells}</tr></tbody></table>"), 'rows.0'))->toHaveCount(1000)
        ->and(data_get(renderedTable('<table><tbody><tr><td colspan="999">x</td><td colspan="5">y</td></tr></tbody></table>'), 'rows.0'))->toHaveCount(1000)
        ->and(data_get(renderedTable('<table><tbody><tr><td rowspan="2" colspan="999">x</td><td>y</td></tr><tr><td>z</td><td>over</td></tr></tbody></table>'), 'rows.1'))->toHaveCount(1000);
});

it('marks a first row of header cells as a header row, with or without a thead', function (string $html, string $expected): void {
    expect(renderedTable($html)['html'])->toContain(' data-set-attr-first_row_headers="'.$expected.'"');
})->with([
    'header cells, no thead' => ['<table><tbody><tr><th>H</th><th>I</th></tr><tr><td>1</td><td>2</td></tr></tbody></table>', 'true'],
    'a thead' => ['<table><thead><tr><td>H</td></tr></thead><tbody><tr><td>1</td></tr></tbody></table>', 'true'],
    'mixed cells' => ['<table><tbody><tr><th>H</th><td>I</td></tr></tbody></table>', 'false'],
    'an empty thead row' => ['<table><thead><tr></tr></thead><tbody><tr><td>1</td></tr></tbody></table>', 'false'],
    'header cells after an empty row' => ['<table><tbody><tr></tr><tr><th>H</th></tr></tbody></table>', 'true'],
    'a later row of header cells' => ['<table><tbody><tr><td>1</td></tr><tr><th>H</th></tr></tbody></table>', 'false'],
    'data cells' => ['<table><tbody><tr><td>1</td></tr></tbody></table>', 'false'],
]);

it('reads a plain table caption and footer', function (): void {
    expect(renderedTable('<table><caption> Doses </caption><tbody><tr><td>1</td></tr></tbody><tfoot><tr><td>F</td></tr></tfoot></table>')['html'])
        ->toContain(' data-set-attr-caption="Doses"')
        ->toContain(' data-set-attr-last_row_footers="true"');
});

it('marks no footer for a tfoot with no cells', function (): void {
    expect(renderedTable('<table><tbody><tr><td>1</td></tr></tbody><tfoot><tr></tr></tfoot></table>')['html'])
        ->toContain(' data-set-attr-last_row_footers="false"');
});

it('tells a table of data from layout, spacing and a converted shortcode', function (string $html, bool $data): void {
    $table = TextProcessor::loadHtml($html)->getElementsByTagName('table')->item(0);

    expect($table instanceof DOMElement && (new TableProcessor)->isDataTable($table))->toBe($data);
})->with([
    'data' => ['<table><tbody><tr><td>1</td></tr></tbody></table>', true],
    'a nested table' => ['<table><tbody><tr><td>1<table><tbody><tr><td>2</td></tr></tbody></table></td></tr></tbody></table>', false],
    'a set in a cell' => ['<table><tbody><tr><td>1<div data-set-type="file"></div></td></tr></tbody></table>', false],
    'blank cells' => ['<table><tbody><tr><td>&nbsp; </td></tr></tbody></table>', false],
    'an image and no text' => ['<table><tbody><tr><td><img src="a.jpg" alt=""></td></tr></tbody></table>', false],
]);

it('renders nothing for a table with no cells', function (): void {
    $document = TextProcessor::loadHtml('<table><tfoot><tr></tr></tfoot></table>');
    $table = $document->getElementsByTagName('table')->item(0);

    expect($table instanceof DOMElement ? (new TableProcessor)->renderSet($table) : 'no table')->toBeNull();
});

it('warns and returns null when the figure holds no table', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/about/');
    $warnings = tableWarnings();

    expect(processTable(tableOpeningNode('<!-- wp:table --><figure class="wp-block-table"><p>no table</p></figure>')))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No table found in wp:table block (from entry: https://example.test/about/)',
            [
                'site' => 'default',
                'pageSlug' => 'none',
                'node_html' => '<figure class="wp-block-table"><p>no table</p></figure>...',
                'entry_url' => 'https://example.test/about/',
            ],
        ]]);
});

it('cuts the snippet of a figure with no table to 100 characters and names the page', function (): void {
    $warnings = tableWarnings();
    $node = tableOpeningNode(
        '<!-- wp:table --><figure class="wp-block-table"><p>no table here, only a paragraph long enough to pass the snippet limit</p></figure>'
    );
    $figure = $node->nextSibling;
    $snippet = $figure instanceof DOMNode ? (string) $node->ownerDocument?->saveHTML($figure) : '';

    expect(processTable($node, [], 'about'))->toBeNull()
        ->and(strlen($snippet))->toBeGreaterThan(100)
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No table found in wp:table block',
            ['site' => 'default', 'pageSlug' => 'about', 'node_html' => substr($snippet, 0, 100).'...'],
        ]]);
});

it('warns with an empty snippet when synthetic figure serialization fails', function (): void {
    // Keep the caller's document while simulating a serializer failure.
    $warnings = tableWarnings();
    $document = new class extends DOMDocument
    {
        /**
         * Fail serialization independently of the native XML tree.
         *
         * @param  ?DOMNode  $node  The node to serialize
         * @return false The synthetic serialization failure
         */
        public function saveHTML(?DOMNode $node = null): false
        {
            return false;
        }
    };
    $document->loadXML('<root><!-- wp:table --><figure class="wp-block-table"><p>no table</p></figure></root>');

    $node = $document->documentElement?->firstChild;
    if (! $node instanceof DOMComment) {
        throw new RuntimeException('Fixture has no opening comment.');
    }

    // Assert the public failure result and the exact warning context.
    expect(processTable($node, [], 'about'))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No table found in wp:table block',
            ['site' => 'default', 'pageSlug' => 'about', 'node_html' => '...'],
        ]]);
});

it('names the page when no wp-block-table figure follows the comment', function (): void {
    $warnings = tableWarnings();

    expect(processTable(tableOpeningNode('<!-- wp:table -->'), [], 'about'))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No valid wp:table figure found',
            ['site' => 'default', 'pageSlug' => 'about', 'attributes' => []],
        ]]);
});

it('warns and returns null when no wp-block-table figure follows the comment', function (string $html): void {
    $warnings = tableWarnings();

    expect(processTable(tableOpeningNode($html), ['align' => 'wide']))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No valid wp:table figure found',
            ['site' => 'default', 'pageSlug' => 'none', 'attributes' => ['align' => 'wide']],
        ]]);
})->with([
    'nothing after the comment' => ['<!-- wp:table -->'],
    'a figure of another block' => ['<!-- wp:table --><figure class="wp-block-image"><table><tbody><tr><td>A</td></tr></tbody></table></figure>'],
]);

it("takes no figure from past the block's closing comment or from the block after it", function (string $html): void {
    $warnings = tableWarnings();

    // #197: the figure lookup stops where the block ends
    expect(processTable(tableOpeningNode($html)))->toBeNull()
        ->and(array_column($warnings->getArrayCopy(), 0))->toBe(['TableProcessor::process: No valid wp:table figure found']);
})->with([
    'a figure after the closing comment' => ['<!-- wp:table --><!-- /wp:table --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure>'],
    'a figure in the block after an unclosed one' => ['<!-- wp:table --><!-- wp:group --><div><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure></div><!-- /wp:group --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure>'],
]);

it("skips the block's own nodes, counting neither whitespace nor what follows the closing comment", function (string $html, int $skipCount): void {
    tableWarnings();

    expect(processTable(tableOpeningNode($html))['skipCount'] ?? null)->toBe($skipCount);
})->with([
    'the figure alone' => ['<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><!-- /wp:table --><p>After</p>', 3],
    'the figure among whitespace' => ["<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>T</td></tr></tbody></table></figure>\n<!-- /wp:table -->\n<p>After</p>", 3],
    'content ahead of the figure' => ['<!-- wp:table --><p>A</p><p>B</p><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><!-- /wp:table --><p>After</p>', 5],
    'content after the figure' => ['<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><p>A</p><!-- /wp:table --><p>After</p>', 4],
    'a block that is never closed, before another block' => ['<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><!-- wp:paragraph --><p>Next</p><!-- /wp:paragraph -->', 2],
]);

it('warns of the content a table block holds besides its figure', function (): void {
    $warnings = tableWarnings();

    processTable(tableOpeningNode('<!-- wp:table --><p>A</p>text<!-- a note --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><figure class="wp-block-table"><table><tbody><tr><td>U</td></tr></tbody></table></figure><!-- /wp:table -->'), [], 'about');

    // The note is a comment, which holds no content, so it is not named
    expect($warnings->getArrayCopy())->toBe([[
        'TableProcessor::process: Dropped content in a wp:table block outside its figure',
        ['site' => 'default', 'pageSlug' => 'about', 'dropped_html' => substr('<p>A</p>text<figure class="wp-block-table"><table><tbody><tr><td>U</td></tr></tbody></table></figure>', 0, 100)],
    ]]);
});

it('warns of nothing for a table block that holds only its figure', function (): void {
    $warnings = tableWarnings();

    processTable(tableOpeningNode("<!-- wp:table -->\n<!-- a note --><figure class=\"wp-block-table\"><table><tbody><tr><td>T</td></tr></tbody></table></figure>\n<!-- /wp:table --><p>After</p>"));

    expect($warnings->getArrayCopy())->toBe([]);
});

it('returns null for a comment that belongs to no document', function (): void {
    $warnings = tableWarnings();

    expect(processTable(new DOMComment(' wp:table ')))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([]);
});

it('warns once, naming the page, of the images a wp:table block holds', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/news/photo-story/');
    $node = tableOpeningNode(
        '<!-- wp:table -->'
        .'<figure class="wp-block-table"><table><tbody>'
        .'<tr><td><img src="https://example.test/a.jpg" alt="A">Caption A</td></tr>'
        .'<tr><td><img src="https://example.test/b.jpg" alt="B">Caption B</td></tr>'
        .'</tbody></table></figure>'
    );

    expect(processTable($node))->toBeArray();
    expect(processTable($node))->toBeArray();

    expect(ImportWarningManager::getWarnings('content', 'images_in_table_cells'))->toBe([[
        'page_link' => 'https://example.test/news/photo-story/',
        'image_count' => 2,
        'content_snippet' => '<img src="https://example.test/a.jpg" alt="A">',
        'reason' => 'An image in a table cell is kept as HTML in the table set, is not imported as an asset, and does not render.',
    ]]);
});

it('warns of the image a plain table of data holds, as content reaches it', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/about/');

    // The cell of text without an image makes this a table of data, not of photos (#489)
    TextProcessor::processForBard(
        '<table align="right"><tbody><tr><td><img class="img_style_1" src="https://example.test/c.jpg" alt=""> A cutline</td><td>Dr. Smith</td></tr></tbody></table>',
        'default',
        app(AssetImporter::class),
        new BufferedOutput
    );

    expect(ImportWarningManager::getWarnings('content', 'images_in_table_cells'))->toBe([[
        'page_link' => 'https://example.test/about/',
        'image_count' => 1,
        'content_snippet' => '<img class="img_style_1" src="https://example.test/c.jpg" alt="">',
        'reason' => 'An image in a table cell is kept as HTML in the table set, is not imported as an asset, and does not render.',
    ]]);
});

it('cuts the image snippet to 100 characters and reports a page with no link as empty', function (): void {
    $src = 'https://example.test/'.str_repeat('a', 120).'.jpg';
    renderedTable('<table><tbody><tr><td><img src="'.$src.'"> text</td></tr></tbody></table>');

    $warnings = ImportWarningManager::getWarnings('content', 'images_in_table_cells');

    expect($warnings)->toHaveCount(1)
        ->and(data_get($warnings, '0.page_link'))->toBe('')
        ->and(data_get($warnings, '0.content_snippet'))->toBe(mb_substr('<img src="'.$src.'">', 0, 100).'...');
});

it('warns of no image for a table without one, or one with no rows to import', function (string $html): void {
    renderedTable($html);

    expect(ImportWarningManager::getWarnings('content', 'images_in_table_cells'))->toBe([]);
})->with([
    'text only' => ['<table><tbody><tr><td>1</td></tr></tbody></table>'],
    'no rows' => ['<table><caption><img src="a.jpg"></caption><tfoot><tr></tr></tfoot></table>'],
]);

/**
 * The photos TableProcessor::photoFigures() finds in a table, or null.
 *
 * @param  string  $html  A fragment whose first node is the table
 * @return ?list<array{figure: string, caption: string}> The photos, or null for a table that is not one of photos
 */
function tablePhotos(string $html): ?array
{
    $table = tableOpeningNode($html);

    if (! $table instanceof DOMElement) {
        throw new RuntimeException('Fixture is not an element');
    }

    return (new TableProcessor)->photoFigures($table);
}

/**
 * The figures TableProcessor::photoFigures() makes of a table, or null.
 *
 * @param  string  $html  A fragment whose first node is the table
 * @return ?list<string> The figures, or null for a table that is not one of photos
 */
function photoFigures(string $html): ?array
{
    $photos = tablePhotos($html);

    return $photos === null ? null : array_column($photos, 'figure');
}

it('makes a figure of each photo in a table of photos, with the text after it as its caption', function (): void {
    expect(photoFigures(
        '<table align="right"><tbody>'
        .'<tr><td><p style="text-align: right;"> <img class="img_style_1" src="a.jpg" alt="A"><br>'
        ."\n".'<span class="cutline">First   caption,&nbsp;here.</span></p></td></tr>'
        .'<tr><td><img src="b.jpg"></td><th></th></tr>'
        .'<tr><th>Lead text <img src="c.jpg"> after c <img src="d.jpg"> after d</th></tr>'
        .'</tbody></table>'
    ))->toBe([
        '<figure><img class="img_style_1" src="a.jpg" alt="A"><figcaption>First caption, here.</figcaption></figure>',
        '<figure><img src="b.jpg"></figure>',
        '<figure><img src="c.jpg"><figcaption>Lead text after c</figcaption></figure>',
        '<figure><img src="d.jpg"><figcaption>after d</figcaption></figure>',
    ]);
});

it('gives each photo its caption as text beside its figure', function (): void {
    expect(tablePhotos('<table><tr><td><img src="a.jpg">  Dr.&nbsp;Smith  </td><td><img src="b.jpg"></td></tr></table>'))->toBe([
        ['figure' => '<figure><img src="a.jpg"><figcaption>Dr. Smith</figcaption></figure>', 'caption' => 'Dr. Smith'],
        ['figure' => '<figure><img src="b.jpg"></figure>', 'caption' => ''],
    ]);
});

it('takes a photo whose link holds only the image', function (): void {
    expect(photoFigures('<table><tr><td><a href="big.jpg"><img src="a.jpg"></a> Cap</td></tr></table>'))
        ->toBe(['<figure><img src="a.jpg"><figcaption>Cap</figcaption></figure>']);
});

it('escapes a caption as text in its figure', function (): void {
    expect(photoFigures('<table><tr><td><img src="a.jpg">Smith &amp; Jones &lt;b&gt;</td></tr></table>'))
        ->toBe(['<figure><img src="a.jpg"><figcaption>Smith &amp; Jones &lt;b&gt;</figcaption></figure>']);
});

it('takes a caption of 500 characters, and reads a longer one as an article', function (): void {
    $caption = str_repeat('a', 500);

    expect(photoFigures('<table><tr><td><img src="a.jpg"> '.$caption.' </td></tr></table>'))
        ->toBe(['<figure><img src="a.jpg"><figcaption>'.$caption.'</figcaption></figure>'])
        ->and(photoFigures('<table><tr><td><img src="a.jpg">'.$caption.'b</td></tr></table>'))->toBeNull()
        ->and(photoFigures('<table><tr><td><img src="a.jpg">a</td></tr><tr><td><img src="b.jpg">'.$caption.'b</td></tr></table>'))->toBeNull();
});

it('reads no table as one of photos unless it is a table of data whose every cell with text holds an image', function (string $html): void {
    expect(photoFigures($html))->toBeNull();
})->with([
    'no image' => ['<table><tr><td>Name</td></tr></table>'],
    'a cell of text without an image' => ['<table><tr><td><img src="a.jpg"> A</td><td>Dr. Smith</td></tr></table>'],
    'a cell of text without an image, after the photo' => ['<table><tr><td><img src="a.jpg"> A</td></tr><tr><td>Notes</td></tr></table>'],
    'no text at all' => ['<table><tr><td><img src="a.jpg"></td></tr></table>'],
    'a nested table' => ['<table><tr><td><img src="a.jpg"> A<table><tr><td>B</td></tr></table></td></tr></table>'],
    'a converted shortcode' => ['<table><tr><td><img src="a.jpg"> A<div data-set-type="video"></div></td></tr></table>'],
    'a link with text' => ['<table><tr><td><img src="a.jpg"> A <a href="/story">Full Story</a></td></tr></table>'],
    'a link with text around the image' => ['<table><tr><td><a href="/mail"><img src="m.jpg"> E-mail This Article</a></td></tr></table>'],
    'a caption element' => ['<table><caption>Gala</caption><tr><td><img src="a.jpg"> A</td></tr></table>'],
    'a caption element, and cells of images only' => ['<table><caption>Gala</caption><tr><td><img src="a.jpg"></td></tr></table>'],
]);
