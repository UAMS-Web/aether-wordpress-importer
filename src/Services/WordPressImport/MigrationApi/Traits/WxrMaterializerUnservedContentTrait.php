<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Traits;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\RedirectPluginRules;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Records what a site holds that the migration API does not serve, for `WxrMaterializer`.
 *
 * Holds the unserved content and options report and reads the redirect plugins' rules
 * a vanity domain runs.
 */
trait WxrMaterializerUnservedContentTrait
{
    /**
     * Content that exists on the site but the API will not serve.
     *
     * Empty unless `materialize()` has run. Each entry names the kind, the name, and how
     * many records are stranded.
     *
     * @return list<array{kind: string, name: string, records: int}> The unserved content
     */
    public function unservedContent(): array
    {
        return $this->unserved;
    }

    /**
     * What this run read from the redirect plugins a vanity domain runs (wordpress-importer-archive#1307).
     *
     * Empty unless `materialize()` has read a listed redirect domain on which at least one of
     * the plugins is served.
     *
     * @return list<array{host: string, plugin: string, rules: int, written: int, unsupported: array<string, int>}>
     */
    public function redirectPluginRules(): array
    {
        return $this->redirectPluginRules;
    }

    /**
     * The Redirection and Pretty Links rules a vanity domain serves, as `redirect_rule` items (wordpress-importer-archive#1307).
     *
     * Read only for a site whose host is a listed redirect domain, because only that path
     * writes the rules to the redirect domains collection, and only by a run that imports
     * redirect rules. A plugin the site does not run answers with its own error code, which
     * is recorded as the plugin being absent; any other failure raises, because a run that
     * read some rules and not others would disable the rest at the next import (wordpress-importer-archive#1311).
     *
     * What each plugin served, what was written and what the collection cannot express are
     * recorded for `redirectPluginRules()` and for the end-of-import summary.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @param  string|null  $contentType  The one type this run imports, or null for every type
     * @return list<array<string, mixed>> The rules as post payloads
     *
     * @throws MigrationApiException When a plugin's rules cannot be read
     */
    private function redirectPluginPosts(
        Client $client,
        array $channel,
        ?string $contentType
    ): array {
        if ($contentType !== null && $contentType !== 'redirect_rule') {
            return [];
        }

        $baseBlogUrl = $channel['base_blog_url'] ?? null;
        $host = \is_string($baseBlogUrl) ? RedirectRuleGlobalCreator::redirectDomainHost($baseBlogUrl) : null;

        if ($host === null) {
            return [];
        }

        $posts = [];

        foreach (RedirectPluginRules::PLUGINS as $endpoint => $plugin) {
            try {
                $records = $client->paginate($endpoint);
            } catch (MigrationApiException $migrationApiException) {
                // An API code is a string or null, so strict and loose comparison agree.
                // @pest-mutate-ignore: TrueToFalse
                if (\in_array($migrationApiException->apiCode(), RedirectPluginRules::ABSENT_CODES, true)) {
                    continue;
                }

                throw $migrationApiException;
            }

            $written = 0;
            $unsupported = [];

            foreach ($records as $record) {
                if (! \is_array($record)) {
                    continue;
                }

                $converted = RedirectPluginRules::convert($endpoint, $record);

                foreach ($converted['unsupported'] as $feature) {
                    $unsupported[$feature] = ($unsupported[$feature] ?? 0) + 1;
                }

                if ($converted['post'] !== null) {
                    $posts[] = $converted['post'];
                    $written++;
                }
            }

            $entry = [
                'host' => $host,
                'plugin' => $plugin,
                'rules' => \count($records),
                'written' => $written,
                'unsupported' => $unsupported,
            ];

            $this->redirectPluginRules[] = $entry;

            // Recorded for the end-of-import summary, so the list survives a long run's scrollback
            foreach ($unsupported as $feature => $rules) {
                ImportWarningManager::addWarning('redirects', 'unsupported_plugin_features', [
                    'host' => $host,
                    'plugin' => $plugin,
                    'feature' => $feature,
                    'rules' => $rules,
                ]);
            }
        }

        return $posts;
    }

