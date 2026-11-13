<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Tests for the ShortcodeBlockProcessor class.
 *
 * Tests processing of WordPress wp:shortcode blocks into Tiptap-compatible
 * HTML or page_builder sets for Statamic Bard and landing pages. Routed to the
 * light Statamic band because the SUT logs via the `Log` facade (e.g. the empty
 * and no-shortcodes-extracted paths) and builds page-builder blocks with
 * Statamic's `RowId`, both of which need a booted container.
 *
 * Equivalent mutants retain the duplicate native comment/document guards,
 * native node-value fallbacks, and trims of unchanged blockContent() results.
 * The diagnostic coalesces are grouped so each narrow annotation covers one
 * operation without hiding the entity-reference fallback. With these native-DOM
 * invariants and the pre-existing strict string-membership equivalent explained,
 * every killable mutant is dead in the recorded Linux mutation population.
 *
 * @command  php vendor/bin/pest tests/Integration/ShortcodeBlockProcessorTest.php
 */
/**
 * A shortcode processor that counts its toTiptapHtml() calls by shortcode name.
 */
class ShortcodeBlockCountingShortcodeProcessor extends ShortcodeProcessor
{
    /**
     * The number of toTiptapHtml() calls for each shortcode name.
     *
     * @var array<string, int>
     */
    public array $renders = [];

    /**
     * Count the call and render as the real processor does.
     *
     * @param  array<int|string, mixed>  $shortcode
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     */
    public function toTiptapHtml(
        array $shortcode,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string {
        $name = \is_string($shortcode['name'] ?? null) ? $shortcode['name'] : '';
        $this->renders[$name] = ($this->renders[$name] ?? 0) + 1;

        return parent::toTiptapHtml($shortcode, $site, $assetImporter, $output, $attachments, $terms, $pageSlug);
    }
}

beforeEach(function (): void {
    // Real ShortcodeBlockProcessor under test
    $this->processor = new ShortcodeBlockProcessor;

    // Mock AssetImporter since no real asset processing is exercised
    $this->assetImporter = $this::createStub(AssetImporter::class);

    // DOMDocument for building test HTML structures
    $this->doc = new DOMDocument;
});

afterEach(function (): void {
    // Clear TablePress static state to avoid leaking table IDs between tests
    TablePressShortcodeProcessor::clearTableIds();

    Mockery::close();
});

it('canProcess returns true for a wp:shortcode comment', function (): void {
    $comment = $this->doc->createComment('wp:shortcode');

    $result = $this->processor->canProcess($comment, 'test-site', 'test-page');

    expect($result)->toBeTrue();
});

it('canProcess returns true for a wp:shortcode comment with trailing whitespace', function (): void {
    $comment = $this->doc->createComment('wp:shortcode ');

    $result = $this->processor->canProcess($comment, 'test-site', null);

    expect($result)->toBeTrue();
});

it('canProcess returns false for a wp:paragraph comment', function (): void {
    $comment = $this->doc->createComment('wp:paragraph');

    $result = $this->processor->canProcess($comment, 'test-site', 'test-page');

    expect($result)->toBeFalse();
});

it('canProcess returns false for a closing wp:shortcode comment', function (): void {
    $comment = $this->doc->createComment('/wp:shortcode');

    $result = $this->processor->canProcess($comment, 'test-site', null);

    expect($result)->toBeFalse();
});

it('canProcess returns false for an element node', function (): void {
    $element = $this->doc->createElement('div');

    $result = $this->processor->canProcess($element, 'test-site', null);

    expect($result)->toBeFalse();
});

it('process returns Tiptap html and a page_builder_set for a show-people shortcode', function (): void {
    // Wrap in <p>: libxml HTML drops raw `[shortcode]` between comments.
    $html = '<!-- wp:shortcode --><p>[show-people orderby="sortpriority" category="advanced-endoscopy" layout="grid"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="people"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_show-people');
});

it('process returns an array with html and page_builder_set for a table shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[table id=7 /]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="table"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_table');
    expect($result['skipCount'])->toBeGreaterThanOrEqual(2);
});

it('process returns null for an empty wp:shortcode block', function (): void {
    $html = '<!-- wp:shortcode -->

<!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process(
        $comment,
        [],
        'default',
        $this->assetImporter,
        shortcodeBlockOutput(),
        [],
        [],
        null
    );

    expect($result)->toBeNull();
});

