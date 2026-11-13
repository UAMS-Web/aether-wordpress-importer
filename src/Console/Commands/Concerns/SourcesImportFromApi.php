<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Client as MigrationApiClient;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\ContentTypeReads;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\StdinKeyReader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader as MinervaKbSettingsLoader;

/**
 * Materializes an export from the migration API and reports what it could not serve.
 *
 * Part of the `statamic:import:wordpress` command, split out of
 * {@see ImportWordPress} so that each file's
 * mutation run fits the CI limit (#546). The methods run on the command and use its
 * properties and output.
 */
trait SourcesImportFromApi
{
    /**
     * Resolve and validate the --minerva-generation option (wordpress-importer-archive#1235).
     *
     * @return string|false|null The declared generation, null when none was given, or false
     *                           when the value is invalid and the error has been reported
     */
    private function resolveMinervaGeneration(): string|false|null
    {
        // Read the raw option value from the console input
        $generation = $this->option('minerva-generation');

        // Treat a missing or empty value as no declaration
        if ($generation === null || $generation === '') {
            return null;
        }

        // Accept only a generation the settings loader knows, compared case-insensitively
        $normalized = \is_string($generation) ? \strtolower($generation) : null;

        if ($normalized !== null && isset(MinervaKbSettingsLoader::DECLARABLE_GENERATIONS[$normalized])) {
            return $normalized;
        }

        // `%s` formats a scalar as the cast does, so the cast only types it
        // @pest-mutate-ignore: RemoveStringCast
        $this->error(\sprintf(
            "The --minerva-generation option must be one of: %s, got '%s'.",
            \implode(', ', \array_keys(MinervaKbSettingsLoader::DECLARABLE_GENERATIONS)),
            \is_scalar($generation) ? (string) $generation : \get_debug_type($generation)
        ));

        return false;
    }

