<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi;

use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Traits\WxrMaterializerAttachmentsTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Traits\WxrMaterializerUnservedContentTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Traits\WxrMaterializerXmlWriterTrait;
use XMLWriter;

/**
 * Writes migration API content out as WXR part files.
 *
 * **Why this materializes to disk rather than implementing the parser's interface.**
 * `XmlParser`'s seven public getters look like the seam, but they are a facade. Six entry
 * creators — `PostEntryCreator`, `PageEntryCreator`, `PeopleEntryCreator`, `KbEntryCreator`,
 * `TablePressEntryCreator` and `LogoListItemEntryCreator` — reach past the parser entirely,
 * opening their own stream over `getFilePaths()` and reading the files themselves.
 * Since `wordpress-importer-archive#662` they do that through `ContentStreamFactory::open()`, which raises
 * `EmptyContentSourceException` rather than short-circuiting — so a
 * source with no file paths imports zero posts and reports success.
 *
 * Producing real files makes every one of those bypasses work by construction rather than by
 * having found them all, and reuses the sanitizer, split-part ordering and memory-efficient
 * streaming that already exist. The rejected alternatives are recorded in `docs/DECISIONS.md`;
 * the interface extraction it deferred landed in wordpress-importer-archive#662.
 *
 * The payload shapes below are read from `UAMS-Web/uamswp-migration-api`'s `Content/*Payload`
 * classes, not inferred — `terms` arrives grouped by taxonomy, and `meta` maps each key to a
 * **list** of values, because `get_post_meta()` without a key returns every row.
 */
class WxrMaterializer
{
    /**
     * Reads a scoped run's attachments by what its items reference.
     */
    use WxrMaterializerAttachmentsTrait;

    /**
     * Records unserved content and reads the redirect plugin rules.
     */
    use WxrMaterializerUnservedContentTrait;

    /**
     * Writes the WXR part files.
     */
    use WxrMaterializerXmlWriterTrait;

    /**
     * The sites whose exports are cleaned before import, by `base_blog_url` host (wordpress-importer-archive#1303).
     *
     * @var list<string>
     */
    // PCOV records no line for a constant; each change to it, made by hand, fails a test.
    // @pest-mutate-ignore: RemoveArrayItem
    public const CLEANED_SITES = ['news.uams.edu'];

    /**
     * The WXR namespace declarations every part carries.
     *
     * @var array<string, string>
     */
    // PCOV records no line for a constant; each change to it, made by hand, fails a test.
    // @pest-mutate-ignore: RemoveArrayItem
    private const array NAMESPACES = [
        'xmlns:excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
        'xmlns:content' => 'http://purl.org/rss/1.0/modules/content/',
        'xmlns:wfw' => 'http://wellformedweb.org/CommentAPI/',
        'xmlns:dc' => 'http://purl.org/dc/elements/1.1/',
        'xmlns:wp' => 'http://wordpress.org/export/1.2/',
    ];

    /**
     * Characters XML 1.0 cannot represent, less tab, newline and carriage return.
     *
     * Matches `WxrSanitizer`'s own C0 pattern. Writing these would produce a document the
     * sanitizer has to repair on the way back in; not writing them is cheaper and honest.
     */
    private const string ILLEGAL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u';

    /**
     * The manifest a run writes beside its parts.
     *
     * Named so it sorts beside `wxr.000.xml` in a directory listing and cannot collide with
     * a part, whose name is always `wxr.%03d.xml`.
     */
    private const string MANIFEST_NAME = 'wxr.manifest.json';

    /**
     * The most ids or files one attachment request names.
     *
     * The API caps `file[]` at 100 paths (its D-085). `include[]` has no cap of its own, but
     * the same bound keeps each request to one page and its query string finite.
     */
    // PCOV records no line for a constant; each change to it, made by hand, fails a test.
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    private const int REFERENCE_CHUNK = 100;