it('process extracts a shortcode from a block with newlines between comments', function (): void {
    $html = '<!-- wp:shortcode --><p>[table id=8 /]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'wardteams'
        )
    );

    expect($result['html'])->not->toBeEmpty();
    expect($result['html'])->toContain('data-set-type="table"');
});

it('process returns an array for a gravityform shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[gravityform id="1" title="false"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="form"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_gravityform');
});

it('process returns an array for a gallery shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[gallery ids="1,2,3"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="gallery"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_gallery');
});

it('process returns an array for a formidable shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[formidable id=2]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="form"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_formidable');
});

it('process returns the image set html, with no page builder set, for a caption shortcode', function (): void {
    // The host app defines no caption block, so a caption reaches page builder content
    // as the same image set the Bard route makes, inside a content block (#119)
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1" align="alignright" width="300"]<img src="https://example.com/img.jpg" />My caption[/caption]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireHtmlOnlyResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result)->toContain('data-set-type="image"')
        ->toContain('data-set-attr-caption="My caption"')
        ->not->toContain('[caption');
});

it('process returns an array for a blogroll shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[blogroll style="default"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="news"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_news');
});

it('PeopleShortcodeProcessor normalizes attribute values to lowercase in parse()', function (): void {
    $processor = new PeopleShortcodeProcessor('show-people');
    $attributes = [
        'category' => 'Pulmonology-fellowship-faculty',
        'layout' => 'Grid',
        'style' => 'Img-Square',
        'display' => 'Name,Photo',
    ];

    $result = $processor->parse(
        $attributes,
        'default',
        $this->assetImporter,
        shortcodeBlockOutput(),
        []
    );

    $attrs = shortcodeBlockNormalizeStringKeyedArray($result['attrs'] ?? null);
    $values = shortcodeBlockNormalizeStringKeyedArray($attrs['values'] ?? null);

    expect($values['layout'])->toBe('grid');
});

it('PeopleShortcodeProcessor normalizes attribute values in toPageBuilderBlock()', function (): void {
    $processor = new PeopleShortcodeProcessor('show-team');
    $attributes = [
        'category' => 'Some-Team-Category',
        'layout' => 'grid',
    ];

    $block = shortcodeBlockNormalizeStringKeyedArray(
        $processor->toPageBuilderBlock(
            $attributes,
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            []
        )
    );

    expect($block['category'])->toBe('some-team-category');
});

it('PeopleShortcodeProcessor trims attribute values before processing', function (): void {
    $processor = new PeopleShortcodeProcessor('show-people');
    $attributes = [
        'category' => 'pulmonology-fellows ',
        'layout' => ' grid ',
    ];

    $block = shortcodeBlockNormalizeStringKeyedArray(
        $processor->toPageBuilderBlock(
            $attributes,
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            []
        )
    );

    expect($block['category'])->toBe('pulmonology-fellows');
    expect($block['layout'])->toBe('grid');
});

it('process returns a Tiptap html string for an audio shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[audio mp3="https://example.com/audio.mp3"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireStringResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result)->toContain('data-set-type="audio"');
});

it('process flattens an accordion shortcode into a heading and its content', function (): void {
    $html = '<!-- wp:shortcode --><div>[accordion][section title="Section A"]Content A[/section][/accordion]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireStringResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    // The host app defines no accordion set, so the section becomes a heading
    // and a paragraph in place of the shortcode
    expect($result)->toContain('<h3>Section A</h3><p>Content A</p>')
        ->not->toContain('[accordion]')
        ->not->toContain('data-set-type');
});

it('process handles a wp:shortcode block within mixed block types', function (): void {
    $html = '<!-- wp:paragraph --><p>Intro text</p><!-- /wp:paragraph -->
<!-- wp:shortcode --><p>[table id=7 /]</p><!-- /wp:shortcode -->
<!-- wp:paragraph --><p>More text</p><!-- /wp:paragraph -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="table"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_table');
    expect($result['skipCount'])->toBeGreaterThanOrEqual(2);
});

it('process handles each wp:shortcode when a document has multiple shortcode blocks', function (): void {
    $html = '<div><!-- wp:shortcode --><p>[table id=7 /]</p><!-- /wp:shortcode -->
<!-- wp:paragraph --><p>Between</p><!-- /wp:paragraph -->
<!-- wp:shortcode --><p>[table id=8 /]</p><!-- /wp:shortcode --></div>';

    // Process first wp:shortcode block
    $comment1 = shortcodeBlockRequireCommentNode($this->doc, $html, 0);
    $result1 = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment1,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );
    expect($result1['html'])->toContain('data-set-type="table"');
    expect($result1['page_builder_set']['type'])->toBe('uamsweb_block_table');

    // Process second wp:shortcode block (reload DOM to get a fresh node)
    $comment2 = shortcodeBlockRequireCommentNode($this->doc, $html, 1);
    $result2 = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment2,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );
    expect($result2['html'])->toContain('data-set-type="table"');
    expect($result2['page_builder_set']['type'])->toBe('uamsweb_block_table');
});

