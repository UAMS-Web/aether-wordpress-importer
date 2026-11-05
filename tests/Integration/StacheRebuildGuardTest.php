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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningDisplayService;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\StacheRebuildGuard;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

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

it('knows a refused cache write by its message', function (string $message, bool $transient): void {
    expect(StacheRebuildGuard::isTransientWriteFailure(new ErrorException($message)))->toBe($transient);
})->with([
    'the refusal from #173' => ['file_put_contents(/app/storage/framework/cache/data/stache/keys/entries): Failed to open stream: Permission denied', true],
    'a file another handle holds, as PHP 8.4 on Windows reports it' => ['file_put_contents(C:\app\storage\framework\cache\data\stache\keys\entries): Failed to open stream: Resource temporarily unavailable', true],
    'a rename refused' => ['rename(/a,/b): Permission denied', true],
    'a disk full' => ['file_put_contents(): Only 0 of 12 bytes written, possibly out of free disk space', false],
    'a lower-case refusal' => ['permission denied', false],
]);
