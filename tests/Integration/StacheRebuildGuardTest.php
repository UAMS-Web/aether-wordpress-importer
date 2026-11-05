<?php

declare(strict_types=1);

/**
 * Tests `StacheRebuildGuard`: a Stache clear or warm that completes is left alone, a
 * cache write refused once is retried after a short wait, one refused twice is
 * skipped and recorded for the run's summary, and any other failure propagates
 * (#325).
 *
 * Sleeps are faked, so the wait before the retry takes no time.
 *
 * @command  php vendor/bin/pest tests/Integration/StacheRebuildGuardTest.php
 */

use Carbon\CarbonInterval;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Stache;
use Statamic\Stache\Indexes\Index;
use Statamic\Stache\Stores\AggregateStore;
use Statamic\Stache\Stores\Store;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningDisplayService;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\StacheRebuildGuard;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    Sleep::fake();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

/**
 * The failure PHP raises when Windows refuses a Stache cache write (#173).
 */
function stacheGuardRefusal(): ErrorException
{
    return new ErrorException('file_put_contents(/app/storage/framework/cache/data/stache/keys/entries): Failed to open stream: Permission denied', 0, E_WARNING);
}

/**
 * An operation that throws each of the given failures in turn, then completes,
 * counting its calls.
 *
 * @param  ArrayObject<int, int>  $calls  The call counter, appended to on each call
 * @param  list<Throwable>  $failures  The failures to throw, in order
 * @return Closure(): void
 */
function stacheGuardOperation(ArrayObject $calls, array $failures): Closure
{
    return static function () use ($calls, &$failures): void {
        $calls->append(1);

        $failure = array_shift($failures);

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    };
}

/**
 * An array cache that refuses every Stache index write while `$refusing` is set,
 * as Windows refuses a write to a file another handle holds.
 */
final class StacheGuardRefusingStore extends ArrayStore
{
    /**
     * Whether index writes are refused.
     */
    public static bool $refusing = false;

    /**
     * {@inheritDoc}
     */
    public function forever($key, $value)
    {
        if (self::$refusing && \str_starts_with($key, 'stache::indexes::')) {
            throw stacheGuardRefusal();
        }

        return parent::forever($key, $value);
    }
}

/**
 * A store's warm state: whether it reuses its memoized entry list, and the list.
 *
 * @return array{mixed, mixed}
 */
function stacheGuardWarmState(mixed $store): array
{
    $read = Closure::bind(static function (Store $target): array {
        $items = $target->fileItems;

        return [$target->shouldCacheFileItems, $items instanceof Collection ? $items->all() : $items];
    }, null, Store::class);

    return $store instanceof Store ? $read($store) : [null, null];
}

/**
 * The Stache's entries store, after saving a collection for each handle.
 *
 * @param  list<string>  $collections  The collection handles
 */
function stacheGuardEntries(array $collections = ['pages']): AggregateStore
{
    foreach ($collections as $handle) {
        CollectionFacade::make($handle)->save();
    }

    $entries = Stache::store('entries');

    if (! $entries instanceof AggregateStore) {
        throw new LogicException('Expected the entries store to be an aggregate.');
    }

    return $entries;
}

/**
 * A child store of the entries store.
 */
function stacheGuardChild(AggregateStore $entries, string $handle): Store
{
    $child = $entries->store($handle);

    if (! $child instanceof Store) {
        throw new LogicException("Expected a '{$handle}' store.");
    }

    return $child;
}

/**
 * Save a page entry with the given id and title.
 */
function stacheGuardPage(string $id, string $title): void
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new LogicException('Expected an entry.');
    }

    $entry->collection('pages');
    $entry->id($id);
    $entry->slug($id);
    $entry->data(['title' => $title]);
    $entry->save();
}

/**
 * Set a store's warm state, as a warm in progress leaves it.
 *
 * @param  Collection<string, string>|null  $items  The memoized entry list
 */
function stacheGuardSetWarmState(mixed $store, bool $reuse, ?Collection $items): void
{
    $set = Closure::bind(static function (Store $target) use ($reuse, $items): void {
        $target->shouldCacheFileItems = $reuse;
        $target->fileItems = $items;
    }, null, Store::class);

    if ($store instanceof Store) {
        $set($store);
    }
}

/**
 * Collect the messages logged as warnings.
 *
 * @return ArrayObject<int, array{string, array<mixed>}>
 */