it('process handles two consecutive wp:shortcode blocks', function (): void {
    $html = '<div><!-- wp:shortcode --><p>[table id=7 /]</p><!-- /wp:shortcode --><!-- wp:shortcode --><p>[table id=8 /]</p><!-- /wp:shortcode --></div>';

    $comment1 = shortcodeBlockRequireCommentNode($this->doc, $html, 0);
    $result1 = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment1,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );
    expect($result1['html'])->toContain('data-set-type="table"');
    expect($result1['page_builder_set']['type'])->toBe('uamsweb_block_table');

    $comment2 = shortcodeBlockRequireCommentNode($this->doc, $html, 1);
    $result2 = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment2,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );
    expect($result2['html'])->toContain('data-set-type="table"');
    expect($result2['page_builder_set']['type'])->toBe('uamsweb_block_table');
});

it('process drops a subpage-list shortcode without warning or residual text', function (): void {
    // Start from a clean warning collection so the assertion is unambiguous
    ImportWarningManager::clearWarnings();

    // Spy on the log so we can assert the per-entry warning never fires
    $logSpy = shortcodeBlockSpyLog();

    // Wrap in <p>: libxml HTML drops raw `[shortcode]` between comments
    $html = '<!-- wp:shortcode --><p>[subpage-list link=""]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process(
        $comment,
        [],
        'default',
        $this->assetImporter,
        shortcodeBlockOutput(),
        [],
        [],
        'management-of-the-neonate'
    );

    // A fully-dropped block returns an empty string, never null — null would be
    // logged downstream as a block-processor failure.
    expect($result)->toBe('');

    if (! is_string($result)) {
        throw new RuntimeException('Expected process() to return the empty string.');
    }

    // The literal shortcode text must not survive into the imported content.
    expect($result)->not->toContain('[subpage-list');

    // The "No shortcodes extracted from wp:shortcode block content" warning must
    // not fire now that subpage-list is a recognized shortcode.
    $logSpy->shouldNotHaveReceived('warning', [
        Mockery::pattern('/No shortcodes extracted/'),
        Mockery::any(),
    ]);

    // A recognized drop is not an unaddressed shortcode, so nothing is tracked.
    expect(ImportWarningManager::getWarnings('content', 'unaddressed_shortcodes'))
        ->toHaveCount(0);
});

/**
 * A typed, silent console output for shortcode processing.
 */
function shortcodeBlockOutput(): OutputInterface
{
    return new NullOutput;
}

/**
 * Spy on the Log facade for warning assertions.
 */
function shortcodeBlockSpyLog(): LegacyMockInterface
{
    return Log::spy();
}

/**
 * Load HTML and return the Nth opening wp:shortcode comment node (0-based index).
 */
