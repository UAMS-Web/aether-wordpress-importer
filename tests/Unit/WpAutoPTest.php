<?php

declare(strict_types=1);

/**
 * Tests `WpAutoP`, this package's port of WordPress's `wpautop()`, by the exact HTML
 * it gives for each input shape: paragraphs and line breaks, `<pre>` and script
 * protection, block-level tags, and the clean-up passes that remove stray `<p>` and
 * `<br />` around blocks.
 *
 * Each expected string is what WordPress 7.0.7's `wpautop()` returns for the same
 * input (#146), except where a test says it records a deliberate difference.
 *
 * @command  php vendor/bin/pest tests/Unit/WpAutoPTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;

it('returns nothing for blank text', function (string $text): void {
    expect((new WpAutoP)->process($text))->toBe('');
})->with([
    'empty' => [''],
    'whitespace only' => ["  \n\t "],
]);

it('wraps text in paragraphs and breaks as WordPress does', function (string $text, bool $br, string $html): void {
    expect((new WpAutoP)->process($text, $br))->toBe($html);
})->with([
    'one line' => ['Hello', true, "<p>Hello</p>\n"],
    'two paragraphs' => ["One\n\nTwo", true, "<p>One</p>\n<p>Two</p>\n"],
    'a single line break' => ["One\nTwo", true, "<p>One<br />\nTwo</p>\n"],
    'a single line break with line breaks off' => ["One\nTwo", false, "<p>One\nTwo</p>\n"],
    'CRLF and CR newlines' => ["One\r\n\r\nTwo\rThree", true, "<p>One</p>\n<p>Two<br />\nThree</p>\n"],
    'a run of blank lines' => ["One\n\n\n\nTwo", true, "<p>One</p>\n<p>Two</p>\n"],
    'two br tags in a row' => ['One<br><br/>Two', true, "<p>One</p>\n<p>Two</p>\n"],
    'each br spelling' => ["A<br>\nB<br/>\nC", true, "<p>A<br />\nB<br />\nC</p>\n"],
    'a pre between paragraphs' => ["Intro\n\n<pre>a\n\nb</pre>\n\nOutro", true, "<p>Intro</p>\n<pre>a\n\nb</pre>\n<p>Outro</p>\n"],
    'two pres' => ["<pre>x\ny</pre>\n\nmid\n\n<pre class=\"c\">p\n\nq</pre>\n\nend", true, "<pre>x\ny</pre>\n<p>mid</p>\n<pre class=\"c\">p\n\nq</pre>\n<p>end</p>\n"],
    'a pre on its own' => ["<pre>a\n\nb</pre>", true, "<pre>a\n\nb</pre>\n"],
    'a pre between text with no blank lines' => ['lead<pre>a</pre>tail', true, "<p>lead</p>\n<pre>a</pre>\n<p>tail</p>\n"],
    'a single block' => ["<div>One\nTwo</div>", true, "<div>One<br />\nTwo</div>\n"],
    'two different blocks' => ['<h2>Title</h2><p>Body</p>', true, "<h2>Title</h2>\n<p>Body</p>\n"],
    'an image between text' => ['Before<img src="x.jpg" />After', true, "<p>Before<img src=\"x.jpg\" />After</p>\n"],
    'a script between paragraphs' => ["Text\n\n<script>a\nb</script>\n\nMore", true, "<p>Text</p>\n<p><script>a\nb</script></p>\n<p>More</p>\n"],
    'a script with line breaks off' => ["Text\n\n<script>a\nb</script>\n\nMore", false, "<p>Text</p>\n<p><script>a\nb</script></p>\n<p>More</p>\n"],
    'text closing a div' => ["Lead\n\nText</div>", true, "<p>Lead</p>\n<p>Text</p></div>\n"],
    'a list on its own' => ["<ul>\n<li>One</li>\n<li>Two</li>\n</ul>", true, "<ul>\n<li>One</li>\n<li>Two</li>\n</ul>\n"],
    'a list after text' => ["Lead\n\n<ul><li>One</li></ul>", true, "<p>Lead</p>\n<ul>\n<li>One</li>\n</ul>\n"],
    'a blockquote after text' => ["Lead\n\n<blockquote>Quote</blockquote>", true, "<p>Lead</p>\n<blockquote><p>Quote</p></blockquote>\n"],
    'a blockquote with an attribute' => ["Lead\n\n<blockquote class=\"q\">Quote\nmore</blockquote>\n\nEnd", true, "<p>Lead</p>\n<blockquote class=\"q\"><p>Quote<br />\nmore</p></blockquote>\n<p>End</p>\n"],
    'a line break before a closing tag' => ["<div>One\n</div>\n\nAfter", true, "<div>One\n</div>\n<p>After</p>\n"],
    'inline markup' => ["A <strong>bold</strong>\nline", true, "<p>A <strong>bold</strong><br />\nline</p>\n"],
    'a table cell' => ["<table><tr><td>One\nTwo</td></tr></table>\n\nAfter", true, "<table>\n<tr>\n<td>One<br />\nTwo</td>\n</tr>\n</table>\n<p>After</p>\n"],
    'an attributed block after text' => ['Lead<div class="a">x</div>', true, "<p>Lead</p>\n<div class=\"a\">x</div>\n"],
    'an attributed hr after text' => ['Lead<hr class="x">Tail', true, "<p>Lead</p>\n<hr class=\"x\">Tail</p>\n"],
]);

it('turns single line breaks into br tags by default', function (): void {
    expect((new WpAutoP)->process("One\nTwo"))->toBe("<p>One<br />\nTwo</p>\n");
});

it('handles the edge cases its clean-up passes exist for, as WordPress does', function (string $text, string $html): void {
    expect((new WpAutoP)->process($text))->toBe($html);
})->with([
    'a single CRLF' => ["One\r\nTwo", "<p>One<br />\nTwo</p>\n"],
    'closing pre tags without an opener' => ['a</pre>b</pre><pre>c</pre>d', "<p>ab</p>\n<pre>c</pre>\n<p>d</p>\n"],
    'a script newline inside a lone block' => ["<div><script>a\nb</script></div>", "<div><script>a\nb</script></div>\n"],
    'a spaced script newline inside a lone block' => ["<div><script>a \n b</script></div>", "<div><script>a \n b</script></div>\n"],
    'a script newline inside a paragraph' => ["Text <script>a \n b</script>", "<p>Text <script>a \n b</script></p>\n"],
    'a leading newline' => ["\nOne\n\nTwo", "<p>One</p>\n<p>Two</p>\n"],
    'an empty paragraph' => ["One\n\n<p> </p>\n\nTwo", "<p>One</p>\n<p>Two</p>\n"],
]);

it('keeps the newlines of an element too large for a regular expression', function (): void {
    // A recorded difference (#146): WordPress finds these elements with a regular
    // expression, which exhausts the default backtrack limit on a 1.2 MB svg, so its
    // wpautop() returns an empty string. The port finds them with a string scan.
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1000000');

    try {
        $html = (new WpAutoP)->process("Intro\n\n<svg>".str_repeat("a  \nb\n", 200000)."</svg>\n\nOutro");
    } finally {
        ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
    }

    expect($html)->toStartWith("<p>Intro</p>\n<p><svg>a  \nb\na  \nb\n")
        ->and($html)->toEndWith("</svg></p>\n<p>Outro</p>\n")
        ->and($html)->not->toContain('<br />')
        ->and($html)->not->toContain('<WPPreserveNewline');
});

it('reports a step whose regular expression fails, and keeps the text', function (): void {
    // A failed step leaves its input unchanged and says so in the run's summary,
    // naming the step, the PCRE error and the start of the text (#146)
    ImportWarningManager::clearWarnings();
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1000');

    try {
        $html = (new WpAutoP)->process('<ul><li>'.str_repeat('x', 5000).'</li></ul>');
    } finally {
        ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
    }

    $warnings = ImportWarningManager::getWarnings('content', 'wpautop_step_failed');
    ImportWarningManager::clearWarnings();

    expect($html)->toContain('<li>'.str_repeat('x', 5000).'</li>')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toMatchArray([
            'step' => '|<p>(<li.+?)</p>|',
            'error' => 'Backtrack limit exhausted',
            'length' => 5028,
        ])
        ->and(data_get($warnings, '0.excerpt'))->toStartWith("<ul>\n<p><li>xxx");
});

it('restores a placeholder name already in the text as the pre it names', function (): void {
    // The placeholders are `<pre wp-pre-tag-N></pre>`, numbered from zero, and the
    // restore replaces every occurrence, so text that already holds one gets that
    // pre's content too. WordPress's wpautop() does the same.
    expect((new WpAutoP)->process("<pre wp-pre-tag-1></pre>\n\n<pre>B</pre>"))->toBe("<pre>B</pre>\n<pre>B</pre>\n");
});

it('starts a new block at a bare block tag after text, as WordPress does', function (string $text, string $html): void {
    // A tag without attributes ends the paragraph before it, as an attributed one
    // does (#145). Each expected string is what WordPress 7.0.7's wpautop() returns.
    expect((new WpAutoP)->process($text))->toBe($html);
})->with([
    'a bare div' => ['Lead<div>x</div>', "<p>Lead</p>\n<div>x</div>\n"],
    'a bare heading' => ['Lead<h2>T</h2>', "<p>Lead</p>\n<h2>T</h2>\n"],
    'a bare hr' => ['Lead<hr>Tail', "<p>Lead</p>\n<hr>\n<p>Tail</p>\n"],
    'a bare blockquote' => ['Lead<blockquote>Q</blockquote>Tail', "<p>Lead</p>\n<blockquote><p>Q</p></blockquote>\n<p>Tail</p>\n"],
    'a bare details' => ['Lead<details><summary>S</summary>B</details>', "<p>Lead</p>\n<details>\n<summary>S</summary>\n<p>B</details>\n"],
    'a self-closing hr' => ['Lead<hr />Tail', "<p>Lead</p>\n<hr />\n<p>Tail</p>\n"],
    'a tag whose name begins with a block name' => ['Lead<progress>x</progress>', "<p>Lead<progress>x</progress>\n"],
]);