    /**
     * Fetch content from the migration API and write it out as WXR parts.
     *
     * @return list<string>|null The materialized part paths, or null when the fetch failed
     */
    private function materializeFromApi(): ?array
    {
        // The file argument names the output directory here rather than an input file; with
        // none given the parts land in a unique temporary directory that is reported below.
        $destination = $this->argument('file');

        if (! \is_string($destination) || $destination === '') {
            // The extra entropy only makes a collision less likely; either name is unique.
            // @pest-mutate-ignore: TrueToFalse
            $destination = \sys_get_temp_dir()
                .\DIRECTORY_SEPARATOR
                .\uniqid('wordpress-import-', true);
        }

        // Create the directory unless it already exists
        if (! \is_dir($destination) && ! \mkdir($destination, 0o775, true)) {
            // A directory another process created between the two calls serves as well
            if (! \is_dir($destination)) {
                $this->error("Could not create the directory '{$destination}' for materialized parts.");

                return null;
            }
        }

        $site = $this->option('site');
        $modifiedAfter = $this->option('modified-after');

        // Reject a `--site` that is not a positive integer rather than discarding it. The
        // old `is_numeric($site) ? (int) $site : null` silently fell back to the API's
        // default site, so `--site=uams-health` — an operator reaching for the network
        // slug — imported an entirely different site's content and reported success (wordpress-importer-archive#680).
        // `--source` is already rejected this way a few lines above; there was no reason
        // for the two to differ.
        //
        // The pattern is deliberately narrower than `is_numeric`: that accepts `3.5`,
        // `0x1A`, `1e3` and leading `+`, each of which `(int)` would quietly reshape into a
        // site id nobody asked for. The API's own route declares `minimum: 1`.
        $siteId = null;

        if ($site !== null && $site !== '') {
            if (! \is_string($site) || \preg_match('/^[1-9][0-9]*$/', $site) !== 1) {
                // `%s` formats a scalar as the cast does, so the cast only types it
                // @pest-mutate-ignore: RemoveStringCast
                $this->error(\sprintf(
                    "The --site option must be a positive whole number (the network blog id), got '%s'.",
                    \is_scalar($site) ? (string) $site : \get_debug_type($site)
                ));

                return null;
            }

            $siteId = (int) $site;
        }

        // Resolve the declared MinervaKB generation before asking for the network, so a typo
        // is reported before anything is fetched (wordpress-importer-archive#1235)
        $minervaGeneration = $this->resolveMinervaGeneration();

        if ($minervaGeneration === false) {
            return null;
        }

        $client = $this->askForNetwork();

        if (! $client instanceof MigrationApiClient) {
            return null;
        }

        try {
            // Point the MinervaKB settings loader at the same API. Its plugin configuration
            // lives in a `wp_options` row rather than in the content, so materialization
            // cannot carry it — it is fetched directly (wordpress-importer-archive#586).
            $this->minervaKbSettingsLoader->setMigrationApiSource(
                $client,
                $siteId,
                $minervaGeneration
            );

            // Point the navigation selector at the same API (wordpress-importer-archive#1145). Menu-location
            // assignments live in a `theme_mods_*` option rather than in the content, so
            // materialization cannot carry them; without this the run stops to ask the
            // operator to read the WordPress menu-locations screen.
            $this->navigationMenuSelector->setMigrationApiSource(
                $client,
                $siteId
            );

            // Point the Gravity Forms seam at the same API (wordpress-importer-archive#809). Form definitions live in
            // plugin tables rather than in the content, so materialization cannot carry them
            // either — they are fetched directly, exactly as the MinervaKB settings row is.
            $this->gravityFormsLoader->setMigrationApiSource(
                $client,
                $siteId
            );

            // And the Ninja Forms seam (wordpress-importer-archive#810). Ninja alone prompts once per referenced form
            // ID on the file path, because a `.nff` carries no ID; the endpoint serves one
            // with every form, so configuring this source is what retires that loop.
            $this->ninjaFormsLoader->setMigrationApiSource(
                $client,
                $siteId
            );

            // And the Formidable seam (wordpress-importer-archive#811). Its endpoint serves a JSON shape the XML export
            // does not, so this is the one seam whose API path translates; without this line
            // the selector sees no source and prompts for an XML export on an API run.
            $this->formidableFormsLoader->setMigrationApiSource(
                $client,
                $siteId
            );

            $this->info('Reading the migration API...');

            // The destination is currently the only sign a run is alive, and with no
            // directory argument it is a `sys_get_temp_dir()` path the run does not print
            // until it finishes — so an operator has to glob for it. Say it up front.
            $this->line("Writing WXR parts to {$destination}");

            // Clear before reading rather than relying on materialize() to overwrite it, so a
            // read that throws partway cannot leave a previous run's scope in force (wordpress-importer-archive#1179).
            MaterializationScope::clear();

            $materializer = $this->makeMaterializer(
                $client->withProgressReporter(function (string $message): void {
                    $this->line($message);
                }),
                $destination
            );

            // `--content-type` narrows the READ as well as the import (wordpress-importer-archive#1089). Without it the
            // whole site is fetched to import one type, which measured at roughly 16,000
            // records read for 25 imported. The materializer reads what the type declares in
            // `ContentTypeReads` (#109), so this narrows the fetch without dropping what the
            // import depends on.
            $scopedContentType = $this->option('content-type');

            $parts = $materializer->materialize(
                $siteId,
                \is_string($modifiedAfter) && $modifiedAfter !== '' ? $modifiedAfter : null,
                [],
                \is_string($scopedContentType) && \trim($scopedContentType) !== ''
                    ? \trim($scopedContentType)
                    : null
            );

            // Say so at the point of discovery rather than only in the end-of-run summary.
            // The whole failure this guards is that nobody notices (wordpress-importer-archive#688).
            $this->reportUnservedContent($materializer->unservedContent());

            // And what the redirect plugins served, with what the redirect domains
            // collection cannot express, so nothing a plugin rule did is dropped unseen (wordpress-importer-archive#1307)
            $this->reportRedirectPluginRules($materializer->redirectPluginRules());
        } catch (MigrationApiException $migrationApiException) {
            // A migration API failure is reported as itself rather than as a parse error;
            // the two have entirely different fixes.
            $this->error("Could not read the migration API: {$migrationApiException->getMessage()}");

            return null;
        }

        $partCount = \count($parts);

        // Name the directory: these files are the evidence for what was imported, and an
        // operator diffing them against a real export is how wordpress-importer-archive#588 gets proved.
        $this->info("Materialized {$partCount} WXR part(s) into {$destination}");

        return $parts;
    }