function stacheGuardLogged(): ArrayObject
{
    /** @var ArrayObject<int, array{string, array<mixed>}> $logged */
    $logged = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $message) use ($logged): void {
        if ($message->level === 'warning') {
            $logged->append([$message->message, $message->context]);
        }
    });

    return $logged;
}

it('runs a completing operation once, with no wait and no warning', function (): void {
    $logged = stacheGuardLogged();
    $calls = new ArrayObject;

    $result = StacheRebuildGuard::run('a site', 'entries', stacheGuardOperation($calls, []));

    expect($result)->toBeTrue()
        ->and($calls->count())->toBe(1)
        ->and(ImportWarningManager::getAllWarnings())->toBe([])
        ->and($logged->count())->toBe(0);
    Sleep::assertNeverSlept();
});

it('retries a refused cache write once, after a short wait, with no warning when the retry completes', function (): void {
    $logged = stacheGuardLogged();
    $calls = new ArrayObject;

    $result = StacheRebuildGuard::run('a site', 'entries', stacheGuardOperation($calls, [stacheGuardRefusal()]));

    expect($result)->toBeTrue()
        ->and($calls->count())->toBe(2)
        ->and(ImportWarningManager::getAllWarnings())->toBe([])
        ->and($logged->count())->toBe(0);
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(static fn (CarbonInterval $duration): bool => (int) $duration->totalMicroseconds === StacheRebuildGuard::RETRY_DELAY, 1);
});

it('skips a rebuild refused twice, recording the site, the store and the error', function (): void {
    $logged = stacheGuardLogged();
    $calls = new ArrayObject;
    $refusal = stacheGuardRefusal();

    $result = StacheRebuildGuard::run('before the navigation import', 'all', stacheGuardOperation($calls, [stacheGuardRefusal(), $refusal]));

    $data = [
        'location' => 'before the navigation import',
        'store' => 'all',
        'error' => $refusal->getMessage(),
    ];
    expect($result)->toBeFalse()
        ->and($calls->count())->toBe(2)
        ->and(ImportWarningManager::getAllWarnings())->toBe(['stache' => ['skipped_rebuilds' => [$data]]])
        ->and($logged->getArrayCopy())->toBe([['StacheRebuildGuard::run: Stache rebuild skipped after a refused cache write', $data]]);
    Sleep::assertSleptTimes(1);
});

it('shows a skipped rebuild in the run\'s warning summary', function (): void {
    StacheRebuildGuard::run('before the navigation import', 'all', stacheGuardOperation(new ArrayObject, [stacheGuardRefusal(), stacheGuardRefusal()]));
    $output = new BufferedOutput;

    ImportWarningDisplayService::displayAllWarnings($output);

    expect(str_replace(PHP_EOL, "\n", $output->fetch()))
        ->toContain("stache - skipped_rebuilds:\n")
        ->toContain('"location":"before the navigation import","store":"all"');
});

