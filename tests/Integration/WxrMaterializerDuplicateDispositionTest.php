<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition\ApplyDuplicateDisposition;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Disposition\DuplicateDisposition;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * News posts captured through the migration API get the duplicate disposition their export gets (#89).
 *
 * `news.uams.edu`'s export is cleaned with `bin/wxr-cleanup --disposition`, which drops the
 * non-canonical member of each duplicate set and gives the kept post its canonical slug
 * (`UAMS-Web/uams-statamic#46`). These tests serve real sets from the disposition the cleaner
 * ships through a faked API, read the materialized parts back with the importer's own
 * `XmlParser`, and compare them with the same posts sent through the CLI's two steps.
 *
 * Two sets from `dispositions/news-uams-edu.json`:
 *
 * - 12240 is kept at its own slug, and 16831 is dropped.
 * - 12335 is kept but renamed from its `-2` slug to the canonical one, and 12287, which held
 *   that slug, and 16828 are dropped.
 *
 * @command  php vendor/bin/pest tests/Integration/WxrMaterializerDuplicateDispositionTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('wxr_dispose_', true);
    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * The posts of the two sets, and one post outside every set, as `[id, slug, date]`.
 *
 * @return list<array{0: int, 1: string, 2: string}>
 */
function dispositionPosts(): array
{
    return [
        [12240, 'former-student-delivers-inaugural-yasargil-lecture-at-uams', '2008-10-06'],
        [16831, 'formerstudentdeliversinauguralyasargillectureatuams', '2008-10-06'],
        [12287, 'researcher-receives-grant-to-study-brain-activity-of-addicts', '2008-10-08'],
        [12335, 'researcher-receives-grant-to-study-brain-activity-of-addicts-2', '2008-10-08'],
        [16828, 'researcherreceivesgranttostudybrainactivityofaddicts', '2008-10-08'],
        [501, 'a-post-in-no-set', '2026-01-02'],
    ];
}

/**
 * A post payload as the migration API serves it.
 *
 * @return array<string, mixed>
 */
function dispositionApiPost(int $id, string $slug, string $date, string $host): array
{
    return [
        'id' => $id,
        'type' => 'post',
        'slug' => $slug,
        'status' => 'publish',
        'title' => 'Post '.$id,
        'author' => ['id' => 2, 'login' => 'todd', 'display_name' => 'Todd Example'],
        'date_created' => $date.' 09:00:00',
        'date_created_gmt' => $date.' 14:00:00',
        'date_modified' => $date.' 09:00:00',
        'date_modified_gmt' => $date.' 14:00:00',
        'content' => '<p>The body of post '.$id.'.</p>',
        'excerpt' => '',
        'parent' => 0,
        'menu_order' => 0,
        'guid' => 'https://'.$host.'/?p='.$id,
        'link' => 'https://'.$host.'/'.\str_replace('-', '/', $date).'/'.$slug.'/',
        'is_sticky' => false,
        'ping_status' => 'closed',
        'comment_status' => 'closed',
        'post_password' => '',
        'terms' => [],
        'meta' => [],
    ];
}

/**
 * Fake a single-site migration API serving the given posts.
 *
 * @param  list<array{0: int, 1: string, 2: string}>  $posts  The posts, as `[id, slug, date]`
 */
function dispositionFakeApi(string $host, array $posts): void
{
    $baseBlogUrl = 'https://'.$host;
    $payloads = \array_map(fn (array $post): array => dispositionApiPost($post[0], $post[1], $post[2], $host), $posts);

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
        '*/posts/post*' => Http::response($payloads, 200, ['X-WP-Total' => (string) \count($payloads)]),
    ]);
}

/**
 * Materialize the faked site.
 *
 * @return list<string> The part paths
 */
function dispositionMaterialize(string $directory): array
{
    return (new WxrMaterializer(new Client('https://example.test', 'shhh'), $directory))->materialize(1);
}

