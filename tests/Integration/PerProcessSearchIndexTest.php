<?php

declare(strict_types=1);

use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Search;
use Statamic\Search\Comb\Index as CombIndex;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins the per-process search index directory (#111).
 *
 * Every entry save updates the `cp` search index, and Statamic adds that index
 * whether or not `statamic.search.indexes` lists it. Its file defaulted to the
 * Testbench storage path, `vendor/orchestra/testbench-core/laravel/storage/statamic/search/cp.json`,
 * one file per checkout shared by every process. After a parallel mutation run left
 * that file undecodable, every later run in the tree failed 123 tests with a
 * `JsonException` from the save, which read as a regression in the import code.
 *
 * The sequential suite passes the same with and without the change, so these cases
 * are what make it visible to the gate: where the index points, where a save writes
 * it, and that an index which does not decode fails the save rather than passing.
 *
 * @command  php vendor/bin/pest tests/Integration/PerProcessSearchIndexTest.php
 */

/**
 * The shared Testbench search directory the per-process one replaces.
 *
 * @return string The absolute path of the shared directory
 */
function perProcessSearchSharedDirectory(): string
{
    return \dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/storage/statamic/search';
}

/**
 * Save one entry, which updates the `cp` index.
 *
 * @param  string  $slug  The slug of the entry to save
 * @return string The id of the saved entry
 */
function perProcessSearchSaveEntry(string $slug): string
{
    $collection = CollectionFacade::make('search_probe');
    $collection->save();

    $entry = Entry::make();

    if (! $entry instanceof StatamicEntry) {
        throw new RuntimeException('Entry::make() did not return a Statamic entry.');
    }

    $entry->collection('search_probe');
    $entry->slug($slug);
    $entry->data(['title' => 'Search Probe '.$slug]);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id)) {
        throw new RuntimeException('The saved entry has no id.');
    }

    return $id;
}

/**
 * The `cp` index's file, read from the resolved index rather than the config.
 *
 * @return string The absolute path of `cp.json`
 */
function perProcessSearchCpPath(): string
{
    $index = Search::index('cp');

    if (! $index instanceof CombIndex) {
        throw new RuntimeException('The cp index is not on the local driver.');
    }

    $path = $index->path();

    if (! \is_string($path)) {
        throw new RuntimeException('The cp index has no path.');
    }

    return $path;
}

it('resolves the cp index inside the per-process workbench', function (): void {
    // ---
    // Read from the resolved index, not the config key. `IndexManager` caches each
    // index once resolved, so an index resolved during boot would keep the shared
    // path while the config reported the new one.
    // ---
    $path = perProcessSearchCpPath();

    expect($path)->toEndWith('/workbench-'.\getmypid().'/search/cp.json');
    expect($path)->not->toContain('testbench-core');
});

it('writes the cp index into the per-process directory on save, and not the shared one', function (): void {
    $shared = perProcessSearchSharedDirectory().'/cp.json';
    $sharedBefore = \is_file($shared) ? \hash_file('sha256', $shared) : null;

    $id = perProcessSearchSaveEntry('lands_here');

    // The save reached the index at all: without this an empty directory passes
    $path = perProcessSearchCpPath();

    expect(\is_file($path))->toBeTrue();

    $decoded = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray()->toHaveKey('entry::'.$id);

    // The shared file is neither created nor changed by this process's save
    $sharedAfter = \is_file($shared) ? \hash_file('sha256', $shared) : null;

    expect($sharedAfter)->toBe($sharedBefore);
});

it('fails the save, rather than passing, when the index it reads does not decode', function (): void {
    // ---
    // The broken file a parallel run can leave, planted where this process reads.
    // A truncated object is what a torn or interrupted write produces.
    // ---
    $path = perProcessSearchCpPath();

    // ---
    // Planted only where this process reads. Without the guard, a regression that
    // put the index back on the shared path would have this test break the very file
    // every other process in the tree reads (measured: it did, with the fix reverted).
    // ---
    if (! \str_ends_with($path, '/workbench-'.\getmypid().'/search/cp.json')) {
        throw new RuntimeException('The cp index is not per-process; refusing to plant at '.$path);
    }

    \file_put_contents($path, '{"entry::torn":{"title":"Tor');

    expect(fn () => perProcessSearchSaveEntry('reads_a_broken_index'))
        ->toThrow(JsonException::class);
});