    /**
     * The most encoded query-string bytes one attachment request's references take.
     *
     * Leaves room under an 8 KB request line for the method, the route and the other
     * parameters. See `referenceChunks()`.
     */
    // PCOV records no line for a constant; each change to it, made by hand, fails a test.
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    private const int REFERENCE_QUERY_BYTES = 6000;

    /**
     * How many unresolved references the manifest lists by name, beside their counts.
     */
    // PCOV records no line for a constant; each change to it, made by hand, fails a test.
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    private const int REPORTED_EXAMPLES = 20;

    /**
     * Content that exists on the site but the API will not serve.
     *
     * @var list<array{kind: string, name: string, records: int}>
     */
    private array $unserved = [];

    /**
     * How a scoped run read its attachments, or null for an unscoped run (wordpress-importer-archive#1175).
     *
     * @var array{resolution: string, reason: string, ids_requested: int, paths_requested: int, records: int, unresolved_image_count: int, unresolved_images: list<string>, unresolved_link_count: int, unresolved_links: list<string>, unserved_ids: list<int>}|null
     */
    private ?array $attachmentResolution = null;

    /**
     * What this run read from each redirect plugin a vanity domain runs (wordpress-importer-archive#1307).
     *
     * One entry per plugin served on the site: how many rules the plugin serves, how many
     * were written as `redirect_rule` items, and what the rules carry that the redirect
     * domains collection cannot, each counted by the rules it affects.
     *
     * @var list<array{host: string, plugin: string, rules: int, written: int, unsupported: array<string, int>}>
     */
    private array $redirectPluginRules = [];