/**
 * Each post in the given WXR files as the importer's parser reads it, in file order.
 *
 * @param  list<string>  $paths  WXR file paths
 * @return list<array{id: int, slug: string, title: string, date: string, content: string}>
 */
function dispositionParsedPosts(array $paths): array
{
    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $posts = [];

    foreach ($parser->getItems('post') as $item) {
        $wp = $item->children('wp', true);
        $posts[] = [
            'id' => (int) $wp->post_id,
            'slug' => (string) $wp->post_name,
            'title' => (string) $item->title,
            'date' => (string) $wp->post_date,
            'content' => (string) $item->children('content', true)->encoded,
        ];
    }

    return $posts;
}

/**
 * The same posts as they reach the importer from a cleaned export.
 *
 * Writes an export-shaped WXR holding the posts, then runs exactly what
 * `bin/wxr-cleanup --disposition=dispositions/news-uams-edu.json` runs: the disposition, then
 * `Transform::withDefaultRules()`.
 *
 * @param  list<array{0: int, 1: string, 2: string}>  $posts  The posts, as `[id, slug, date]`
 * @return list<array{id: int, slug: string, title: string, date: string, content: string}>
 */
function dispositionExportPosts(string $directory, array $posts): array
{
    $cdata = static fn (string $value): string => '<![CDATA['.$value.']]>';
    $items = '';

    foreach ($posts as [$id, $slug, $date]) {
        $items .= '<item><title>'.$cdata('Post '.$id).'</title>'
            .'<link>https://news.uams.edu/'.\str_replace('-', '/', $date).'/'.$slug.'/</link>'
            .'<dc:creator>'.$cdata('todd').'</dc:creator><guid>https://news.uams.edu/?p='.$id.'</guid>'
            .'<content:encoded>'.$cdata('<p>The body of post '.$id.'.</p>').'</content:encoded>'
            .'<excerpt:encoded>'.$cdata('').'</excerpt:encoded>'
            .'<wp:post_id>'.$id.'</wp:post_id><wp:post_date>'.$cdata($date.' 09:00:00').'</wp:post_date>'
            .'<wp:post_name>'.$cdata($slug).'</wp:post_name><wp:status>publish</wp:status>'
            .'<wp:post_type>post</wp:post_type></item>'."\n";
    }

    $export = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel><title>Site</title><link>https://news.uams.edu</link><wp:wxr_version>1.2</wp:wxr_version>'
        .'<wp:base_site_url>https://news.uams.edu</wp:base_site_url><wp:base_blog_url>https://news.uams.edu</wp:base_blog_url>'."\n"
        .$items.'</channel></rss>';

    $disposition = WxrMaterializer::dispositionFor(['base_blog_url' => 'https://news.uams.edu']);

    if (! $disposition instanceof DuplicateDisposition) {
        throw new RuntimeException('The cleaner ships no news.uams.edu disposition.');
    }

    $path = $directory.\DIRECTORY_SEPARATOR.'export-cleaned.xml';
    $applied = (new ApplyDuplicateDisposition($disposition))->apply($export);
    \file_put_contents($path, Transform::withDefaultRules()->apply($applied['wxr']));

    try {
        return dispositionParsedPosts([$path]);
    } finally {
        \unlink($path);
    }
}

/**
 * The run's manifest, decoded.
 *
 * @return array<array-key, mixed>
 */
function dispositionManifest(string $directory): array
{
    $manifest = \json_decode((string) \file_get_contents($directory.\DIRECTORY_SEPARATOR.'wxr.manifest.json'), true);

    if (! \is_array($manifest)) {
        throw new RuntimeException('The manifest is not a JSON object.');
    }

    return $manifest;
}

/**
 * The post ids the run's manifest records as dropped by the disposition.
 *
 * @return list<int>
 */
function dispositionDroppedIds(string $directory): array
{
    $record = dispositionManifest($directory)['duplicate_disposition'] ?? null;
    $dropped = \is_array($record) ? ($record['dropped'] ?? null) : null;

    if (! \is_array($dropped)) {
        throw new RuntimeException('The manifest records no dropped posts.');
    }

    return \array_values(\array_filter($dropped, \is_int(...)));
}

