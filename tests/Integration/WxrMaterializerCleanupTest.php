<?php

declare(strict_types=1);

use Dom\HTMLDocument;
use Illuminate\Support\Facades\Http;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Rule;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * The WXR cleaner runs on migration API content for the sites whose exports it cleans (wordpress-importer-archive#1303).
 *
 * The WXR workflow cleans `news.uams.edu`'s export with `uams-web/aether-wordpress-importer-cleanup`
 * before importing it. Content read through the migration API did not pass through the
 * cleaner, so the same post mapped differently depending on its source. These tests read the
 * materialized parts back with the real `XmlParser`, the class the importer reads exports with.
 *
 * The other half is what must NOT happen: the cleaner removes every HTML comment, Gutenberg
 * block delimiters included, so a site that is not listed has to come through untouched.
 *
 * @command  php vendor/bin/pest tests/Integration/WxrMaterializerCleanupTest.php
 */
uses(StatamicTestCase::class);

/**
 * A post body carrying each kind of markup the cleaner exists for.
 *
 * Word-paste noise, a caption shortcode wrapped in a paragraph with a linked image, a layout
 * table around the body, and a YouTube embed.
 */
const CLEANUP_RAW_BODY = '<table width="600" border="0"><tbody><tr><td>'
    .'<p class="MsoNormal"><span style="font-family: Arial;">LITTLE ROCK – The &ldquo;new&rdquo; clinic opened -- today.<o:p></o:p></span></p>'
    .'<p>[caption id="attachment_42" align="alignleft" width="300"]<a href="https://news.uams.edu/wp-content/uploads/a.jpg"><img src="https://news.uams.edu/wp-content/uploads/a.jpg" alt="A" /></a> A caption.[/caption]</p>'
    .'<p><embed src="https://www.youtube.com/v/dQw4w9WgXcQ" type="application/x-shockwave-flash" width="425" height="350"></embed></p>'
    .'</td></tr></tbody></table>';

/**
 * A rule that adds to every body on each pass, so the cleaner's output never settles.
 */
final class CleanupNeverSettles implements Rule
{
    /**
     * {@inheritDoc}
     */
    public function apply(HTMLDocument $document): void
    {
        $document->body?->append('x');
    }
}

/**
 * A rule that fails as a programming error would, which the materializer must not catch.
 */
final class CleanupThrowsLogicError implements Rule
{
    /**
     * {@inheritDoc}
     */
    public function apply(HTMLDocument $document): void
    {
        throw new LogicException('A rule broke.');
    }
}

/**
 * A materializer that cleans with the given pipeline in place of the default one.
 */
final class CleanupTransformMaterializer extends WxrMaterializer
{
    /**
     * Create the materializer over a client that is never called, cleaning with the pipeline.
     */
    public function __construct(string $directory, private readonly Transform $transform)
    {
        parent::__construct(new Client('https://example.test', 'shhh'), $directory);
    }

    /**
     * {@inheritDoc}
     */
    protected function cleanupTransform(): Transform
    {
        return $this->transform;
    }
}

beforeEach(function (): void {
    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('wxr_clean_', true);
    \mkdir($this->workingDirectory);
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * Fake a single-site migration API whose site reports the given base blog URL.
 *
 * @param  string  $baseBlogUrl  The site's `base_blog_url`
 * @param  list<array<string, mixed>>  $posts  Post payloads served from `posts/post`
 */
function cleanupFakeApi(string $baseBlogUrl, array $posts): void
{
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1,
            'title' => 'Site',
            'link' => $baseBlogUrl,
            'description' => 'A site',
            'language' => 'en-US',
            'wxr_version' => '1.2',
            'base_site_url' => $baseBlogUrl,
            'base_blog_url' => $baseBlogUrl,
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response($posts, 200, ['X-WP-Total' => (string) \count($posts)]),
    ]);
}

/**
 * A post payload with the given title, body and excerpt.
 *
 * @return array<string, mixed>
 */
function cleanupPost(string $title, string $content, string $excerpt): array
{
    return [
        'id' => 501,
        'type' => 'post',
        'slug' => 'a-post',
        'status' => 'publish',
        'title' => $title,
        'author' => ['id' => 2, 'login' => 'todd', 'display_name' => 'Todd Example'],
        'date_created' => '2026-01-02 03:04:05',
        'date_created_gmt' => '2026-01-02 09:04:05',
        'date_modified' => '2026-01-02 03:04:05',
        'date_modified_gmt' => '2026-01-02 09:04:05',
        'content' => $content,
        'excerpt' => $excerpt,
        'parent' => 0,
        'menu_order' => 0,
        'guid' => 'https://news.uams.edu/?p=501',
        'link' => 'https://news.uams.edu/a-post/',
        'is_sticky' => false,
        'ping_status' => 'closed',
        'comment_status' => 'closed',
        'post_password' => '',
        'terms' => [],
        'meta' => [],
    ];
}

/**
 * Materialize the faked site and return its one post's title, body and excerpt as parsed.
 *
 * @return array{title: string, content: string, excerpt: string}
 */
function cleanupMaterializedPost(string $directory): array
{
    $paths = (new WxrMaterializer(new Client('https://example.test', 'shhh'), $directory))->materialize(1);

    return cleanupParsedPost($paths);
}

/**
 * Read the one post in the given WXR files as the importer's parser sees it.
 *
 * @param  list<string>  $paths  WXR file paths
 * @return array{title: string, content: string, excerpt: string}
 */
function cleanupParsedPost(array $paths): array
{
    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $item = $parser->getItems('post')[0];

    return [
        'title' => (string) $item->title,
        'content' => (string) $item->children('content', true)->encoded,
        'excerpt' => (string) $item->children('excerpt', true)->encoded,
    ];
}

/**
 * The same post as it reaches the importer from a cleaned export.
 *
 * Writes an export-shaped WXR holding the raw fields, cleans it exactly as the CLI does
 * (`Transform::withDefaultRules()->apply()`, which is all `bin/wxr-cleanup` runs), and parses
 * the result with the importer's parser.
 *
 * @return array{title: string, content: string, excerpt: string}
 */
function cleanupExportPost(string $directory, string $title, string $content, string $excerpt): array
{
    $cdata = static fn (string $value): string => '<![CDATA['.\str_replace(']]>', ']]]]><![CDATA[>', $value).']]>';

    $export = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel><title>Site</title><link>https://news.uams.edu</link><wp:wxr_version>1.2</wp:wxr_version>'
        .'<wp:base_site_url>https://news.uams.edu</wp:base_site_url><wp:base_blog_url>https://news.uams.edu</wp:base_blog_url>'
        .'<item><title>'.$cdata($title).'</title><link>https://news.uams.edu/a-post/</link>'
        .'<dc:creator>'.$cdata('todd').'</dc:creator><guid>https://news.uams.edu/?p=501</guid>'
        .'<content:encoded>'.$cdata($content).'</content:encoded><excerpt:encoded>'.$cdata($excerpt).'</excerpt:encoded>'
        .'<wp:post_id>501</wp:post_id><wp:post_name>'.$cdata('a-post').'</wp:post_name><wp:status>publish</wp:status>'
        .'<wp:post_type>post</wp:post_type></item></channel></rss>';

    $path = $directory.\DIRECTORY_SEPARATOR.'export-cleaned.xml';
    \file_put_contents($path, Transform::withDefaultRules()->apply($export));

    try {
        return cleanupParsedPost([$path]);
    } finally {
        \unlink($path);
    }
}

/**
 * Clean one part on disk through the materializer's own `cleanParts()`, for the site
 * `https://news.uams.edu`.
 */
function cleanupCleanPart(WxrMaterializer $materializer, string $path): void
{
    $clean = Closure::bind(function (string $part): void {
        $this->cleanParts([$part], 'https://news.uams.edu');
    }, $materializer, WxrMaterializer::class);

    if ($clean === null) {
        throw new RuntimeException('Could not bind to the materializer.');
    }

    $clean($path);
}

/**
 * Write a part holding an item for each body, keyed by its post id, or by a string key for an
 * item with no post id, and answer its path.
 *
 * @param  array<int|string, string>  $bodies  Each item's body, by post id
 */
function cleanupWritePart(string $directory, array $bodies): string
{
    $items = '';

    foreach ($bodies as $postId => $body) {
        $items .= '<item><title>A title</title><content:encoded><![CDATA['.$body.']]></content:encoded>'
            .(\is_int($postId) ? '<wp:post_id>'.$postId.'</wp:post_id>' : '')
            .'</item>';
    }

    $path = $directory.\DIRECTORY_SEPARATOR.'wxr.000.xml';
    \file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel>'.$items.'</channel></rss>');

    return $path;
}

it('maps a listed site\'s API post exactly as its cleaned export would', function (): void {
    $title = 'The &ldquo;new&rdquo; clinic -- opens';
    $excerpt = '<p>LITTLE ROCK – The <span style="color:red">clinic</span> opened.</p>';

    cleanupFakeApi('https://news.uams.edu', [cleanupPost($title, CLEANUP_RAW_BODY, $excerpt)]);

    $fromApi = cleanupMaterializedPost($this->workingDirectory);
    $fromExport = cleanupExportPost($this->workingDirectory, $title, CLEANUP_RAW_BODY, $excerpt);

    // Identical to the cleaned export, field by field
    expect($fromApi)->toBe($fromExport);

    // And genuinely cleaned, so the equality above is not two raw copies agreeing
    expect($fromApi['content'])->not->toBe(CLEANUP_RAW_BODY)
        ->not->toContain('MsoNormal')
        ->not->toContain('<table')
        ->not->toContain('<span')
        ->not->toContain('<embed')
        ->toContain('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->toContain('[caption id="attachment_42"');
});

// The comparison above runs the same cleaner on both sides, so a change in what the cleaner
// writes passes it. This pins the body itself (#612), so a cleanup release that changes the
// output fails here and the change is reviewed in the bump that brings it.
it('cleans a listed site\'s API post to the exact expected body', function (): void {
    cleanupFakeApi('https://news.uams.edu', [cleanupPost('A title', CLEANUP_RAW_BODY, '')]);

    // The cleaner writes em dashes, so they are spelled as escapes here
    $expected = "<p>LITTLE ROCK, Ark. \u{2014} The \"new\" clinic opened \u{2014} today.</p>\n\n"
        .'[caption id="attachment_42" align="alignleft" width="300"]'
        .'<img src="https://news.uams.edu/wp-content/uploads/a.jpg" alt="A" width="300"> A caption.[/caption]'
        ."\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ";

    expect(cleanupMaterializedPost($this->workingDirectory)['content'])->toBe($expected);
});

it('leaves an unlisted site\'s block content untouched', function (): void {
    $body = '<!-- wp:paragraph --><p class="MsoNormal">Body -- copy.</p><!-- /wp:paragraph -->'
        .'<!-- wp:acf/hero {"name":"acf/hero"} /-->';

    cleanupFakeApi('https://health.uams.edu', [cleanupPost('A title', $body, 'An excerpt.')]);

    $post = cleanupMaterializedPost($this->workingDirectory);

    expect($post['content'])->toBe($body)
        ->and($post['title'])->toBe('A title')
        ->and($post['excerpt'])->toBe('An excerpt.');
});

it('cleans only the sites the configuration lists', function (): void {
    config(['wordpress-import.wxr_cleanup.sites' => ['health.uams.edu']]);

    cleanupFakeApi('https://news.uams.edu', [cleanupPost('A title', CLEANUP_RAW_BODY, '')]);

    expect(cleanupMaterializedPost($this->workingDirectory)['content'])->toBe(CLEANUP_RAW_BODY);
});

it('decides by the host of the site\'s base_blog_url', function (array $channel, bool $cleans): void {
    expect(WxrMaterializer::cleansSite($channel))->toBe($cleans);
})->with([
    'the listed host' => [['base_blog_url' => 'https://news.uams.edu'], true],
    'the listed host with a path and a different case' => [['base_blog_url' => 'https://NEWS.uams.edu/archive/'], true],
    'another host' => [['base_blog_url' => 'https://health.uams.edu'], false],
    'a host that only ends with the listed one' => [['base_blog_url' => 'https://oldnews.uams.edu'], false],
    'no base_blog_url' => [[], false],
    'a URL with no host' => [['base_blog_url' => 'news.uams.edu'], false],
]);

it('refuses a list that is not a list', function (): void {
    config(['wordpress-import.wxr_cleanup.sites' => 'news.uams.edu']);

    expect(WxrMaterializer::cleansSite(['base_blog_url' => 'https://news.uams.edu']))->toBeFalse();
});

it('refuses to overwrite a part the cleaner could not read', function (): void {
    // The cleaner hides libxml errors, so an unparseable part comes back as an empty document.
    // Writing that over the part would silently drop every item it held.
    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.000.xml';
    $broken = '<rss><channel><item><title>One</title></item><item><title>Two</title></item>';
    \file_put_contents($path, $broken);

    $materializer = new WxrMaterializer(new Client('https://example.test', 'shhh'), $this->workingDirectory);

    expect(fn () => cleanupCleanPart($materializer, $path))->toThrow(MigrationApiException::class, 'returned 0 of its 2 items');
    expect(\file_get_contents($path))->toBe($broken);
});

// The cleaner reruns its rules until the output stops changing, and gives up past five
// passes. That failure has to stop the run naming the part, not escape as a bare
// `RuntimeException` naming nothing (#611).
it('stops on a cleaner that does not settle, naming the part and leaving it as written', function (): void {
    $path = cleanupWritePart($this->workingDirectory, [501 => '<p>Body.</p>']);
    $written = \file_get_contents($path);
    $materializer = new CleanupTransformMaterializer($this->workingDirectory, new Transform([new CleanupNeverSettles]));

    try {
        cleanupCleanPart($materializer, $path);
        $thrown = null;
    } catch (MigrationApiException $exception) {
        $thrown = $exception;
    }

    expect($thrown?->getMessage())->toBe(\sprintf(
        'The WXR cleaner failed on "%s": Cleanup rules did not settle after 5 passes; a rule keeps undoing another rule\'s work. The part was left as written.',
        $path
    ))
        ->and($thrown?->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and(\file_get_contents($path))->toBe($written)
        ->and(ImportWarningManager::getAllWarnings())->toBe([]);
});

it('stops on a part the cleaner refuses as empty, naming the part and leaving it as written', function (): void {
    // Nothing but characters XML forbids, which the cleaner strips to nothing and refuses
    $path = $this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.000.xml';
    \file_put_contents($path, "\x01\x02");
    $materializer = new WxrMaterializer(new Client('https://example.test', 'shhh'), $this->workingDirectory);

    expect(fn () => cleanupCleanPart($materializer, $path))->toThrow(
        MigrationApiException::class,
        \sprintf('The WXR cleaner failed on "%s": WXR input is empty. The part was left as written.', $path)
    );
    expect(\file_get_contents($path))->toBe("\x01\x02");
});

it('lets an error that is not the cleaner\'s own surface as itself', function (): void {
    $path = cleanupWritePart($this->workingDirectory, [501 => '<p>Body.</p>']);
    $materializer = new CleanupTransformMaterializer($this->workingDirectory, new Transform([new CleanupThrowsLogicError]));

    expect(fn () => cleanupCleanPart($materializer, $path))->toThrow(LogicException::class, 'A rule broke.');
});

// A post whose whole body cleans away is imported empty, as its cleaned export would be, so
// the only sign of it is the warning that names it (#611).
it('imports a post whose body cleans to nothing, naming it in a warning with the site and the post id', function (): void {
    cleanupFakeApi('https://news.uams.edu', [cleanupPost('A title', '<p>&nbsp;</p>', '')]);

    $paths = (new WxrMaterializer(new Client('https://example.test', 'shhh'), $this->workingDirectory))->materialize(1);

    expect(cleanupParsedPost($paths)['content'])->toBe('')
        ->and(ImportWarningManager::getAllWarnings())->toBe(['migration_api' => ['emptied_bodies' => [[
            'site' => 'https://news.uams.edu',
            'post_id' => 501,
            'label' => 'wp:post_id 501',
            'part' => $paths[0],
        ]]]]);
});

it('names every emptied post in a part, and only those, with no id for an item that has none', function (): void {
    $path = cleanupWritePart($this->workingDirectory, [501 => '<p>&nbsp;</p>', 502 => '<p>Kept text.</p>', 'no id' => '<p></p>']);

    cleanupCleanPart(new WxrMaterializer(new Client('https://example.test', 'shhh'), $this->workingDirectory), $path);

    expect(ImportWarningManager::getAllWarnings())->toBe(['migration_api' => ['emptied_bodies' => [
        ['site' => 'https://news.uams.edu', 'post_id' => 501, 'label' => 'wp:post_id 501', 'part' => $path],
        ['site' => 'https://news.uams.edu', 'post_id' => null, 'label' => 'an item without wp:post_id', 'part' => $path],
    ]]]);
});

it('records no warning when every body keeps text', function (): void {
    cleanupFakeApi('https://news.uams.edu', [cleanupPost('A title', CLEANUP_RAW_BODY, '')]);

    $paths = (new WxrMaterializer(new Client('https://example.test', 'shhh'), $this->workingDirectory))->materialize(1);

    expect(cleanupParsedPost($paths)['content'])->not->toBe('')
        ->and(ImportWarningManager::getAllWarnings())->toBe([]);
});