function shortcodeBlockRequireCommentNode(
    DOMDocument $doc,
    string $html,
    int $index = 0
): DOMComment {
    // DOMDocument::loadHTML() rejects an empty string; narrow before handing it over.
    if ($html === '') {
        throw new InvalidArgumentException('Cannot load empty HTML.');
    }

    $doc->loadHTML(
        $html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    $xpath = new DOMXPath($doc);
    $comments = $xpath->query(
        '//comment()[contains(., "wp:shortcode") and not(contains(., "/wp:shortcode"))]'
    );
    expect($comments)->not->toBeFalse();

    $comment = $comments === false ? null : $comments->item($index);
    expect($comment)->toBeInstanceOf(DOMComment::class);

    if (! $comment instanceof DOMComment) {
        throw new RuntimeException(
            "Expected a wp:shortcode opening comment node at index {$index} in the HTML."
        );
    }

    return $comment;
}

/**
 * Normalize mixed array data to a string-keyed array.
 *
 * @return array<string, mixed>
 */
function shortcodeBlockNormalizeStringKeyedArray(mixed $candidate): array
{
    if (! is_array($candidate)) {
        return [];
    }

    $normalized = [];

    foreach ($candidate as $key => $value) {
        $normalized[(string) $key] = $value;
    }

    return $normalized;
}

/**
 * Assert process() returned a shortcode array result with html and page_builder_set.
 *
 * @return array{
 *     html: string,
 *     page_builder_set: array<string, mixed>,
 *     skipCount: int
 * }
 */
function shortcodeBlockRequireArrayResult(mixed $result): array
{
    expect($result)->toBeArray();

    if (! is_array($result)) {
        throw new RuntimeException('Expected process() to return an array.');
    }

    expect($result)->toHaveKey('html');
    expect($result)->toHaveKey('page_builder_set');

    $html = $result['html'] ?? null;
    expect($html)->toBeString();

    $pageBuilderSet = $result['page_builder_set'] ?? null;
    expect($pageBuilderSet)->toBeArray();

    $skipCount = $result['skipCount'] ?? 0;
    expect($skipCount)->toBeInt();

    if (! is_string($html)) {
        throw new RuntimeException('Expected process() result html to be a string.');
    }

    if (! is_array($pageBuilderSet)) {
        throw new RuntimeException('Expected process() result page_builder_set to be an array.');
    }

    if (! is_int($skipCount)) {
        throw new RuntimeException('Expected process() result skipCount to be an int.');
    }

    return [
        'html' => $html,
        'page_builder_set' => shortcodeBlockNormalizeStringKeyedArray($pageBuilderSet),
        'skipCount' => $skipCount,
    ];
}

/**
 * Assert process() returned a string result.
 *
 * Retained after wordpress-importer-archive#509: this does more than narrow — the `expect()` inside is a
 * real runtime assertion, and `process()` is declared to return `mixed`.
 */
function shortcodeBlockRequireStringResult(mixed $result): string
{
    expect($result)->toBeString();

    if (! is_string($result)) {
        throw new RuntimeException('Expected process() to return a string.');
    }

    return $result;
}

/**
 * Assert process() returned html and a skip count over the whole block, with no
 * page builder set, and return the html.
 *
 * A lone registered shortcode whose handler builds no page builder block returns
 * this shape, so the caller does not read the block's inner nodes again.
 */
function shortcodeBlockRequireHtmlOnlyResult(mixed $result): string
{
    expect($result)->toBeArray();

    if (! is_array($result)) {
        throw new RuntimeException('Expected process() to return an array.');
    }

    expect($result)->not->toHaveKey('page_builder_set')
        ->and($result['skipCount'] ?? null)->toBe(3);

    $html = $result['html'] ?? null;

    if (! is_string($html)) {
        throw new RuntimeException('Expected process() to return html.');
    }

    return $html;
}

it('process counts every remaining sibling when a caption block has no closing comment', function (): void {
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]<img src="https://example.com/img.jpg" />My caption[/caption]</div><div></div>';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    // The opening comment and the two divs; no closing comment to add
    expect(is_array($result) ? ($result['skipCount'] ?? null) : null)->toBe(3);
});

it('process keeps what surrounds a lone shortcode, in the content a string returns', function (string $inner, string $name, string $kept): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.$inner.'<!-- /wp:shortcode --></div>');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeString()
        ->toContain($kept)
        ->not->toContain('['.$name);
})->with([
    'text around a caption' => ['Lead [caption id="attachment_1"]<img src="https://example.com/img.jpg" />Cap[/caption] tail', 'caption', 'Lead'],
]);

it('process returns what is on each side of a shortcode beside its page builder set', function (string $inner, string $html, ?string $before, ?string $after): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.$inner.'<!-- /wp:shortcode --></div>');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeArray()->toHaveKey('page_builder_set')
        ->and($result['html_before'] ?? null)->toBe($before)
        ->and($result['html_after'] ?? null)->toBe($after)
        ->and($result['html'] ?? null)->toContain($html)->toContain('data-set-type="people"');
})->with([
    'text' => ['Lead [show-people ids="1"] tail', 'Lead ', 'Lead ', ' tail'],
    'only before' => ['Lead [show-people ids="1"]', 'Lead ', 'Lead ', null],
    'only after' => ['[show-people ids="1"] tail', ' tail', null, ' tail'],
    'a wrapper holds no text of its own' => ['<p>[show-people ids="1"] tail</p>', ' tail', null, ' tail</p>'],
    'a paragraph after a block with no closing comment' => ['[show-people ids="1"]<p>After</p>', '<p>After</p>', null, '<p>After</p>'],
]);