it('lets any other failure propagate, without a retry or a warning', function (Throwable $failure): void {
    $calls = new ArrayObject;
    $thrown = null;

    try {
        StacheRebuildGuard::run('a site', 'entries', stacheGuardOperation($calls, [$failure]));
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    expect($thrown)->toBe($failure)
        ->and($calls->count())->toBe(1)
        ->and(ImportWarningManager::getAllWarnings())->toBe([]);
    Sleep::assertNeverSlept();
})->with([
    'a warning that is not a refused write' => [new ErrorException('file_put_contents(): Only 0 of 12 bytes written, possibly out of free disk space', 0, E_WARNING)],
    'a refused write that is not a PHP warning' => [new RuntimeException('Permission denied')],
    'a type error' => [new TypeError('Statamic\Stache\Stores\Store::clear(): Return value must be of type void')],
]);

it('lets any other failure on the retry propagate, without a warning', function (): void {
    $calls = new ArrayObject;
    $other = new ErrorException('Undefined array key "entries"', 0, E_WARNING);
    $thrown = null;

    try {
        StacheRebuildGuard::run('a site', 'entries', stacheGuardOperation($calls, [stacheGuardRefusal(), $other]));
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    expect($thrown)->toBe($other)
        ->and($calls->count())->toBe(2)
        ->and(ImportWarningManager::getAllWarnings())->toBe([]);
});

it('resets a refused warm\'s memoized entry list after each refused attempt, so no later warm reuses it', function (int $refusals, bool $result): void {
    $entries = stacheGuardEntries();
    $pages = stacheGuardChild($entries, 'pages');
    $states = new ArrayObject;
    $failures = array_fill(0, $refusals, stacheGuardRefusal());

    // Leave the state a warm refused part-way leaves, then refuse; record what each attempt starts from
    $operation = static function () use ($pages, $states, &$failures): void {
        $states->append(stacheGuardWarmState($pages));
        stacheGuardSetWarmState($pages, true, collect(['stale-id' => 'stale']));

        $failure = array_shift($failures);

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        // A warm that finishes resets its own state, as Statamic's does
        stacheGuardSetWarmState($pages, false, null);
    };

    expect(StacheRebuildGuard::run('a site', 'entries', $operation, $entries))->toBe($result)
        ->and($states->getArrayCopy())->toBe([[false, null], [false, null]])
        ->and(stacheGuardWarmState($pages))->toBe([false, null]);
})->with([
    'refused once' => [1, true],
    'refused twice' => [2, false],
]);

it('lets the next warm after a skipped one see entries saved since (#325)', function (): void {
    // The Stache's cache refuses its index writes while the flag is up
    StacheGuardRefusingStore::$refusing = false;
    Cache::extend('stache-guard-refusing', static fn (): Repository => Cache::repository(new StacheGuardRefusingStore));
    config([
        'cache.stores.stache-guard-refusing' => ['driver' => 'stache-guard-refusing'],
        'statamic.stache.cache_store' => 'stache-guard-refusing',
    ]);
    $entries = stacheGuardEntries();
    stacheGuardPage('first', 'First');
    $pages = stacheGuardChild($entries, 'pages');

    // A warm refused twice is skipped
    StacheGuardRefusingStore::$refusing = true;
    $skipped = StacheRebuildGuard::run('a site', 'entries', static function () use ($entries): void {
        $entries->warm();
    }, $entries);
    StacheGuardRefusingStore::$refusing = false;

    // An entry saved afterwards must be in the indexes the next warm builds
    stacheGuardPage('second', 'Second');
    $pages->warm();
    $index = $pages->index('title');
    $items = $index instanceof Index ? $index->items() : null;
    $titles = $items instanceof Collection ? $items->sort()->values()->all() : null;

    expect($skipped)->toBeFalse()
        ->and($titles)->toBe(['First', 'Second']);
});

it('leaves the warm state alone when it is given no store', function (): void {
    $pages = stacheGuardChild(stacheGuardEntries(), 'pages');
    $operation = static function () use ($pages): void {
        stacheGuardSetWarmState($pages, true, collect(['stale-id' => 'stale']));

        throw stacheGuardRefusal();
    };

    StacheRebuildGuard::run('a site', 'entries', $operation);

    expect(stacheGuardWarmState($pages)[0])->toBeTrue();
});

it('resets the warm state of a plain store, and of every child of an aggregate', function (): void {
    $entries = stacheGuardEntries(['pages', 'posts']);
    $children = [stacheGuardChild($entries, 'pages'), stacheGuardChild($entries, 'posts')];

    foreach ([$entries, ...$children] as $store) {
        stacheGuardSetWarmState($store, true, collect(['stale-id' => 'stale']));
    }

    StacheRebuildGuard::resetWarmState($entries);
    StacheRebuildGuard::resetWarmState(null);

    expect(array_map(stacheGuardWarmState(...), [$entries, ...$children]))->toBe([[false, null], [false, null], [false, null]]);
});

it('knows a refused cache write by its message', function (string $message, bool $transient): void {
    expect(StacheRebuildGuard::isTransientWriteFailure(new ErrorException($message)))->toBe($transient);
})->with([
    'the refusal from #173' => ['file_put_contents(/app/storage/framework/cache/data/stache/keys/entries): Failed to open stream: Permission denied', true],
    'a file another handle holds, as PHP 8.4 on Windows reports it' => ['file_put_contents(C:\app\storage\framework\cache\data\stache\keys\entries): Failed to open stream: Resource temporarily unavailable', true],
    'a rename refused' => ['rename(/a,/b): Permission denied', true],
    'a disk full' => ['file_put_contents(): Only 0 of 12 bytes written, possibly out of free disk space', false],
    'a lower-case refusal' => ['permission denied', false],
]);
