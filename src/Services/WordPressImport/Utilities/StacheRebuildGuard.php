<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use ErrorException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Runs a Stache clear or warm in the middle of an import, so a transient
 * cache-write failure skips that rebuild instead of ending the run (#325).
 *
 * Clearing or warming a Stache store rewrites its cache files. On Windows a
 * write can be refused while another handle briefly holds the file, and PHP
 * reports it as a `file_put_contents(...): Failed to open stream` warning,
 * which Laravel turns into an `ErrorException`. The guard retries the
 * operation once after a short wait; if the retry fails the same way, it
 * records the skipped rebuild with `ImportWarningManager`, so the run's summary
 * names it, and lets the import carry on. Any other failure propagates.
 */
final class StacheRebuildGuard
{
    /**
     * The `ImportWarningManager` category a skipped rebuild is recorded under.
     */
    public const string WARNING_CATEGORY = 'stache';

    /**
     * The `ImportWarningManager` type a skipped rebuild is recorded under.
     */
    public const string WARNING_TYPE = 'skipped_rebuilds';

    /**
     * Microseconds to wait before the one retry, for the other handle to let go.
     */
    public const int RETRY_DELAY = 100000;

    /**
     * Run a Stache clear or warm, retrying once on a transient cache-write failure.
     *
     * @param  string  $location  Where in the import the rebuild runs, for the warning
     * @param  string  $store  The Stache store being rebuilt, for the warning
     * @param  callable(): mixed  $operation  The clear, warm or both
     * @return bool True when the operation completed, false when it was skipped
     *
     * @throws ErrorException When the operation fails other than by a transient cache write
     */
    public static function run(string $location, string $store, callable $operation): bool
    {
        // Try the operation once
        $failure = self::attempt($operation);
        if (! $failure instanceof ErrorException) {
            return true;
        }

        // Wait for the other handle to let go, then try once more
        Sleep::usleep(self::RETRY_DELAY);
        $failure = self::attempt($operation);
        if (! $failure instanceof ErrorException) {
            return true;
        }

        // Both attempts were refused: record the skipped rebuild and carry on
        $data = [
            'location' => $location,
            'store' => $store,
            'error' => $failure->getMessage(),
        ];
        ImportWarningManager::addWarning(self::WARNING_CATEGORY, self::WARNING_TYPE, $data);
        Log::warning('StacheRebuildGuard::run: Stache rebuild skipped after a refused cache write', $data);

        return false;
    }

    /**
     * The endings of a refused write, as PHP words them.
     *
     * `Permission denied` is the refusal recorded in wordpress-importer-archive#173.
     * `Resource temporarily unavailable` is how PHP 8.4 on Windows reports a file
     * another handle holds without sharing it (measured on PHP 8.4.27, Windows 11).
     */
    public const array REFUSALS = [
        'Permission denied',
        'Resource temporarily unavailable',
    ];

    /**
     * Whether a failure is the transient cache-write refusal this guard absorbs.
     *
     * @param  ErrorException  $exception  The failure
     * @return bool True for a write refused in one of the ways `REFUSALS` names
     */
    public static function isTransientWriteFailure(ErrorException $exception): bool
    {
        // Match any of the refusals, by its exact wording
        return \array_any(
            self::REFUSALS,
            static fn (string $refusal): bool => \str_contains($exception->getMessage(), $refusal)
        );
    }

    /**
     * Run the operation once, returning a transient write failure instead of throwing it.
     *
     * @param  callable(): mixed  $operation  The clear, warm or both
     * @return ErrorException|null The transient failure, or null when the operation completed
     *
     * @throws ErrorException When the operation fails other than by a transient cache write
     */
    private static function attempt(callable $operation): ?ErrorException
    {
        try {
            $operation();
        } catch (ErrorException $errorException) {
            if (! self::isTransientWriteFailure($errorException)) {
                throw $errorException;
            }

            return $errorException;
        }

        return null;
    }
}