it('process returns nothing around a shortcode that is all the block holds', function (): void {
    // The block sits in a body, as AcfBlockParser and ContentProcessor load content.
    // Loaded bare with LIBXML_HTML_NOIMPLIED, libxml 2.14 and later drop text at the
    // top of the document, so the block would hold nothing (#405)
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<!DOCTYPE html><html><body><!-- wp:shortcode -->[show-people ids="1"]<!-- /wp:shortcode --></body></html>');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeArray()->not->toHaveKey('html_before')->not->toHaveKey('html_after');
});

it('process reads a wrapper holding only the shortcode as a lone shortcode', function (string $inner): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.$inner.'<!-- /wp:shortcode --></div>');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeArray()->toHaveKey('page_builder_set')->not->toHaveKey('html_before')->not->toHaveKey('html_after');
})->with([
    'bare' => '[show-people ids="1"]',
    'in a paragraph' => '<p>[show-people ids="1"]</p>',
    'in a div' => '<div>[show-people ids="1"]</div>',
    'beside a non-breaking space in an element' => '<p>&nbsp;[show-people ids="1"]</p>',
    'beside a non-breaking space in text' => '&nbsp;[show-people ids="1"]',
]);

it('process returns the text and a skip count for a lone caption with no image', function (): void {
    // A caption with no image keeps its text (#164); the block is still skipped
    // whole, as it was when the caption built a page builder block
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]No image here[/caption]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBe(['html' => 'No image here', 'skipCount' => 3]);
});

it('process returns a plain string for a lone shortcode with no page builder block other than a caption', function (): void {
    // Audio builds no page builder block and is not a caption, so it keeps the loop's string
    $html = '<!-- wp:shortcode --><div>[audio mp3="https://example.com/a.mp3"]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    expect($this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page'))->toBeString();
});

it('process keeps the rendered shortcodes and warns about one that renders nothing among several', function (): void {
    // Start from no entry context, which an earlier test in the process can leave set
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearFormConfigContext();

    // Collect every warning logged during the call
    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]<img src="https://example.com/img.jpg" />With image[/caption] [caption id="attachment_2"][/caption]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    // The rendered caption is replaced; the empty one stays as its shortcode text
    expect($result)->toBeString()
        ->toContain('data-set-attr-caption="With image"')
        ->toContain('[caption id="attachment_2"][/caption]');

    // The caption's own missing-content warning comes first; the block processor's follows
    $blockWarnings = \array_values(\array_filter(
        $warnings->getArrayCopy(),
        static fn (array $warning): bool => \str_contains($warning['message'], 'ShortcodeBlockProcessor::process')
    ));

    expect($blockWarnings)->toHaveCount(1)
        ->and($blockWarnings[0]['message'] ?? '')->toContain("toTiptapHtml returned empty string for shortcode 'caption'")
        ->and($blockWarnings[0]['context'] ?? [])->toBe([
            'site' => 'default',
            'pageSlug' => 'test-page',
            'shortcode_name' => 'caption',
            'shortcode_original' => '[caption id="attachment_2"][/caption]',
        ]);
});

it('process renders a lone shortcode once, with or without a page builder block', function (string $inner, string $name): void {
    // A lone shortcode is rendered either by the single-shortcode branch or by the
    // loop, never by both, so its side effects (an audio import, a log line) run once
    $counting = new ShortcodeBlockCountingShortcodeProcessor;
    $processor = new ShortcodeBlockProcessor($counting);

    $comment = shortcodeBlockRequireCommentNode($this->doc, '<!-- wp:shortcode --><div>'.$inner.'</div><!-- /wp:shortcode -->');

    $processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($counting->renders)->toBe([$name => 1]);
})->with([
    'audio, no page builder block' => ['[audio mp3="https://example.com/a.mp3"]', 'audio'],
    'accordion, no page builder block' => ['[accordion][section title="A"]x[/section][/accordion]', 'accordion'],
    'table, a page builder block' => ['[table id=1 /]', 'table'],
    'caption, html alone' => ['[caption id="attachment_1"]<img src="https://example.com/img.jpg" />Cap[/caption]', 'caption'],
]);