/**
 * The redirect pairs the cleaner ships for news.uams.edu, keyed by source URL.
 *
 * The file `content:generate-migration-redirects --dedup-redirects` reads, from the same
 * release of the cleaner as the disposition the run applied.
 *
 * @return array<string, array<array-key, mixed>>
 */
function dispositionShippedPairs(): array
{
    $file = \dirname((string) (new ReflectionClass(DuplicateDisposition::class))->getFileName(), 3)
        .\DIRECTORY_SEPARATOR.'dispositions'.\DIRECTORY_SEPARATOR.'news-uams-edu.redirects.json';
    $rows = \json_decode((string) \file_get_contents($file), true);

    if (! \is_array($rows) || $rows === []) {
        throw new RuntimeException('The cleaner ships no news.uams.edu redirect pairs.');
    }

    $bySource = [];

    foreach ($rows as $row) {
        if (\is_array($row) && \is_string($row['source'] ?? null)) {
            $bySource[$row['source']] = $row;
        }
    }

    return $bySource;
}

/**
 * The public permalink of each served post, keyed by post id.
 *
 * @return array<int, string>
 */
function dispositionPermalinks(): array
{
    $disposition = WxrMaterializer::dispositionFor(['base_blog_url' => 'https://news.uams.edu']);

    if (! $disposition instanceof DuplicateDisposition) {
        throw new RuntimeException('The cleaner ships no news.uams.edu disposition.');
    }

    $permalinks = [];

    foreach (dispositionPosts() as [$id, $slug, $date]) {
        $permalinks[$id] = $disposition->permalink($date, $slug);
    }

    return $permalinks;
}

it('imports one post per duplicate set from an API capture, exactly as from the cleaned export', function (): void {
    dispositionFakeApi('news.uams.edu', dispositionPosts());

    $fromApi = dispositionParsedPosts(dispositionMaterialize($this->workingDirectory));
    $fromExport = dispositionExportPosts($this->workingDirectory, dispositionPosts());

    // The same posts, field by field, from either source
    expect($fromApi)->toBe($fromExport);

    // And the disposition really ran, so the equality is not two undisposed copies agreeing:
    // each set's recorded canonical survives, at the canonical slug, and nothing else of it
    expect(\array_column($fromApi, 'slug', 'id'))->toBe([
        12240 => 'former-student-delivers-inaugural-yasargil-lecture-at-uams',
        12335 => 'researcher-receives-grant-to-study-brain-activity-of-addicts',
        501 => 'a-post-in-no-set',
    ]);
});

it('records the posts it dropped and renamed in the manifest', function (): void {
    dispositionFakeApi('news.uams.edu', dispositionPosts());

    dispositionMaterialize($this->workingDirectory);

    expect(dispositionManifest($this->workingDirectory)['duplicate_disposition'])->toBe([
        'file' => 'news-uams-edu.json',
        'sets' => \count(WxrMaterializer::dispositionFor(['base_blog_url' => 'https://news.uams.edu'])->sets ?? []),
        'dropped' => [16831, 12287, 16828],
        'renamed' => [12335],
    ]);
});

it('leaves every dropped post a redirect pair the cleaner ships for the WXR path', function (): void {
    dispositionFakeApi('news.uams.edu', dispositionPosts());

    dispositionMaterialize($this->workingDirectory);
    $droppedIds = dispositionDroppedIds($this->workingDirectory);
    $bySource = dispositionShippedPairs();
    $permalinks = dispositionPermalinks();

    expect($droppedIds)->toHaveCount(3);

    // Each dropped post's own URL redirects to its kept post's canonical URL, or already is it:
    // 12287 held the canonical slug, which the renamed 12335 now answers at
    $canonical = [
        16831 => 'https://news.uams.edu/2008/10/06/former-student-delivers-inaugural-yasargil-lecture-at-uams/',
        12287 => 'https://news.uams.edu/2008/10/08/researcher-receives-grant-to-study-brain-activity-of-addicts/',
        16828 => 'https://news.uams.edu/2008/10/08/researcher-receives-grant-to-study-brain-activity-of-addicts/',
    ];

    foreach ($droppedIds as $dropped) {
        $source = $permalinks[$dropped];

        if ($source === $canonical[$dropped]) {
            expect($bySource)->not->toHaveKey($source);

            continue;
        }

        expect($bySource[$source] ?? null)->toMatchArray([
            'target' => $canonical[$dropped],
            'response' => '301',
            'source_wordpress_post_id' => $dropped,
        ]);
    }

    // And the renamed post's old URL follows it
    expect($bySource[$permalinks[12335]] ?? null)->toMatchArray([
        'target' => $canonical[12287],
        'target_wordpress_post_id' => 12335,
    ]);
});