    /**
     * Create a materializer.
     *
     * @param  Client  $client  The migration API client
     * @param  string  $outputDirectory  Directory the part files are written to
     * @param  int  $itemsPerPart  Items per part before rotating to a new file
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $outputDirectory,
        private readonly int $itemsPerPart = 2000,
    ) {}

    /**
     * Fetch a site's content and write it as WXR part files.
     *
     * @param  int|null  $site  The network site id to read, when scoping is needed
     * @param  string|null  $modifiedAfter  Restrict to posts modified after this timestamp
     * @param  list<string>  $consumedOptions  Option names this run has already read, so a
     *                                         row that was consumed is not reported as stranded
     * @param  string|null  $contentType  The one post type this run imports, which narrows the
     *                                    read to it, its companions and their taxonomies. Null
     *                                    reads the whole site, exactly as before (wordpress-importer-archive#1089)
     * @return list<string> The written part paths, in ascending part order
     *
     * @throws MigrationApiException When the API fails or nothing could be written
     */
    public function materialize(
        ?int $site = null,
        ?string $modifiedAfter = null,
        array $consumedOptions = [],
        ?string $contentType = null
    ): array {
        $contentType = $contentType !== null && \trim($contentType) !== '' ? \trim($contentType) : null;
        $this->attachmentResolution = null;
        $this->redirectPluginRules = [];

        // Refuse an output directory that does not exist rather than resolving a relative
        // path against the current working directory, where the parts are indistinguishable
        // from repository files. This guards the *caller*; it does not stop a mutation run
        // from scattering parts, because those mutants corrupt `partPath()` rather than this
        // property — the `.gitignore` rule is what makes that output uncommittable.
        // `is_dir()` is false for an empty or blank path, so it covers that case too
        if (! \is_dir($this->outputDirectory)) {
            throw new MigrationApiException(\sprintf(
                'The output directory "%s" does not exist. Refusing to scatter materialized '
                .'parts into the working directory.',
                $this->outputDirectory
            ));
        }

        // Record the parts already in the destination BEFORE writing anything. A run
        // overwrites `wxr.000.xml` onwards, so a shorter second run leaves the first run's
        // higher-numbered parts behind looking current — naming them here is what makes that
        // accumulation legible without deleting anything.
        $preExisting = $this->existingParts();

        // Mark the run in progress before the first part is written. An interrupted run
        // leaves this file saying `in_progress`, which is what distinguishes it from a
        // complete one without reading any WXR content.
        $this->writeManifest('in_progress', $site, $modifiedAfter, $preExisting, [], null, $contentType);

        // Read the channel metadata first; every part repeats it, so nothing can be written
        // until it is known.
        $channel = $this->channelFor($site);

        // Everything after this point is addressed to the target site's OWN REST root
        // rather than scoped with `?site=`. Measured against a live multisite: the two are
        // equivalent for data endpoints, and only the per-site root works for the
        // registration-derived ones — see `siteClientFor()` and wordpress-importer-archive#671.
        $client = $this->siteClientFor($channel, $site);

        $authors = $client->paginate('authors');
        $exposedPostTypes = $this->exposedPostTypes($client);

        // A vanity redirect domain is not a Statamic site, so its redirect rules declare fewer
        // reads than a site's do (#109).
        $baseBlogUrl = $channel['base_blog_url'] ?? null;
        $redirectDomain = \is_string($baseBlogUrl)
            && RedirectRuleGlobalCreator::redirectDomainHost($baseBlogUrl) !== null;

        // With no content type every exposed type is read, which is the behavior a full run
        // has always had. With one, only it and what it declares it reads besides itself.
        $companions = null;
        $linkTargets = null;
        $postTypes = $exposedPostTypes;

        if ($contentType !== null) {
            $companions = ContentTypeReads::companions($contentType, $redirectDomain);
            $linkTargets = ContentTypeReads::linkTargets($contentType, $redirectDomain);
            $postTypes = $this->scopedPostTypes($exposedPostTypes, $contentType, $companions, $linkTargets);
        }

        $terms = $this->termsByTaxonomy($client, $contentType === null ? null : $postTypes);

        // Compare against the FULL exposed list, not the scoped one. Scoping is a choice this
        // run made; a type it chose not to read is not content the API refuses to serve, and
        // reporting it as unserved would bury the real refusals under the run's own filter.
        $this->recordUnservedContent($client, $channel, $exposedPostTypes, $site, $consumedOptions);

        $paths = [];
        $writer = null;

        // The first item opens a part and starts the count, so its start is never read.
        // @pest-mutate-ignore: IncrementInteger, DecrementInteger
        $itemsInPart = 0;
        $itemsWritten = 0;

        // A scoped run reads attachments by reference (wordpress-importer-archive#1175), so it reads them LAST: the
        // references come from the items of every other type, which have to be read first.
        // An unscoped run reads every type in the API's order, exactly as before.
        // The manifest keeps the API's order, which is what `post_types` has always reported.
        $collector = null;
        $scannedTypes = [];
        $itemLinks = [];
        $itemSlugs = [];
        $readOrder = $postTypes;

        if ($contentType !== null) {
            // Post types are strings, so strict and loose comparison agree.
            // @pest-mutate-ignore: TrueToFalse
            $readOrder = \array_merge(
                \array_filter($postTypes, static fn (string $type): bool => $type !== 'attachment'),
                \in_array('attachment', $postTypes, true) ? ['attachment'] : []
            );

            $collector = new AttachmentReferenceCollector(\is_string($baseBlogUrl) ? $baseBlogUrl : '');

            // The items whose attachments get imported: the requested type and the companions
            // it pulls content from. A link target read only so links resolve is not
            // imported, so its media is not needed. Attachments are read last, after their
            // references are used, so scanning them as a companion changes nothing.
            $scannedTypes = \array_merge(
                [$contentType],
                $companions
            );
        }

        // Rotate to a new part once this one is full, so a multi-thousand-item export splits
        // the way a real one does rather than becoming one huge file.
        $write = function (array $post) use (&$writer, &$itemsInPart, &$itemsWritten, &$paths, $channel, $authors, $terms): void {
            if (! $writer instanceof XMLWriter || $itemsInPart >= $this->itemsPerPart) {
                if ($writer instanceof XMLWriter) {
                    $this->closePart($writer);
                }

                $paths[] = $this->partPath(\count($paths));
                $writer = $this->openPart(
                    $paths[\count($paths) - 1],
                    $channel,
                    $authors,
                    $terms
                );
                $itemsInPart = 0;
            }

            $this->writeItem($writer, $post);
            $itemsInPart++;
            $itemsWritten++;
        };

        // A vanity domain running Redirection or Pretty Links keeps its rules in the plugins'
        // own tables, so they are read from their endpoints and written as `redirect_rule`
        // items, which is the type the import reads a redirect domain's rules from (wordpress-importer-archive#1307).
        // Their attachment references are not collected: rules come only from a vanity domain,
        // where a run scoped to `redirect_rule` declares no companions and reads no
        // attachments, and an unscoped run reads every attachment anyway.
        foreach ($this->redirectPluginPosts($client, $channel, $contentType) as $post) {
            $write($post);
        }

        foreach ($readOrder as $postType) {
            $query = $modifiedAfter === null ? [] : ['modified_after' => $modifiedAfter];

            $records = $collector instanceof AttachmentReferenceCollector && $postType === 'attachment'
                ? $this->attachmentsByReference($client, $collector, $itemLinks, $itemSlugs, $query, $channel)
                : $client->paginate('posts/'.$postType, null, $query);

            foreach ($records as $post) {
                if (! \is_array($post)) {
                    continue;
                }

                // Every item's permalink is kept so a link can be checked against what was
                // read, and the items whose media is imported are scanned for references. An
                // unscoped run scans no type and never reads the links it would keep.
                // @pest-mutate-ignore: InstanceOfToTrue
                if ($collector instanceof AttachmentReferenceCollector) {
                    // Only the keys are read, through array_diff_key() and isset().
                    if (\is_string($post['link'] ?? null)) {
                        // @pest-mutate-ignore: TrueToFalse
                        $itemLinks[AttachmentReferenceCollector::normalizeLink($post['link'])] = true;
                    }

                    // A draft's link is `?page_id=N` rather than its path, and the importer
                    // still resolves a link to it by slug, so those slugs are kept as well.
                    // Only an unpublished item's: `LinkProcessor`'s structure match discards
                    // published candidates, so a published post sharing a slug with an
                    // attachment would otherwise hide a link to that attachment's page.
                    // A normalized link never ends in an empty segment, so an empty slug
                    // matches nothing and needs no check of its own.
                    if (
                        \is_string($post['slug'] ?? null)
                        && ($post['status'] ?? null) !== 'publish'
                    ) {
                        // @pest-mutate-ignore: TrueToFalse
                        $itemSlugs[\strtolower(\rawurldecode($post['slug']))] = true;
                    }

                    // Post types are strings, so strict and loose comparison agree.
                    // @pest-mutate-ignore: TrueToFalse
                    if (\in_array($postType, $scannedTypes, true)) {
                        $collector->collect($post);
                    }
                }

                $write($post);
            }
        }

        // A site with no items still needs a part. `getFilePaths()` would otherwise
        // return `[]`, and since wordpress-importer-archive#662 every creator opens through
        // `ContentStreamFactory::open()`, which raises on a source serving nothing —
        // so an empty materialization would abort the import rather than import an
        // honest zero. Before wordpress-importer-archive#662 the same case short-circuited to zero silently;
        // either way this guard is what keeps a legitimately empty site working.
        if (! $writer instanceof XMLWriter) {
            $paths[] = $this->partPath(0);
            $writer = $this->openPart($paths[0], $channel, $authors, $terms);
        }

        $this->closePart($writer);

        // A site whose export is cleaned before import gets the same cleaning here, so its
        // API-sourced posts reach the parser exactly as its cleaned export would (wordpress-importer-archive#1303). Only
        // listed sites: the cleaner strips every HTML comment, including the Gutenberg block
        // delimiters other sites' content is parsed from. It runs before the manifest says
        // `complete`, so an interrupted clean leaves the run reading unfinished.
        if (self::cleansSite($channel)) {
            $this->cleanParts($paths);
        }

        // Mark the run complete only once every part is closed. Until this line runs the
        // manifest still reads `in_progress`, so a crash or an interrupt anywhere above
        // leaves the artifact describing itself as unfinished.
        $this->writeManifest(
            'complete',
            $site,
            $modifiedAfter,
            $preExisting,
            $paths,
            $itemsWritten,
            $contentType,
            $contentType === null ? null : $postTypes,
            $linkTargets
        );

        // Tell the readers that look items up in this export what it covers, so a miss caused
        // by the scope is reported rather than read as a broken link (wordpress-importer-archive#1179). A whole-site
        // read records null, which is also what clears a previous run's scope.
        MaterializationScope::set($contentType === null ? null : $postTypes, $redirectDomain);

        return $paths;
    }