    /**
     * Ask the operator which network to read, and for the key that authenticates there.
     *
     * **Neither value is configured, and neither is accepted as a command-line option.**
     * The estate is 26 separate multisite networks, and each issues its own key from its
     * own admin page, so storing them would put 26 live credentials on disk for a job that
     * reads one network at a time. Passing the key as an option would be worse than
     * storing it: it would land in shell history and in the process list, readable by any
     * other account on the machine, and it would outlive the run in both.
     *
     * **The two are asked together because they only work together.** The plugin stores
     * its key per network, so a key from one network and an address from another
     * authenticate against nothing. Asking in one act is what stops them drifting apart —
     * which is the failure `wordpress-importer-archive#982` was filed about, where the address lived in
     * configuration and was edited between runs.
     *
     * @return MigrationApiClient|null A client bound to the named network, or null when
     *                                 the run cannot be set up
     */
    private function askForNetwork(): ?MigrationApiClient
    {
        // The unattended route, taken only when the operator opts in. It is checked before
        // the terminal guard below precisely because having no terminal is its whole
        // purpose -- a run that reaches the guard has not asked for this path.
        if ($this->option('key-from-stdin') === true) {
            return $this->networkFromStdin();
        }

        // A run with no terminal cannot be asked, and must not guess. Reading an unanswered
        // prompt as an empty key would send no credential, draw the API's `401`, and report
        // an operator's setup mistake as an authentication failure against a network that
        // is reachable and correctly configured.
        if (! $this->input->isInteractive()) {
            $this->error(
                'Reading the migration API needs the network address and its API key, and '
                .'both are asked at the prompt rather than stored. Re-run without '
                .'--no-interaction, or supply them without a terminal: pass the address as '
                .'--network=https://... and the key on the first line of standard input '
                .'with --key-from-stdin.'
            );

            return null;
        }

        // Honour `--network` here too. It is documented as "supplying without a prompt what
        // the run would otherwise ask for", and reading it only on the `--key-from-stdin`
        // path made that false on the path an operator is most likely to take: the flag was
        // accepted, ignored, and the address asked for anyway (wordpress-importer-archive#1088).
        $option = $this->option('network');
        $supplied = \is_string($option) ? \trim($option) : '';

        if ($supplied !== '') {
            $baseUrl = $supplied;
        } else {
            $answer = $this->ask('Which network? Paste its WordPress address, including https://');
            $baseUrl = \is_string($answer) ? $answer : '';
        }

        // Echo it back before the key is typed, whether it was typed or passed. Nothing else
        // in the run names the network, and under a per-network key the errors are
        // asymmetric: a typo that resolves nowhere is refused, and so is one that reaches a
        // network this key does not open — but an address the operator did not mean, whose
        // key they then paste, reads as a completely normal run against the wrong site's
        // content. That reasoning is about what the operator sees before spending a
        // credential, so it does not weaken when the address came from a flag.
        $this->line("  Reading: {$baseUrl}");

        $pasted = $this->secret("Paste that network's migration API key");
        $key = \is_string($pasted) ? $pasted : '';

        try {
            return MigrationApiClient::forRun($baseUrl, $key)
                ->withRetryReporter(function (string $message): void {
                    $this->warn($message);
                });
        } catch (MigrationApiException $migrationApiException) {
            $this->error("Could not read the migration API: {$migrationApiException->getMessage()}");

            return null;
        }
    }

    /**
     * Build the client from `--network` and the first line of standard input.
     *
     * **The unattended counterpart to `askForNetwork()`, and it keeps that method's two
     * guarantees rather than trading them away.** The key still reaches no configuration
     * file, no command-line option and no environment variable: it is consumed once from a
     * stream, which is neither shell history nor the process list. And the address and the
     * key are still supplied in one act, so they cannot drift apart between runs — the
     * failure `wordpress-importer-archive#982` was filed about, where the address lived in configuration and was
     * edited independently of the key.
     *
     * **The address is an option and the key is not, because only one of them is a
     * secret.** An `https://` URL in a process list discloses which network is being read,
     * which the run prints on screen anyway; a key there would be readable by any other
     * account on the machine and would outlive the run.
     *
     * @return MigrationApiClient|null A client bound to the named network, or null when
     *                                 the run cannot be set up
     */
    private function networkFromStdin(): ?MigrationApiClient
    {
        $option = $this->option('network');
        $baseUrl = \is_string($option) ? $option : '';

        // Refuse the pair rather than the key alone. Reading a key that no address can be
        // paired with would spend the operator's credential on a request that was never
        // going to be made, and the remedy names the flag that is missing rather than
        // leaving them to infer it from a validation failure further down.
        if (\trim($baseUrl) === '') {
            $this->error(
                '--key-from-stdin needs the network it belongs to. Pass the address as '
                .'--network=https://..., since a key from one network authenticates against '
                .'no other.'
            );

            return null;
        }

        // Name the network before the key is read, for the same reason the prompt echoes it:
        // an address the operator did not mean, whose key is then supplied, reads as a
        // completely normal run against the wrong site's content.
        $this->line("  Reading: {$baseUrl}");

        try {
            $key = StdinKeyReader::read($this->apiKeyStream());

            return MigrationApiClient::forRun($baseUrl, $key)
                ->withRetryReporter(function (string $message): void {
                    $this->warn($message);
                });
        } catch (MigrationApiException $migrationApiException) {
            $this->error("Could not read the migration API: {$migrationApiException->getMessage()}");

            return null;
        }
    }

