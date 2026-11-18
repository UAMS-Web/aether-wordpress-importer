Written against v0.35.0.

One entry per symptom, as you meet it: the message or behavior, its cause, and the fix. Errors are logged to `storage/logs/laravel.log`; [Command reference](Command-reference.md#starting-from-a-clean-log) shows how to start a run with an empty log.

- [The command refuses the export file](#the-command-refuses-the-export-file)
- [Every migration API request answers 401](#every-migration-api-request-answers-401)
- [SSL certificate problem: self-signed certificate](#ssl-certificate-problem-self-signed-certificate)
- [A large import runs out of memory or time](#a-large-import-runs-out-of-memory-or-time)
- [An import does not show in the Control Panel](#an-import-does-not-show-in-the-control-panel)
- [Imported content looks wrong after a warning](#imported-content-looks-wrong-after-a-warning)
- [An unattended run imports one type and exits non-zero](#an-unattended-run-imports-one-type-and-exits-non-zero)
- [Fixed](#fixed)

## The command refuses the export file

The run stops before scanning.

**Cause:** the path does not exist, PHP cannot read it, or the file is not a WordPress WXR export.

**Fix:** check the path and its permissions, and export again from **Tools, Export** in WordPress.

## Every migration API request answers 401

An import with `--source=api`, or the check requests, answers `401` whatever you change.

**Cause:** the API returns the identical `401` for an unset constant, a wrong key and a correctly refused anonymous request, so the code alone does not say which. A key piped from PowerShell 5.1 arrives with an invisible three-byte byte-order mark and is always wrong.

**Fix:** run the three check requests in [Importing from the migration API](Importing-from-the-migration-API.md#4-check-it-worked-with-three-requests). Pipe the key with `printf '%s'` from bash, or a file redirect, not from PowerShell 5.1.

## SSL certificate problem: self-signed certificate

`curl` or the import stops on `SSL certificate problem: self-signed certificate`.

**Cause:** the WordPress site is a local `.test` host with a self-signed certificate.

**Fix:** for a local host you control, `curl -k` skips the check. Never skip it for a real host; see the warning in [Importing from the migration API](Importing-from-the-migration-API.md#4-check-it-worked-with-three-requests).

## A large import runs out of memory or time

The run stops with a PHP memory or execution-time error.

**Cause:** the export is large, and one process imports all of it.

**Fix:** raise PHP's memory and time limits for the run, for example `php -d memory_limit=2G artisan statamic:import:wordpress ...`.

## An import does not show in the Control Panel

The run completed, but the entries are missing or out of date in the Control Panel.

**Cause:** the Stache or a cache still holds the state from before the import, for example when the run stopped before finalization cleared them.

**Fix:** clear the caches and refresh the Stache (`php artisan cache:clear`, `php artisan statamic:stache:refresh`).

## Imported content looks wrong after a warning

Entries imported after a Classic, Row, Details or Freeform warning look different from the WordPress page.

**Cause:** those patterns have no exact equivalent, which is why the command warns about them.

**Fix:** review the entries named in the warnings and adjust them by hand, or rerun with `--unsupported-content=skip` to leave them out.

## An unattended run imports one type and exits non-zero

A run with no terminal imports the first supported content type, reports the rest as untouched, and exits non-zero.

**Cause:** without `--content-type` the run cannot ask which type to import.

**Fix:** pass `--content-type` with the type you want, once per type.

## Fixed

Problems fixed in a release stay here, with the release that fixed them, instead of being deleted.