    /**
     * The post types a run scoped to one content type reads.
     *
     * The requested type, then the companions and link targets it declares in
     * `ContentTypeReads`, each kept only where the API exposes it, in the order the API lists
     * them.
     * Requesting an unexposed type 404s on every page, so a companion the site does not serve
     * is simply not read, exactly as a full run would not read it.
     *
     * @param  list<string>  $exposed  Every post type the API exposes
     * @param  string  $contentType  The requested content type
     * @param  list<string>  $companions  The companion post types the type declares
     * @param  list<string>  $linkTargets  The link-target post types the type declares
     * @return list<string> The post types to read
     */
    private function scopedPostTypes(
        array $exposed,
        string $contentType,
        array $companions,
        array $linkTargets
    ): array {
        $wanted = \array_merge(
            [$contentType],
            $companions,
            $linkTargets
        );

        // Post types are strings, so strict and loose comparison agree.
        // @pest-mutate-ignore: TrueToFalse
        return \array_values(\array_filter(
            $exposed,
            static fn (string $type): bool => \in_array($type, $wanted, true)
        ));
    }

    /**
     * The part files already present in the output directory.
     *
     * @return list<string> The part file names, sorted, without their directory
     */
    private function existingParts(): array
    {
        // Match the exact shape `partPath()` writes rather than anything `.xml`, so an
        // unrelated file in the destination is never reported as a stale part. `glob()`
        // answers sorted, and false only on an error.
        $matches = \glob($this->pathFor('wxr.[0-9][0-9][0-9].xml'));

        // @pest-mutate-ignore: FalseToTrue
        return \array_map(
            \basename(...),
            $matches === false ? [] : $matches
        );
    }