it('drops a set member whose kept post this run did not read', function (): void {
    // An incremental run that read only the duplicate still leaves it out
    dispositionFakeApi('news.uams.edu', [[16831, 'formerstudentdeliversinauguralyasargillectureatuams', '2008-10-06']]);

    expect(dispositionParsedPosts(dispositionMaterialize($this->workingDirectory)))->toBe([]);
});

it('leaves another site\'s posts alone, even with the same ids and slugs', function (): void {
    config(['wordpress-import.wxr_cleanup.sites' => ['news.uams.edu', 'health.uams.edu']]);
    dispositionFakeApi('health.uams.edu', dispositionPosts());

    $posts = dispositionParsedPosts(dispositionMaterialize($this->workingDirectory));

    expect(\array_column($posts, 'id'))->toBe(\array_column(dispositionPosts(), 0))
        ->and(dispositionManifest($this->workingDirectory)['duplicate_disposition'])->toBeNull();
});

it('applies no disposition to a site the cleaner is not configured for', function (): void {
    // The disposition rides on the cleaning, as `--disposition` rides on the CLI
    config(['wordpress-import.wxr_cleanup.sites' => []]);
    dispositionFakeApi('news.uams.edu', dispositionPosts());

    $posts = dispositionParsedPosts(dispositionMaterialize($this->workingDirectory));

    expect(\array_column($posts, 'id'))->toBe(\array_column(dispositionPosts(), 0));
});

it('refuses a post whose slug the disposition did not record, and leaves the run unfinished', function (): void {
    // 16831 edited since the disposition was built: dropping it might drop a post nobody meant
    dispositionFakeApi('news.uams.edu', [
        [12240, 'former-student-delivers-inaugural-yasargil-lecture-at-uams', '2008-10-06'],
        [16831, 'a-slug-edited-since', '2008-10-06'],
    ]);

    expect(fn () => dispositionMaterialize($this->workingDirectory))
        ->toThrow(MigrationApiException::class, 'Post 16831 has slug "a-slug-edited-since"');

    expect(dispositionManifest($this->workingDirectory)['status'])->toBe('in_progress');
});

it('finds the disposition by the host of the site\'s base_blog_url', function (array $channel, bool $found): void {
    expect(WxrMaterializer::dispositionFor($channel) instanceof DuplicateDisposition)->toBe($found);
})->with([
    'news.uams.edu' => [['base_blog_url' => 'https://news.uams.edu'], true],
    'the host in another case, with a path' => [['base_blog_url' => 'https://NEWS.uams.edu/archive/'], true],
    'a site with no disposition' => [['base_blog_url' => 'https://health.uams.edu'], false],
    'no base_blog_url' => [[], false],
    'a base_blog_url that is not a string' => [['base_blog_url' => 42], false],
    'a URL with no host' => [['base_blog_url' => 'news.uams.edu'], false],
]);

it('reads the disposition the cleaner ships for news.uams.edu', function (): void {
    $disposition = WxrMaterializer::dispositionFor(['base_blog_url' => 'https://news.uams.edu']);

    expect($disposition?->site)->toBe('news.uams.edu')
        ->and(\count($disposition->sets ?? []))->toBeGreaterThanOrEqual(140);
});