it('reports bounded empty-block diagnostics without reading past the closing comment', function (int $count, ?string $page, ?string $whitespace, bool $closed): void {
    // Ignore non-content nodes but retain diagnostics that identify the export.
    ProcessingContext::clearFormConfigContext();
    ProcessingContext::setWordPressPageLink('https://example.test/source');
    $root = $this->doc->appendChild($this->doc->createElement('div'));
    $opening = $root->appendChild($this->doc->createComment('wp:shortcode'));
    $expected = [];

    for ($index = 0; $index < $count; $index++) {
        $root->appendChild($this->doc->createComment('N'.str_repeat('x', 119)));

        if ($index < 10) {
            $expected[] = ['type' => DOMComment::class, 'nodeType' => XML_COMMENT_NODE, 'content_preview' => 'N'.str_repeat('x', 99), 'is_empty_after_trim' => null];
        }
    }

    // Include whitespace diagnostics and a block that ends at the parent boundary.
    if ($whitespace !== null) {
        $root->appendChild($this->doc->createTextNode($whitespace));
        $expected[] = ['type' => DOMText::class, 'nodeType' => XML_TEXT_NODE, 'content_preview' => $whitespace, 'is_empty_after_trim' => true];
    }

    if ($closed) {
        $root->appendChild($this->doc->createComment('/wp:shortcode'));
        $root->appendChild($this->doc->createElement('p', 'Outside the block'));
    }

    // Require the complete warning payload, including the ten-node diagnostic cap.
    Log::shouldReceive('warning')->once()->withArgs(
        /** @param array<string, mixed> $context */
        static fn (string $message, array $context): bool => $message === 'ShortcodeBlockProcessor::process: No valid content found in wp:shortcode block (from entry: https://example.test/source)'
            && $context === ['site' => 'clinic', 'pageSlug' => $page ?? 'none', 'node_info' => $expected, 'nodes_checked' => count($expected), 'entry_url' => 'https://example.test/source']
    );

    try {
        expect($this->processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput(), pageSlug: $page))->toBeNull();
    } finally {
        ProcessingContext::clearWordPressPageLink();
    }
})->with([
    'no siblings before the close' => [0, null, null, true],
    'comment and whitespace diagnostics' => [1, 'entry', " \t", true],
    'the diagnostic cap and an extra sibling' => [11, 'entry', null, true],
    'missing closing comment' => [0, null, null, false],
]);

it('keeps unrecognized content and reports its exact preview and bracket diagnostics', function (string $content, int $length, string $preview, bool $starts, bool $ends, ?string $page): void {
    // Isolate the warning from any context left by another import.
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
    $root = $this->doc->appendChild($this->doc->createElement('div'));
    $opening = $root->appendChild($this->doc->createComment('wp:shortcode'));
    $root->appendChild($this->doc->createTextNode($content));
    $root->appendChild($this->doc->createComment('/wp:shortcode'));

    // Require preserved content and bounded diagnostics, not only a warning count.
    Log::shouldReceive('warning')->once()->withArgs(
        /** @param array<string, mixed> $context */
        static fn (string $message, array $context): bool => $message === 'ShortcodeBlockProcessor::process: No shortcodes extracted from wp:shortcode block content'
            && $context === ['site' => 'clinic', 'pageSlug' => $page ?? 'none', 'shortcode_content' => $content, 'content_length' => $length, 'content_preview' => $preview, 'starts_with_bracket' => $starts, 'ends_with_bracket' => $ends]
    );

    expect($this->processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput(), pageSlug: $page))->toBe($content);
})->with([
    'opening bracket only' => ['['.str_repeat('a', 210), 211, '['.str_repeat('a', 199), true, false, null],
    'closing bracket only' => [str_repeat('b', 210).']', 211, str_repeat('b', 200), false, true, 'entry'],
    'literal replacement text' => ['PEST Mutator was here!', 22, 'PEST Mutator was here!', false, false, 'entry'],
]);

it('clears block-scoped identifiers before an injected processor builds a page builder set', function (): void {
    // Return a custom payload whose IDs must not leak from block scope into the set.
    $shortcodes = $this::createStub(ShortcodeProcessor::class);
    $shortcodes->method('extract')->willReturn([['name' => 'custom', 'original' => '[custom]', 'id' => 'block-id', 'link' => 'https://example.test/block']]);
    $shortcodes->method('getHandlers')->willReturn(['custom' => ['set' => 'custom', 'class' => PeopleShortcodeProcessor::class]]);
    $shortcodes->method('toTiptapHtml')->willReturn('<p>Rendered</p>');
    $shortcodes->method('process')->willReturnCallback(
        /** @param array<int|string, mixed> $shortcode */
        static fn (array $shortcode): array => ['type' => 'custom', 'id' => $shortcode['id'], 'link' => $shortcode['link']]
    );
    $processor = new ShortcodeBlockProcessor($shortcodes);
    $opening = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->[custom]<!-- /wp:shortcode --></div>');

    // Observe the extension's returned set rather than private dispatch details.
    expect($processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput()))->toBe([
        'html' => '<p>Rendered</p>', 'skipCount' => 3, 'page_builder_set' => ['type' => 'custom', 'id' => '', 'link' => ''],
    ]);
});