    /**
     * Write the run manifest, replacing any manifest already in the directory.
     *
     * @param  string  $status  Either `in_progress` or `complete`
     * @param  int|null  $site  The network site id this run was scoped to, if any
     * @param  string|null  $modifiedAfter  The modification cutoff this run was scoped to, if any
     * @param  list<string>  $preExisting  Part files present before this run started
     * @param  list<string>  $paths  The part paths this run has written so far
     * @param  int|null  $items  The number of items written, or null while the run is in flight
     * @param  string|null  $contentType  The content type this run was scoped to, if any
     * @param  list<string>|null  $postTypes  The post types a scoped run read, or null when
     *                                        the run was not scoped or has not resolved them yet
     * @param  list<string>|null  $linkTargets  The link targets a scoped run declared, or null
     *                                          when the run was not scoped or has not resolved them yet
     *
     * @throws MigrationApiException When the manifest cannot be written
     */
    private function writeManifest(
        string $status,
        ?int $site,
        ?string $modifiedAfter,
        array $preExisting,
        array $paths,
        ?int $items,
        ?string $contentType = null,
        ?array $postTypes = null,
        ?array $linkTargets = null
    ): void {
        $written = \array_map(
            \basename(...),
            $paths
        );

        // Parts left from an earlier run that this one did not rewrite. A reader diffing the
        // directory would otherwise treat these as part of the current artifact.
        $stale = $status === 'complete'
            ? \array_values(\array_diff($preExisting, $written))
            : [];

        $complete = $status === 'complete';

        $manifest = [
            'status' => $status,
            'scope' => [
                'site' => $site,
                'modified_after' => $modifiedAfter,
                'content_type' => $contentType,
                // What the artifact actually covers, stated rather than left to be inferred.
                // A scoped run's parts are not the site, and diffing them against a full
                // export under that assumption is the harm the label exists to prevent
                // (wordpress-importer-archive#1089). `site` means every exposed type was read.
                'coverage' => $contentType === null ? 'site' : 'content_type',
                'post_types' => $postTypes,
                // The link targets are a corpus measurement rather than a code dependency, so
                // they are named separately: a reader can then see which part of the closure
                // could go stale if the content changes (wordpress-importer-archive#1179). Each type
                // declares its own in `ContentTypeReads` (#109).
                'link_target_post_types' => $linkTargets,
                // Whether a scoped run's attachments are the ones its items reference or the
                // whole site's, and why (wordpress-importer-archive#1175). Null for an unscoped run, which reads them all.
                'attachments' => $this->attachmentResolution,
            ],
            // Null rather than an empty list while a run is in flight. Parts are written
            // after this file and never counted back into it, so `[]` here would read as
            // "no parts written" when the directory may already hold thousands — the same
            // conflation of silence with zero this repository guards everywhere else.
            'parts' => $complete ? $written : null,
            'items' => $items,
            'pre_existing_parts' => $preExisting,
            'stale_parts' => $stale,
        ];

        $encoded = \json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new MigrationApiException(
                'Could not encode the materialization manifest.'
            );
        }

