<?php

declare(strict_types=1);

/**
 * Tests that a link to a file keeps its original URL when the asset importer
 * links it rather than importing it, and is rewritten to the app's files path
 * when it imports it (#408).
 *
 * Under `SameSitePolicy::LINK_DIFFERENT_SITE`, `AssetImporter::importFile()`
 * returns a file on another site as its original URL. Each test uses a real
 * importer rather than a stub, because a stub returns an asset path for every
 * file, so it cannot return the link result. A file on this site is put in the
 * importer's file cache first, so it resolves to an asset path without a
 * download; stray requests fail the test.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkProcessorOffSiteFileLinkTest.php
 */

use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * The base blog URL the importer judges "this site" by.
 */
const OFFSITE_FILE_BLOG = 'https://example.org';

/**
 * A file on another site.
 */
const OFFSITE_FILE_URL = 'https://other.test/wp-content/uploads/report.pdf';

/**
 * A file on this site.
 */
const ONSITE_FILE_URL = 'https://example.org/wp-content/uploads/report.pdf';

beforeEach(function (): void {
    Http::preventStrayRequests();
    AssetImporter::clearFileCache();
    config(['app.url' => 'https://statamic.test', 'wordpress-import.upload_files' => true]);

    // Put this site's file in the cache, as an earlier import would leave it
    $key = (new ReflectionMethod(AssetImporter::class, 'generateFileCacheKey'))->invoke(null, ONSITE_FILE_URL, 'files');
    if (! \is_string($key)) {
        throw new LogicException('The file cache key is not a string.');
    }

    $cache = new ReflectionProperty(AssetImporter::class, 'fileCache');
    $cache->setValue(null, [$key => 'site_a/report.pdf']);
});

afterEach(function (): void {
    AssetImporter::clearFileCache();
});

it('keeps a link to a file on another site and points one on this site at its asset', function (string $url, string $expected): void {
    $html = (new LinkProcessor)->process('<a href="'.$url.'">r</a>', 'site_a', new AssetImporter(OFFSITE_FILE_BLOG), new BufferedOutput);

    expect($html)->toBe('<a href="'.$expected.'">r</a>');
})->with([
    'another site' => [OFFSITE_FILE_URL, OFFSITE_FILE_URL],
    'this site' => [ONSITE_FILE_URL, 'https://statamic.test/files/site_a/report.pdf'],
]);

it('keeps an attachment URL to a file on another site and converts one on this site', function (string $method, string $url, string $expected): void {
    $helper = new ReflectionMethod(LinkProcessor::class, $method);
    $target = $helper->isStatic() ? null : new LinkProcessor;

    expect($helper->invoke($target, $url, 'site_a', new AssetImporter(OFFSITE_FILE_BLOG), new BufferedOutput))->toBe($expected);
})->with([
    'deferred, another site' => ['processAttachmentUrlAsFileForDeferred', OFFSITE_FILE_URL, OFFSITE_FILE_URL],
    'deferred, this site' => ['processAttachmentUrlAsFileForDeferred', ONSITE_FILE_URL, 'https://statamic.test/files/site_a/report.pdf'],
    'immediate, another site' => ['processAttachmentUrlAsFile', OFFSITE_FILE_URL, OFFSITE_FILE_URL],
    'immediate, this site' => ['processAttachmentUrlAsFile', ONSITE_FILE_URL, 'https://statamic.test/files/site_a/report.pdf'],
]);