it('reads block boundaries only from comments and preserves text whitespace between elements', function (): void {
    // Text and processing instructions cannot act as closing comments.
    $root = $this->doc->appendChild($this->doc->createElement('div'));
    $opening = $root->appendChild($this->doc->createComment(' wp:shortcode '));
    $root->appendChild($this->doc->createProcessingInstruction('ignored', '/wp:shortcode'));
    $root->appendChild($this->doc->createTextNode(' /wp:shortcode '));
    $root->appendChild($this->doc->createElement('b', 'Kept'));
    $root->appendChild($this->doc->createTextNode(' tail '));
    $root->appendChild($this->doc->createComment(' /wp:shortcode '));
    $root->appendChild($this->doc->createElement('p', 'Outside'));

    // Count all inner nodes but ignore processing instructions as content.
    expect($this->processor->canProcess($opening, 'clinic', null))->toBeTrue()
        ->and(ShortcodeBlockProcessor::blockContent($opening))->toBe('/wp:shortcode <b>Kept</b> tail')
        ->and(ShortcodeBlockProcessor::blockSkipCount($opening))->toBe(6);
});

it('reports the exact image snippet at the truncation boundary', function (int $width, ?string $page): void {
    // Drop the shortcode but retain an adjacent image for the warning summary.
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
    $source = str_repeat('x', $width);
    $image = '<img src="'.$source.'">';
    $opening = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.$image.'[subpage-list]<!-- /wp:shortcode --></div>');

    try {
        $result = $this->processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput(), pageSlug: $page);
        $warnings = ImportWarningManager::getWarnings('content', 'images_beside_shortcodes');
    } finally {
        ImportWarningManager::clearWarnings();
    }

    // Only the 101-character tag loses its final character to truncation.
    expect($result)->toBe($image)
        ->and($warnings)->toBe([[
            'site' => 'clinic', 'page_slug' => $page ?? '', 'page_link' => '',
            'content_snippet' => $width === 88 ? $image : '<img src="'.$source.'"...',
            'reason' => 'An image beside a shortcode in a wp:shortcode block was not imported.',
        ]]);
})->with([
    'exactly 100 characters' => [88, null],
    'one character beyond the limit' => [89, 'entry'],
]);

it('identifies content beside shortcodes without treating malformed payloads or wrappers as text', function (array $shortcodes, string $content, bool $expected): void {
    expect(ShortcodeBlockProcessor::holdsContentBesideShortcodes($shortcodes, $content))->toBe($expected);
})->with([
    'only a shortcode in a wrapper' => [[['original' => '[custom]']], '<p>[custom]</p>', false],
    'a literal replacement text shortcode' => [[['original' => 'PEST Mutator was here!']], '<p>PEST Mutator was here!</p>', false],
    'missing original' => [[[]], 'PEST Mutator was here!', true],
    'nonnumeric malformed payload' => [['invalid', ['original' => []]], '<p>Kept</p>', true],
    'non-breaking whitespace' => [[['original' => '[custom]']], "\u{A0}[custom]", false],
    'an image without text' => [[['original' => '[custom]']], '<img src="x">[custom]', true],
]);

it('preserves content when an injected extractor cannot locate its original text', function (array $payload): void {
    // The public injection contract permits partially populated extractor results.
    $shortcodes = $this::createStub(ShortcodeProcessor::class);
    $shortcodes->method('extract')->willReturn([$payload]);
    $shortcodes->method('getHandlers')->willReturn(['custom' => ['set' => 'custom', 'class' => PeopleShortcodeProcessor::class]]);
    $shortcodes->method('process')->willReturn(['type' => 'custom']);
    $shortcodes->method('toTiptapHtml')->willReturn('<p>Rendered</p>');
    $processor = new ShortcodeBlockProcessor($shortcodes);
    $opening = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->PEST Mutator was here!<!-- /wp:shortcode --></div>');

    // Retain unmatched content after the set rather than deleting or replacing it.
    $result = $processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput());

    expect($result)->toBe([
        'html' => 'PEST Mutator was here!', 'skipCount' => 3, 'page_builder_set' => ['type' => 'custom'], 'html_after' => 'PEST Mutator was here!',
    ]);
})->with([
    'missing original text' => [['name' => 'custom']],
    'invalid original text' => [['name' => 'custom', 'original' => []]],
    'original not found in the block' => [['name' => 'custom', 'original' => '[elsewhere]']],
]);