    /**
     * Build the materializer that writes the API's content out as WXR parts.
     *
     * A method of its own so a test can stand in for the reads it makes.
     *
     * @param  MigrationApiClient  $client  The client to read through
     * @param  string  $destination  The directory to write the parts into
     */
    protected function makeMaterializer(
        MigrationApiClient $client,
        string $destination
    ): WxrMaterializer {
        // Build the materializer over the client and the directory
        return new WxrMaterializer(
            $client,
            $destination
        );
    }

    /**
     * The stream the `--key-from-stdin` route reads the API key from: standard input.
     *
     * A method of its own so a test can supply a key without reading the process's own
     * standard input.
     *
     * @return resource The stream to read the key from
     */
    protected function apiKeyStream()
    {
        // Read the key from standard input
        return \STDIN;
    }

    /**
     * Tell the operator about content the API will not serve.
     *
     * This is a warning rather than a refusal, and the choice is deliberate. The failure
     * being guarded is *silence*, not proceeding: an operator who knows a plugin is inactive
     * on the source site may legitimately want the rest of it, and refusing would block a
     * migration that is doing exactly what was asked. Saying so loudly removes the defect;
     * stopping would only trade it for a different one.
     *
     * It matches how wordpress-importer-archive#658 reports form references the supplied export cannot satisfy.
     *
     * @param  list<array{kind: string, name: string, records: int}>  $unserved  Stranded content
     */
    private function reportUnservedContent(
        array $unserved
    ): void {
        if ($unserved === []) {
            return;
        }

        $total = \array_sum(\array_column($unserved, 'records'));

        $this->warn(\sprintf(
            '%d record(s) on this site will NOT be imported: the API does not serve them.',
            $total
        ));

        foreach ($unserved as $entry) {
            $this->warn(\sprintf(
                '      %-14s %-24s %d record(s)',
                $entry['kind'],
                $entry['name'],
                $entry['records']
            ));
        }

        // Naming the likely cause matters: "not exposed" alone sends someone to the API's
        // configuration, and the usual reason is a plugin that is not active on the site
        // being read, which is somewhere else entirely.
        $this->warn(
            '      Usually this means the plugin registering that type is not active on the '
            .'site being read. See docs/migration-api-contract.md.'
        );
    }

    /**
     * Report what each redirect plugin on a vanity domain served (wordpress-importer-archive#1307).
     *
     * One line per plugin with the number of rules it serves and the number written as
     * `redirect_rule` items, which is the count a domain's imported entries are checked
     * against. Then every behavior the redirect domains collection cannot express, with the
     * number of rules carrying it, including the reason a rule was not written at all.
     *
     * @param  list<array{host: string, plugin: string, rules: int, written: int, unsupported: array<string, int>}>  $plugins  What each plugin served
     */
    private function reportRedirectPluginRules(
        array $plugins
    ): void {
        foreach ($plugins as $plugin) {
            $this->info(\sprintf(
                '%s on %s: %d rule(s) served, %d written as redirect rules.',
                $plugin['plugin'],
                $plugin['host'],
                $plugin['rules'],
                $plugin['written']
            ));

            if ($plugin['unsupported'] === []) {
                continue;
            }

            $this->warn('      The redirect domains collection cannot express:');

            foreach ($plugin['unsupported'] as $feature => $rules) {
                $this->warn(\sprintf('      %-60s %d rule(s)', $feature, $rules));
            }
        }
    }
}