    /**
     * Record options the endpoint serves that this run did not read.
     *
     * The third content class, and the one WXR cannot carry at all: there is no
     * `<wp:option>` element, so every migration before the API dropped site settings in
     * silence. A run that carries no options must say so, or it reports exactly what a run
     * that carried them reports.
     *
     * Two figures, deliberately kept apart. **Not read** is what the endpoint served and
     * this run did not consume. **Withheld** is what the endpoint refused, which it reports
     * only as totals - `by_name` and `by_shape`, never which rows - so the two cannot be
     * reconciled into one number without inventing precision the payload does not have.
     * Merging them is the failure this records against.
     *
     * The entries are aggregate rather than one per row. The endpoint serves on the order of
     * a thousand options, and a per-row entry would flood `ImportWarningManager` with a
     * warning nobody reads.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  mixed  $counts  The site's `counts` block, which reports whether options exist at all
     * @param  ?int  $site  The site id, which `/options` requires
     * @param  list<string>  $consumedOptions  Option names this run actually read
     */
    private function recordUnservedOptions(
        Client $client,
        mixed $counts,
        ?int $site,
        array $consumedOptions
    ): void {
        // Ask discovery before asking the endpoint. `counts.options` is the site's own
        // whole-table figure, so a deployment that serves no options - or an API too old to
        // carry the route - costs no request and cannot abort the run. The unserved report
        // is a diagnostic; it must never be the reason an import fails.
        $rows = \is_array($counts) ? ($counts['options'] ?? null) : null;

        // A numeric string compares with 1 as the number it holds, so the cast only keeps the
        // type.
        // @pest-mutate-ignore: RemoveIntegerCast
        if (! \is_numeric($rows) || (int) $rows < 1) {
            return;
        }

        $payload = $client->get('options', $site);

        $served = $payload['options'] ?? null;
        $names = \is_array($served) ? \array_keys($served) : [];
        $unread = \array_diff($names, $consumedOptions);

        if ($unread !== []) {
            $this->unserved[] = [
                'kind' => 'option',
                'name' => 'not read by this run',
                'records' => \count($unread),
            ];
        }

        $denied = $payload['denied'] ?? null;
        // A value that is not a number reads as 0 whether or not the block is an array, and a
        // numeric string adds as the number it holds, so these only keep the type.
        // @pest-mutate-ignore: BooleanAndToBooleanOr, RemoveIntegerCast
        $byName = \is_array($denied) && \is_numeric($denied['by_name'] ?? null) ? (int) $denied['by_name'] : 0;

        // @pest-mutate-ignore: BooleanAndToBooleanOr, RemoveIntegerCast
        $byShape = \is_array($denied) && \is_numeric($denied['by_shape'] ?? null) ? (int) $denied['by_shape'] : 0;

        if ($byName + $byShape > 0) {
            $this->unserved[] = [
                'kind' => 'option',
                'name' => 'withheld by the endpoint denylist',
                'records' => $byName + $byShape,
            ];
        }
    }

    /**
     * Record content that exists but will not be served.
     *
     * **Why this exists.** `exposed` is `Config::postTypes()` filtered by
     * `post_type_exists()`, and registration is process-global — so it reflects which
     * plugins were active in the request that booted, not what content exists. A site whose
     * MinervaKB plugin is inactive reports `kb` unexposed while 28 `kb` rows sit in the
     * database, and a migration of it omits them with no error on either side. Measured
     * exactly that way in wordpress-importer-archive#684: 37 records across `kb`, `uamspeople` and `logo-list-item`.
     *
     * Nothing here is a defect to fix — the API is right to refuse a type it cannot
     * describe, and `exposedPostTypes()` is right to skip it, because requesting one makes
     * every page 404. What was missing is anyone saying so.
     *
     * **The two comparisons draw on different sources, deliberately.** Post types compare
     * against `counts.by_post_type` from `/sites`, which is a database count and therefore
     * sees types no longer registered. Taxonomies compare against the `terms` count on
     * `/taxonomies`, because `counts` carries no per-taxonomy breakdown — which means a
     * taxonomy whose plugin is inactive is not listed at all and stays invisible. That gap
     * is real and recorded on wordpress-importer-archive#688 rather than papered over.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @param  list<string>  $postTypes  The exposed post type names
     * @param  ?int  $site  The site id, which `/options` requires
     * @param  list<string>  $consumedOptions  Option names this run actually read
     */
    private function recordUnservedContent(
        Client $client,
        array $channel,
        array $postTypes,
        ?int $site = null,
        array $consumedOptions = []
    ): void {
        $counts = $channel['counts'] ?? null;
        $byPostType = \is_array($counts) ? ($counts['by_post_type'] ?? null) : null;

        if (\is_array($byPostType)) {
            foreach ($byPostType as $name => $records) {
                // A numeric string compares as the number it holds, and post types are strings,
                // so the cast and the strict flag only keep the type.
                // @pest-mutate-ignore: RemoveIntegerCast, TrueToFalse
                if (
                    ! \is_string($name)
                    || ! \is_numeric($records)
                    || (int) $records < 1
                    || \in_array($name, $postTypes, true)
                ) {
                    continue;
                }

                $this->unserved[] = ['kind' => 'post type', 'name' => $name, 'records' => (int) $records];
            }
        }

        foreach ($client->get('taxonomies') as $taxonomy) {
            // A numeric string compares with 1 as the number it holds, so the cast only keeps
            // the type.
            // @pest-mutate-ignore: RemoveIntegerCast
            if (
                ! \is_array($taxonomy)
                || ($taxonomy['exposed'] ?? false) === true
                || ! \is_string($taxonomy['name'] ?? null)
                || ! \is_numeric($taxonomy['terms'] ?? null)
                || (int) $taxonomy['terms'] < 1
            ) {
                continue;
            }

            $this->unserved[] = [
                'kind' => 'taxonomy',
                'name' => $taxonomy['name'],
                'records' => (int) $taxonomy['terms'],
            ];
        }

        $this->recordUnservedOptions($client, $counts, $site, $consumedOptions);

        // Also recorded for the end-of-import summary, so it survives a long run's scrollback.
        foreach ($this->unserved as $entry) {
            ImportWarningManager::addWarning('migration_api', 'unserved_content', $entry);
        }
    }
}