        $path = $this->pathFor(self::MANIFEST_NAME);

        // An unwritable manifest leaves the artifact unmarked, which is the defect this
        // exists to close — so it raises rather than continuing silently.
        if (\file_put_contents($path, $encoded.\PHP_EOL) === false) {
            throw new MigrationApiException(\sprintf(
                'Could not write the materialization manifest to "%s".',
                $path
            ));
        }
    }

    /**
     * A client addressed to a site's own REST root.
     *
     * **Why not `?site=`.** The API switches sites on that parameter for data endpoints,
     * but refuses it for `post-types` and `taxonomies` with
     * `uamswp_migration_api_registration_is_not_site_scoped`: those are derived from
     * `post_type_exists()`, and registration is process-global, so they can only answer for
     * the site that booted the request.
     *
     * Measured against a live multisite rather than reasoned about (wordpress-importer-archive#671). For the same
     * site, `?site=2` and site 2's own root return identical results — 1 post either way
     * against site 1's 46, and 235 option rows either way against site 1's 1206 — while
     * `post-types?site=2` returns 400 and site 2's root returns 200. So the per-site root
     * is equivalent where `?site=` works and necessary where it does not, which is why
     * every call goes through it instead of branching per endpoint.
     *
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @param  int|null  $site  The requested site id, for the error message
     * @return Client A client for that site's root, or this one when no site was requested
     *
     * @throws MigrationApiException When the payload carries no usable `link`
     */
    private function siteClientFor(
        array $channel,
        ?int $site
    ): Client {
        // With no site requested the configured base URL is already the right root: the API
        // serves whichever site that URL belongs to.
        if ($site === null) {
            return $this->client;
        }

        $link = $channel['link'] ?? null;

        if (! \is_string($link) || \trim($link) === '') {
            // Falling back to `?site=` here would fail later with a 400 whose message is
            // about registration rather than about a missing link — a correct error
            // pointing at the wrong cause.
            throw new MigrationApiException(\sprintf(
                'The migration API reported site %d without a usable "link", so its own '
                .'REST root cannot be addressed. Site-scoped reads need that root because '
                .'the discovery endpoints refuse `?site=`.',
                $site
            ));
        }

        return $this->client->withBaseUrl($link);
    }

    /**
     * Read the channel metadata for a site.
     *
     * @param  int|null  $site  The network site id
     * @return array<array-key, mixed> The site's discovery payload
     *
     * @throws MigrationApiException When the site is not served by this API
     */
    private function channelFor(
        ?int $site
    ): array {
        $sites = $this->client->get('sites');

        foreach ($sites as $candidate) {
            if (! \is_array($candidate)) {
                continue;
            }

            // With no scoping the API serves the current site, so the first entry is it.
            if ($site === null) {
                return $candidate;
            }

            $candidateId = $candidate['id'] ?? null;

            if (\is_numeric($candidateId) && (int) $candidateId === $site) {
                return $candidate;
            }
        }

        throw new MigrationApiException(\sprintf(
            'The migration API serves no site with id %s. Refusing to materialize an export '
            .'with no channel metadata.',
            $site ?? '(unscoped)'
        ));
    }

    /**
     * The post types this API exposes, in the order it reports them.
     *
     * @param  Client  $client  A client addressed to that site's own REST root
     * @return list<string> The exposed post type names
     */
    private function exposedPostTypes(
        Client $client
    ): array {
        $types = [];

        // No `?site=` — this endpoint refuses it. The client is already pointed at the
        // right site's root.
        foreach ($client->get('post-types') as $type) {
            // `exposed` is the API's own allow-list decision; a type it will not serve must
            // not be requested, or every page of it 404s.
            if (
                \is_array($type)
                && ($type['exposed'] ?? false) === true
                && \is_string($type['name'] ?? null)
            ) {
                $types[] = $type['name'];
            }
        }

        return $types;
    }

    /**
     * Every exposed taxonomy's terms, grouped by taxonomy.
     *
     * **A scoped run reads the taxonomies attached to what it reads, from `object_types`.**
     * `/taxonomies` carries the post types each taxonomy is attached to; `/post-types` carries
     * no taxonomy list at all, which is the correction recorded on wordpress-importer-archive#1089.
     *
     * **A taxonomy that declares no usable `object_types` is read, not skipped.** Absence says
     * nothing about what it is attached to, and skipping it would strip term assignments from
     * the imported entries with no error — the silent-loss shape a scoped read must not add.
     * Reading one taxonomy too many costs a request; reading one too few costs data.
     *
     * @param  Client  $client  A client addressed to that site's own REST root
     * @param  list<string>|null  $postTypes  The post types a scoped run reads, or null to read
     *                                        every exposed taxonomy
     * @return array<string, list<array<array-key, mixed>>> Terms keyed by taxonomy name
     */
    private function termsByTaxonomy(
        Client $client,
        ?array $postTypes = null
    ): array {
        $grouped = [];

        // As with post types, `?site=` is refused here.
        foreach ($client->get('taxonomies') as $taxonomy) {
            if (
                ! \is_array($taxonomy)
                || ($taxonomy['exposed'] ?? false) !== true
                || ! \is_string($taxonomy['name'] ?? null)
            ) {
                continue;
            }

            // Skip only a taxonomy PROVEN unrelated: one listing post types, none of them read.
            // Only string entries count as a declaration, so a list carrying nothing usable
            // reads as undeclared and the taxonomy is read rather than dropped.
            $objectTypes = $taxonomy['object_types'] ?? null;
            $declared = \is_array($objectTypes)
                ? \array_filter($objectTypes, \is_string(...))
                : [];

            if (
                $postTypes !== null
                && $declared !== []
                && \array_intersect($declared, $postTypes) === []
            ) {
                continue;
            }

            $terms = [];

            foreach ($client->paginate('terms/'.$taxonomy['name']) as $term) {
                if (\is_array($term)) {
                    $terms[] = $term;
                }
            }

            $grouped[$taxonomy['name']] = $terms;
        }

        return $grouped;
    }

    /**
     * The path of a part, numbered the way a split export numbers them.
     *
     * @param  int  $index  The zero-based part index
     * @return string The absolute part path
     */
    private function partPath(
        int $index
    ): string {
        return $this->pathFor(\sprintf('wxr.%03d.xml', $index));
    }

    /**
     * The path of a file in the output directory.
     *
     * @param  string  $name  The file name, or a glob pattern for one
     * @return string The path, joined with one separator however the directory ends
     */
    private function pathFor(
        string $name
    ): string {
        return \rtrim($this->outputDirectory, \DIRECTORY_SEPARATOR)
            .\DIRECTORY_SEPARATOR
            .$name;
    }

    /**
     * Whether a site's materialized parts run through the WXR cleaner (wordpress-importer-archive#1303).
     *
     * The decision follows the WXR workflow it mirrors: only sites whose exports are cleaned
     * before import are cleaned here, and today that is `news.uams.edu` alone. The list is
     * `wordpress-import.wxr_cleanup.sites`, matched against the host of the site's
     * `base_blog_url`, the field exports are grouped by (wordpress-importer-archive#787). Adding a site is a deliberate
     * configuration change, because the cleaner removes the block comments block-built content
     * depends on.
     *
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     */
    public static function cleansSite(array $channel): bool
    {
        $baseBlogUrl = $channel['base_blog_url'] ?? null;

        if (! \is_string($baseBlogUrl)) {
            return false;
        }

        // `parse_url()` answers false, never '', for a URL with no host
        $host = \parse_url($baseBlogUrl, \PHP_URL_HOST);

        if (! \is_string($host)) {
            return false;
        }

        $sites = config('wordpress-import.wxr_cleanup.sites', self::CLEANED_SITES);

        if (! \is_array($sites)) {
            return false;
        }

        return array_any($sites, fn ($site): bool => \is_string($site) && \strcasecmp($site, $host) === 0);
    }

    /**
     * Run the WXR cleaner's default pipeline over every written part, in place.
     *
     * Exports are cleaned by `uams-web/aether-wordpress-importer-cleanup`'s CLI before they are imported.
     * Content read from the migration API never passed through it, so the same post imported
     * differently depending on its source (wordpress-importer-archive#1303). Running the cleaner's own
     * `Transform::withDefaultRules()` on each part applies the same rules, in the same order, to
     * the same WXR elements the CLI cleans, without a second copy of the rule list.
     *
     * One part is read at a time, so memory is bounded by the part size, not the run.
     *
     * @param  list<string>  $paths  The written part paths
     *
     * @throws MigrationApiException When a part cannot be read, cleaned or written back
     */
    private function cleanParts(array $paths): void
    {
        $transform = Transform::withDefaultRules();

        foreach ($paths as $path) {
            // Read the part as written
            $raw = \file_get_contents($path);

            // The part was written a moment ago by this run, so it can always be read.
            // @pest-mutate-ignore: FalseToTrue
            if ($raw === false) {
                throw new MigrationApiException(\sprintf('Could not read "%s" to clean it.', $path));
            }

            $cleaned = $transform->apply($raw);

            // The cleaner suppresses libxml errors, so a part it could not parse comes back as an
            // empty document rather than an exception. Refuse to write that over the part: every
            // item that went in has to come out.
            $before = \substr_count($raw, '<item>');
            $after = \substr_count($cleaned, '<item>');

            if ($after !== $before) {
                throw new MigrationApiException(\sprintf(
                    'Cleaning "%s" returned %d of its %d items. The part was left as written.',
                    $path,
                    $after,
                    $before
                ));
            }

            // The part was just read from the same path, so it can always be written back.
            // @pest-mutate-ignore: FalseToTrue
            if (\file_put_contents($path, $cleaned) === false) {
                throw new MigrationApiException(\sprintf('Could not write the cleaned "%s".', $path));
            }

            // Release the part's text before reading the next one
            unset($raw, $cleaned);
        }
    }
}
