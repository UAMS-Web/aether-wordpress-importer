<?php

declare(strict_types=1);

/**
 * Tests an asset download into a real asset container, into folders that do not exist yet (#612).
 *
 * An imported attachment lands at a year and month path such as `news/2024/05/x.png`, and on a
 * fresh container none of those folders exist. `AssetDownloader::download()` hands the file to
 * Statamic's `Asset::upload()`, which writes it through the container's Flysystem disk.
 * `statamic/cms` `v6.42.0` stopped creating the directory before each write
 * (statamic/cms#15855), so these tests pin that the file and its `.meta` file still land at the
 * nested path. `AssetDownloaderPathsTest` covers the same method with a recording asset in
 * place of a real container, so it cannot see this.
 *
 * Hosts are public IP literals, so the egress guard allows them without a DNS lookup and the
 * suite stays offline.
 *
 * @command  php vendor/bin/pest tests/Integration/AssetDownloaderNestedUploadTest.php
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Statamic\Assets\AssetContainer as ConcreteAssetContainer;
use Statamic\Facades\AssetContainer;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetDownloader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\FailedAssetCollector;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * The root of the disk behind the test's asset container.
 */
function nestedUploadDiskRoot(): string
{
    return \str_replace('\\', '/', \sys_get_temp_dir()).'/wordpress-importer-nested-upload-'.\getmypid();
}

/**
 * A one-pixel PNG.
 */
function nestedUploadPng(): string
{
    return \base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

beforeEach(function (): void {
    Sleep::fake();
    FailedAssetCollector::clearFailedUrls();

    // A fresh local disk with nothing under it, and a container on it
    File::deleteDirectory(nestedUploadDiskRoot());
    File::ensureDirectoryExists(nestedUploadDiskRoot());
    config(['filesystems.disks.nested_upload' => ['driver' => 'local', 'root' => nestedUploadDiskRoot()]]);

    $container = AssetContainer::make('nested_upload');
    \assert($container instanceof ConcreteAssetContainer);
    $container->disk('nested_upload');
    $container->save();
});

afterEach(function (): void {
    FailedAssetCollector::clearFailedUrls();
    AssetContainer::find('nested_upload')?->delete();
    File::deleteDirectory(nestedUploadDiskRoot());
});

it('writes a downloaded file and its meta file into folders that did not exist', function (): void {
    Http::fake(['https://93.184.216.34/x.png' => Http::response(nestedUploadPng(), 200)]);
    $root = nestedUploadDiskRoot();

    // Nothing below the disk root exists before the download
    expect(\is_dir($root.'/news'))->toBeFalse();

    $saved = (new AssetDownloader)->download('https://93.184.216.34/x.png', 'news/2024/05/x.png', 'nested_upload', new BufferedOutput, 60, 1);

    expect($saved)->toBe('news/2024/05/x.png')
        ->and(\file_get_contents($root.'/news/2024/05/x.png'))->toBe(nestedUploadPng())
        ->and(\is_file($root.'/news/2024/05/.meta/x.png.yaml'))->toBeTrue()
        ->and(FailedAssetCollector::getFailedUrls())->toBe([]);
});

it('writes a second file beside the first in the folder the first created', function (): void {
    // A fresh response per request: one response's body can be read only once
    Http::fake(static fn () => Http::response(nestedUploadPng(), 200));
    $root = nestedUploadDiskRoot();
    $downloader = new AssetDownloader;

    $first = $downloader->download('https://93.184.216.34/a.png', 'news/2024/05/a.png', 'nested_upload', new BufferedOutput, 60, 1);
    $second = $downloader->download('https://93.184.216.34/b.png', 'news/2024/05/b.png', 'nested_upload', new BufferedOutput, 60, 1);

    expect([$first, $second])->toBe(['news/2024/05/a.png', 'news/2024/05/b.png'])
        ->and(\is_file($root.'/news/2024/05/a.png'))->toBeTrue()
        ->and(\is_file($root.'/news/2024/05/b.png'))->toBeTrue()
        ->and(\is_file($root.'/news/2024/05/.meta/b.png.yaml'))->toBeTrue();
});