it('does not dispatch malformed names to a different registered shortcode', function (array $payload): void {
    // A custom registry distinguishes the empty fallback from a registered name.
    $shortcodes = $this::createStub(ShortcodeProcessor::class);
    $shortcodes->method('extract')->willReturn([$payload + ['original' => '[custom]']]);
    $shortcodes->method('getHandlers')->willReturn(['PEST Mutator was here!' => ['set' => 'custom', 'class' => PeopleShortcodeProcessor::class]]);
    $shortcodes->method('process')->willReturn(['type' => 'wrong-handler']);
    $shortcodes->method('toTiptapHtml')->willReturn('<p>Wrong handler</p>');
    $processor = new ShortcodeBlockProcessor($shortcodes);
    $opening = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->[custom]<!-- /wp:shortcode --></div>');

    // An absent or invalid name must leave the original block content alone.
    expect($processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput()))->toBe('[custom]');
})->with([
    'missing name' => [[]],
    'invalid name' => [['name' => []]],
]);

it('skips a malformed payload among several and converts the rest', function (): void {
    // The public injection contract permits a payload that is not an array.
    $shortcodes = $this::createStub(ShortcodeProcessor::class);
    $shortcodes->method('extract')->willReturn(['malformed', ['name' => 'custom', 'original' => '[custom]']]);
    $shortcodes->method('getHandlers')->willReturn(['custom' => ['set' => 'custom', 'class' => PeopleShortcodeProcessor::class]]);
    $shortcodes->method('toTiptapHtml')->willReturn('<p>Rendered</p>');
    $processor = new ShortcodeBlockProcessor($shortcodes);
    $opening = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->[custom]<!-- /wp:shortcode --></div>');

    // The payload after the malformed one is still converted.
    expect($processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput()))->toBe('<p>Rendered</p>');
});

it('drops whitespace left between empty wrappers after removing a shortcode', function (): void {
    // Removing a shortcode can expose whitespace inside an already-trimmed block.
    $opening = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode --><p>[subpage-list]</p> <p></p><!-- /wp:shortcode --></div>');

    expect($this->processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput()))->toBe('');
});

it('retains entity text in empty-block diagnostics when nodeValue is null', function (): void {
    // Entity references expose expanded text through textContent, not nodeValue.
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
    $this->doc->loadXML('<!DOCTYPE root [<!ENTITY named "Named content">]><root><!--wp:shortcode-->&named;<!--/wp:shortcode--></root>');
    $root = $this->doc->documentElement;
    $this::assertInstanceOf(DOMElement::class, $root);
    $opening = $root->firstChild;
    $this::assertInstanceOf(DOMComment::class, $opening);

    // Require expanded text in the warning without importing a non-content node.
    Log::shouldReceive('warning')->once()->withArgs(
        /** @param array<string, mixed> $context */
        static fn (string $message, array $context): bool => $message === 'ShortcodeBlockProcessor::process: No valid content found in wp:shortcode block'
            && $context === ['site' => 'clinic', 'pageSlug' => 'none', 'node_info' => [
                ['type' => DOMEntityReference::class, 'nodeType' => XML_ENTITY_REF_NODE, 'content_preview' => 'Named content', 'is_empty_after_trim' => null],
            ], 'nodes_checked' => 1]
    );

    expect($this->processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput()))->toBeNull();
});

it('keeps the existing empty-result fallback when paragraph cleanup exhausts its regex limit', function (): void {
    // Pin current failure behavior separately from successful cleanup.
    // The limit must let shortcode extraction finish and stop the cleanup over the
    // whitespace run, and PCRE counts against it differently with pcre.jit on and off.
    // Measured on PCRE 10.44 and 10.35: extraction needs at most 6 with JIT and 11
    // without, and cleanup over 1000 spaces needs at least 1002 with JIT and 4006
    // without, so 100 sits inside the window in both modes.
    $opening = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode --><p>'.str_repeat(' ', 1000).'text[subpage-list]</p><!-- /wp:shortcode --></div>');
    $previous = ini_set('pcre.backtrack_limit', '100');

    try {
        expect($this->processor->process($opening, [], 'clinic', $this->assetImporter, shortcodeBlockOutput()))->toBe('')
            ->and(preg_last_error())->toBe(PREG_BACKTRACK_LIMIT_ERROR);
    } finally {
        ini_set('pcre.backtrack_limit', $previous);
    }
});
