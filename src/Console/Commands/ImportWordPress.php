<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Console\Commands;

use App\Services\BardContentSanitizer;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns\ChecksImportMemory;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns\ImportWordPressMappings;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns\PreparesImportSite;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns\ReportsImportResults;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns\ScansImportContent;
use UAMSWeb\Aether\WordPressImporter\Console\Commands\Concerns\SourcesImportFromApi;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\FailedAssetCollector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredProcessingCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\GlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\ContentTypeReads;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Navigation\NavigationMenuImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocLocationsParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LogoListParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\SplitExportPartLocator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\ContentTypeSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader as MinervaKbSettingsLoader;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\SiteSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\TaxonomyOverwriteBehaviorSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\ReusableBlocks\ReusableBlockCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\LogosItemCategoriesValidator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\AssetReferenceUpdates;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\DatalessEntrySweeper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\DroppedAnchorTracker;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningDisplayService;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SearchIndexUpdates;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\StacheRebuildGuard;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\VerificationWorkspace;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Imports WordPress content from an XML export file into Statamic.
 *
 * This command orchestrates the WordPress import process by parsing the XML file,
 * checking for supported content types, prompting the user for import options,
 * and creating Statamic entries or global sets. It scans selected content types
 * for Classic blocks, alerts the user if found, and offers an option to stop or
 * continue with a note. It delegates specific tasks to service classes for
 * separation of concerns.
 *
 * Default assumptions for content type mappings:
 *   - 'page' -> Pages Collection ('pages')
 *   - 'post' -> Posts Collection ('post')
 *   - 'uamspeople' -> People Collection ('people')
 *   - 'redirect_rule' -> Redirects Global Set ('redirects')
 *   - 'tablepress_table' -> Tables Collection ('tables')
 *   - 'kb' -> Knowledge Base Articles Collection ('knowledge_bases')
 *   - 'logo-list-item' -> Logo List Items Collection ('logo_list_items')
 *   - 'advert' -> Classifieds Collection ('classifieds')
 *
 * After selecting a content type, it prompts to confirm the default destination
 * if applicable, auto-setting subsequent responses. If declined or unmapped, it
 * falls back to the full workflow.
 *
 * Note: This command checks for supported content types before prompting for a
 * site, exiting early if none are found. It handles enabling the target
 * collection or global set (e.g., 'pages' or 'redirects') for the selected site
 * during setup, including user prompts. Post-parsing enabling of additional
 * collections/taxonomies (e.g., 'people', 'people_group', 'region') due to
 * shortcodes like 'show-people' or blocks like 'wp:acf/fad-providers' is managed
 * by SiteConfigurator.php. After each cycle, it prompts to import more content,
 * looping if desired.
 *
 * Usage:
 * php artisan cache:clear && php artisan statamic:import:wordpress /path/to/export.xml
 *
 * The command automatically analyzes content item sizes and suggests memory
 * limits for large individual posts/pages that might cause memory exhaustion.
 *
 * While debugging, you can also clear the contents of `laravel.log` as part of
 * the command with this usage:
 *   - Windows environment:
 *     php artisan cache:clear && echo. > storage\logs\laravel.log && php -d memory_limit=1024M artisan statamic:import:wordpress /path/to/export.xml
 *   - Mac environment:
 *     php artisan cache:clear && > storage/logs/laravel.log && php -d memory_limit=1024M artisan statamic:import:wordpress /path/to/export.xml
 */
class ImportWordPress extends Command
{
    /**
     * Include the ChecksImportMemory trait for the export parsing and the memory limit check (#546).
     */
    use ChecksImportMemory;

    /**
     * Include the HasPromptsSupport trait to provide platform detection for Laravel
     * Prompts support.
     */
    use HasPromptsSupport;

    /**
     * Include the HasQuestionHelper trait to provide centralized question handling.
     */
    use HasQuestionHelper;

    /**
     * Include the ImportWordPressMappings trait for the content type, taxonomy and handle mapping tables (#546).
     */
    use ImportWordPressMappings;

    /**
     * Include the PreparesImportSite trait for the site, navigation and department organization setup (#546).
     */
    use PreparesImportSite;

    /**
     * Include the ReportsImportResults trait for the Bard sanitization and the content size report (#546).
     */
    use ReportsImportResults;

    /**
     * Include the ScansImportContent trait for the scans for content the import cannot carry (#546).
     */
    use ScansImportContent;

    /**
     * Include the SourcesImportFromApi trait for the migration API materialization and its reports (#546).
     */
    use SourcesImportFromApi;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statamic:import:wordpress {file? : The path to the WordPress XML export file, or the directory to materialize into when --source=api}
        {--source=wxr : Where content comes from: wxr (a local export file) or api (the migration API)}
        {--site= : The network site id to read, when --source=api. A positive whole number; the run is refused if it is anything else}
        {--modified-after= : Only read posts modified after this timestamp, when --source=api. YYYY-MM-DD, "YYYY-MM-DD HH:MM" (GMT, seconds optional), or ISO 8601; anything else is refused by the API}
        {--network= : The network WordPress address to read, when --source=api. An absolute https:// URL, supplying without a prompt what the run would otherwise ask for. Required by --key-from-stdin}
        {--key-from-stdin : Read that network\'s migration API key from the first line of standard input rather than asking for it, so an API-sourced run needs no terminal. Requires --network}
        {--unsupported-content=abort : Non-interactive policy for content containing unsupported blocks (Classic, Freeform, Details) or KB entries without topics: abort|skip|import}
        {--content-type= : The single content type to import, for an unattended run. Without it a non-interactive run imports the first supported type and reports the rest as untouched, exiting non-zero}
        {--minerva-generation= : The MinervaKB generation this site runs, v1 or v2, when --source=api, as read from the network plugins page. Needed only where the migration API serves no plugin header naming the generation and both generations\' settings rows are served, which is otherwise refused. A declaration the header contradicts is refused}
        {--verify : Run the whole import against a temporary copy of the content and assets, print the same counts, and leave the application\'s own directories unchanged}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports content from a WordPress XML export into Statamic';

    /**
     * Default content type to destination mappings.
     *
     * @var array<string, array{type: string, handle: string, name: string}>
     */
    protected array $defaultMappings;

    /**
     * Create a new command instance with its dependencies.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  SiteSelector  $siteSelector  Service for selecting the target site
     * @param  ContentTypeSelector  $contentTypeSelector  Service for selecting the content type
     * @param  TaxonomyOverwriteBehaviorSelector  $taxonomyOverwriteBehaviorSelector  Service for selecting taxonomy overwrite behavior
     * @param  MinervaKbSettingsSelector  $minervaKbSettingsSelector  Service for selecting MinervaKB settings
     * @param  MinervaKbSettingsLoader  $minervaKbSettingsLoader  Loader whose source this command configures
     * @param  GravityFormsLoader  $gravityFormsLoader  Gravity Forms sourcing seam, whose source this command configures
     * @param  NinjaFormsLoader  $ninjaFormsLoader  Ninja Forms sourcing seam, whose source this command configures
     * @param  FormidableFormsLoader  $formidableFormsLoader  Formidable Forms sourcing seam, whose source this command configures
     * @param  EntryCreator  $entryCreator  Service for creating Statamic entries
     * @param  GlobalCreator  $globalCreator  Service for creating/modifying Statamic global sets
     * @param  TreeBuilder  $treeBuilder  Service for building collection trees
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  KbEntryCreator  $kbEntryCreator  The KbEntryCreator instance
     * @param  FormPluginCoordinator  $formPluginCoordinator  The FormPluginCoordinator instance
     * @param  SiteManager  $siteManager  The SiteManager instance
     * @param  NavigationMenuSelector  $navigationMenuSelector  The NavigationMenuSelector instance
     * @param  NavigationMenuImporter  $navigationMenuImporter  The NavigationMenuImporter instance
     * @param  ReusableBlockCoordinator  $reusableBlockCoordinator  The ReusableBlockCoordinator instance
     * @param  CollectionStructureManager  $collectionStructureManager  The CollectionStructureManager instance
     * @param  DeferredProcessingCoordinator  $deferredProcessingCoordinator  The DeferredProcessingCoordinator instance
     * @param  BardContentSanitizer  $bardContentSanitizer  The Bard content sanitizer service instance
     */
    public function __construct(
        protected XmlParser $xmlParser,
        protected SiteSelector $siteSelector,
        protected ContentTypeSelector $contentTypeSelector,
        protected TaxonomyOverwriteBehaviorSelector $taxonomyOverwriteBehaviorSelector,
        protected MinervaKbSettingsSelector $minervaKbSettingsSelector,
        protected MinervaKbSettingsLoader $minervaKbSettingsLoader,
        protected EntryCreator $entryCreator,
        protected GlobalCreator $globalCreator,
        protected TreeBuilder $treeBuilder,
        protected AssetImporter $assetImporter,
        protected KbEntryCreator $kbEntryCreator,
        protected FormPluginCoordinator $formPluginCoordinator,
        protected SiteManager $siteManager,
        protected NavigationMenuSelector $navigationMenuSelector,
        protected NavigationMenuImporter $navigationMenuImporter,
        protected ReusableBlockCoordinator $reusableBlockCoordinator,
        protected CollectionStructureManager $collectionStructureManager,
        protected DeferredProcessingCoordinator $deferredProcessingCoordinator,
        BardContentSanitizer $bardContentSanitizer,
        protected GravityFormsLoader $gravityFormsLoader,
        protected NinjaFormsLoader $ninjaFormsLoader,
        protected FormidableFormsLoader $formidableFormsLoader
    ) {
        parent::__construct();
        $this->bardContentSanitizer = $bardContentSanitizer;

        // Initialize default mappings from static method
        $this->defaultMappings = self::getDefaultMappings();

        // Set form plugin coordinator on EntryCreator for post-processing
        $this->entryCreator->setFormPluginCoordinator($this->formPluginCoordinator);

        // Set reusable block coordinator on EntryCreator for post-processing
        $this->entryCreator->setReusableBlockCoordinator($this->reusableBlockCoordinator);

        // Set deferred processing coordinator on EntryCreator for post-processing
        $this->entryCreator->setDeferredProcessingCoordinator(
            $this->deferredProcessingCoordinator
        );

        // Set site manager on dependent services
        $this->entryCreator->setSiteManager($this->siteManager);
    }

    /**
     * When this run began, as a Unix timestamp with microseconds.
     *
     * Bounds the dataless-entry sweep to files this run produced (wordpress-importer-archive#1160).
     */
    private float $runStartedAt = 0.0;

    /**
     * Whether this command is running an import, so a command that finishes inside it
     * hands Laravel Prompts back (#228).
     */
    private bool $importing = false;

    /**
     * Remove entry files this run materialized with no data in them, and report them.
     *
     * **The defect this answers wrote into a site the run never named** — 37 `knowledge_bases`
     * entries into a site with no content on disk, inside 70 milliseconds, each carrying an `id`
     * and a `blueprint` and nothing else. Nothing in the output, the summary or the log mentioned
     * them, and the host app rejects every one: saving a `knowledge_bases` entry with no Topic
     * parent throws from its own validation listener. So a run invented content that had never
     * existed, in a shape the app considers invalid.
     *
     * Because it writes into a site the run does not know about, the sweep cannot be scoped by
     * collection or site. It is scoped by **time** instead: only files written since this run
     * began. That bound is what keeps a delete from reaching content the run did not produce.
     *
     * The root comes from `ContentRoot`, never `base_path('content/…')` — the host serves a
     * disposable mirror under `APP_ENV=testing`, and a surface pointed at the wrong one deletes
     * content no run produced.
     */
    private function sweepDatalessEntries(): void
    {
        $removed = DatalessEntrySweeper::sweep(
            ContentRoot::collections(),
            $this->runStartedAt
        );

        if ($removed === []) {
            return;
        }

        foreach ($removed as $entry) {
            // Logged individually, because the collection, site, slug and id are what an operator
            // needs to tell an invented entry from one they were expecting.
            Log::warning(
                'ImportWordPress: removed a dataless entry file this run materialized.',
                $entry
            );

            ImportWarningManager::addWarning(
                'content',
                'dataless_entries_removed',
                $entry
            );
        }

        $this->warn(\sprintf(
            '  - Removed %d dataless entry file(s) this run materialized. See the warning summary.',
            \count($removed)
        ));
    }

    /**
     * Execute the console command.
     *
     * Thin wrapper that runs {@see self::runImport()} with two of Statamic's
     * per-save sweeps disabled for the duration of the import:
     *
     *   - {@see AssetReferenceUpdates}: the importer deletes the existing asset on
     *     each overwrite to force a fresh upload, which fires AssetDeleted and
     *     triggers a full scan of every entry, term, global, and user to null-out
     *     references. That sweep is unnecessary here (the importer rewrites asset
     *     references itself) and crashes on a single term with a null blueprint.
     *   - {@see SearchIndexUpdates}: every term/entry save fires Statamic's search
     *     `UpdateItemIndexes`, which rebuilds the `default` (all-content) index by
     *     scanning every searchable across all sites — ~12s per save. Disabling it
     *     for the run and rebuilding the indexes once at the end (see runImport())
     *     turns hundreds of full rebuilds into a single pass.
     *
     * Both restore their listeners afterwards — even if the import throws — so the
     * changes never leak into other commands sharing the process.
     *
     * @return int Exit code (0 for success, 1 for failure)
     */
    public function handle(): int
    {
        // Stamped before any work so the dataless-entry sweep can bound itself to files this
        // run produced. Anything older predates it and is not this run's to delete (wordpress-importer-archive#1160).
        $this->runStartedAt = \microtime(true);

        $run = fn (): int => AssetReferenceUpdates::withDisabled(
            fn (): int => SearchIndexUpdates::withDisabled(
                fn (): int => $this->runImport()
            )
        );

        return $this->isVerificationRun() ? $this->runInWorkspace($run) : $run();
    }

    /**
     * Whether this run leaves the application's own directories unchanged (#254).
     */
    private function isVerificationRun(): bool
    {
        return $this->option('verify') === true;
    }

    /**
     * Run the import against a temporary copy of what it writes, removing the copy however
     * the run ends (#254).
     *
     * @param  callable(): int  $run  The import
     * @return int The import's exit code, or 1 when the workspace cannot be set up
     */
    private function runInWorkspace(callable $run): int
    {
        try {
            $workspace = VerificationWorkspace::enter();
        } catch (RuntimeException $runtimeException) {
            $this->error("The verification run was refused: {$runtimeException->getMessage()} Nothing was imported.");

            return 1;
        }

        // The copies are newer than the old stamp, and the sweep bounds itself by it
        $this->runStartedAt = \microtime(true);

        $this->info("Verification run: writing to a temporary copy. This application's content and assets will not change.");

        try {
            return $run();
        } finally {
            if ($workspace->leave()) {
                $this->info('Verification run: the temporary copy was removed.');
            } else {
                $this->warn("Verification run: the temporary copy at '{$workspace->root()}' could not be removed. Delete it by hand.");
            }
        }
    }

    /**
     * Resolve and validate the --unsupported-content policy option.
     *
     * @return ?string The validated policy (abort|skip|import), or null when the supplied value is invalid
     */
    private function resolveUnsupportedContentPolicy(): ?string
    {
        // Read the raw option value from the console input
        $policy = $this->option('unsupported-content');

        // Treat a missing value as the default policy, as a bare --unsupported-content gives
        if ($policy === null) {
            return 'abort';
        }

        // Reject non-string values defensively. The console answers a string for an option
        // that takes a value, so this only types it.
        if (! is_string($policy)) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return null;
        }

        // Accept only the supported policy values. Every value compared is a string, so
        // a loose comparison accepts the same ones.
        // @pest-mutate-ignore: TrueToFalse
        return \in_array($policy, ['abort', 'skip', 'import'], true)
            ? $policy
            : null;
    }

    /**
     * Orchestrate the WordPress import process.
     *
     * Orchestrates the import process by:
     *   1. Validating the XML file path.
     *   2. Checking memory requirements and prompting user if needed.
     *   3. Parsing the XML to analyze content types.
     *   4. Checking for supported content types, exiting if none found.
     *   5. Prompting for site selection if supported content exists.
     *   6. Looping to prompt for content type, scanning for Classic blocks, and
     *      handling user choice.
     *   7. Confirming/enabling default destinations or falling back to full
     *      selection if applicable.
     *   8. Checking/enabling relevant taxonomies (e.g., people_group) for the site.
     *   9. Prompting for overwrite behaviors for the selected collection, assets,
     *      and tables (if TablePress content detected).
     *   10. Importing content and validating taxonomies.
     *   11. Asking to import more, repeating if yes.
     *   12. Clearing the Laravel cache and reporting completion when done.
     *
     * @return int Exit code (0 for success, 1 for failure)
     */
    private function runImport(): int
    {
        // ---
        // Resolve the content source (wordpress-importer-archive#587)
        // ---

        $source = $this->option('source');
        $source = \is_string($source) ? \strtolower($source) : 'wxr';

        if ($source !== 'wxr' && $source !== 'api') {
            $this->error("Unknown --source '{$source}'. Use 'wxr' or 'api'.");

            return 1;
        }

        // A file-sourced run reads MinervaKB settings from the file the operator picks, so a
        // declared generation would decide nothing there. Refused rather than ignored, so an
        // operator who passed it is not left believing it applied (wordpress-importer-archive#1235).
        $minervaGeneration = $this->option('minerva-generation');

        if ($source === 'wxr' && $minervaGeneration !== null && $minervaGeneration !== '') {
            $this->error('The --minerva-generation option applies only with --source=api.');

            return 1;
        }

        // The API source materializes WXR parts and rejoins the file flow below, so every
        // importer subsystem downstream reads real files exactly as it always has.
        if ($source === 'api') {
            $materialized = $this->materializeFromApi();

            if ($materialized === null) {
                return 1;
            }

            return $this->runImportForParts($materialized);
        }

        // Set the MinervaKB loader's source explicitly rather than relying on it being
        // unset: it is a container singleton, so a previous run in this process would
        // otherwise still be the source (wordpress-importer-archive#586).
        $this->minervaKbSettingsLoader->clearMigrationApiSource();
        $this->navigationMenuSelector->clearMigrationApiSource();
        $this->gravityFormsLoader->clearMigrationApiSource();
        $this->ninjaFormsLoader->clearMigrationApiSource();
        $this->formidableFormsLoader->clearMigrationApiSource();

        // A file-sourced export is whatever the operator exported, so no scope applies to it.
        // Cleared for the same reason as the seams above: it is process-global (wordpress-importer-archive#1179).
        MaterializationScope::clear();

        // Validate the XML file path
        $filePath = $this->argument('file');

        if (! is_string($filePath) || $filePath === '') {
            $this->error('A valid XML file path is required.');

            return 1;
        }

        // Check if the file does not exist
        if (! \file_exists($filePath)) {
            // Show error for missing file
            $this->error("The file '{$filePath}' does not exist.");

            // Exit with error status code
            return 1;
        }

        // ---
        // Resolve the split-export part-set (a plain single file has no siblings)
        // ---

        // Discover any numbered sibling parts of a split export from the given path
        $partSet = SplitExportPartLocator::locate($filePath);

        // Refuse to import a numbered sequence with a gap rather than a partial set
        if ($partSet->hasGap()) {
            // Format the missing part indices as .NNN.xml for a clear operator message
            $missingLabels = \implode(
                ', ',
                \array_map(
                    fn (int $index): string => sprintf('.%03d.xml', $index),
                    $partSet->missingParts
                )
            );

            // Show error naming the missing parts and abort the import
            $this->error("Split export is missing part(s): {$missingLabels}. Refusing to import a partial set — restore the missing part(s) and retry.");

            // Exit with error status code
            return 1;
        }

        // Resolve the ordered parts driving this import (one entry for a single file)
        $parts = $partSet->parts;

        // Inform the operator when a multi-part split export was detected
        if ($partSet->isSplit) {
            // Report how many parts will be imported together as one logical import
            $partCount = \count($parts);

            $this->info("Detected split export: importing {$partCount} parts as a single import.");
        }

        // Hand the resolved part-set to the shared import flow. The API source rejoins
        // here with materialized parts, so nothing below this point knows which it was.
        return $this->runImportForParts($parts);
    }

    /**
     * Run the import for an already-resolved part-set.
     *
     * Both sources converge here: a local WXR export supplies its own parts, and the
     * migration API supplies parts materialized from it. Everything downstream reads real
     * files, which is why no importer subsystem had to change (wordpress-importer-archive#587).
     *
     * Protected so a test can stand in for the import itself while it covers what
     * leads to it.
     *
     * Every Laravel command points the static Laravel Prompts configuration at itself when
     * it runs, and `Artisan::call()` does not point it back. A command called that way
     * inside the import, as a host app's `EntrySaved` listener can do through a synchronous
     * queue, leaves every later question drawn into that command's buffered output while
     * this one waits on the keyboard (#228). So each command that finishes during the
     * import hands Prompts back.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     * @return int Exit code (0 for success, 1 for failure)
     */
    protected function runImportForParts(
        array $parts
    ): int {
        // Hand Prompts back after each nested command, and only while this import runs
        Event::listen(CommandFinished::class, function (): void {
            if ($this->importing) {
                $this->configurePrompts($this->input);
            }
        });

        $this->importing = true;

        try {
            return $this->importParts($parts);
        } finally {
            $this->importing = false;
        }
    }

    /**
     * Import an already-resolved part-set.
     *
     * @param  list<string>  $parts  Ordered part paths (one entry for a single-file export)
     * @return int Exit code (0 for success, 1 for failure)
     */
    private function importParts(
        array $parts
    ): int {
        // Resolve the non-interactive policy for unsupported-content gates
        $unsupportedContentPolicy = $this->resolveUnsupportedContentPolicy();

        // Check if the supplied policy value is invalid
        if ($unsupportedContentPolicy === null) {
            // Show error for invalid option value
            $this->error('The --unsupported-content option must be one of: abort, skip, import.');

            // Exit with error status code
            return 1;
        }

        // Notify user that import is starting. Naming the first part rather than the file
        // argument keeps this true for both sources — the argument is an output directory
        // when the content came from the API.
        $this->info("Starting WordPress import from: {$parts[0]}");

        // Clear deferred processing data from previous imports
        LinkProcessor::clearDeferredData();

        // ---
        // Measure initial filesize of the entire application directory (when enabled)
        // ---

        // An if reads the setting as a boolean, so the cast only types it
        // @pest-mutate-ignore: RemoveBooleanCast
        if (
            (bool) config(
                'wordpress-import.measure_filesize',
                true
            )
        ) {
            // Display informational message to user about filesize measurement
            $this->info('Measuring initial filesize of the codebase...');

            // Get the absolute path to the application root directory for filesize calculation
            $applicationDirectory = base_path();

            // Calculate and store the initial filesize of the application directory
            $this->initialFilesize = $this->calculateDirectoryFilesize($applicationDirectory);

            // Format the initial filesize for display in multiple units
            $initialFormatted = $this->formatFilesize($this->initialFilesize);

            // Display the initial filesize to the user in megabytes
            $this->info("Initial application directory filesize: {$initialFormatted['mb']} MB");

            // Clean up variables to free up memory
            unset(
                $initialFormatted,
                $applicationDirectory
            );
        }

        // Clear all import warnings at the start of import
        ImportWarningManager::clearWarnings();

        // Forget the ids and fragment links a previous run recorded (#502)
        DroppedAnchorTracker::clear();

        // Clear failed asset URLs from any previous import sessions
        FailedAssetCollector::clearFailedUrls();

        // Clear processed terms cache for a fresh import session
        BaseTaxonomyValidator::clearCaches();

        // Clear taxonomy term caches for a fresh import session
        BaseEntryCreator::clearTaxonomyCaches();

        // Check memory requirements across every part and prompt user if needed. The check
        // is the first step to read the parts, so a part the reader cannot open or parse
        // fails here, as the reader's warning raised as an `ErrorException`, and is reported
        // the way the parse below reports it (#289). Only that exception is caught, so an
        // aborted prompt still ends the run as it did.
        try {
            $this->checkMemoryRequirements($parts);
        } catch (\ErrorException $errorException) {
            // Show error for the part the reader could not read
            $this->error("Failed to parse XML file: {$errorException->getMessage()}");

            // Exit with error status code
            return 1;
        }

        // Parse the XML part(s)
        try {
            // Parse XML part(s) using streaming parser
            $this->parseXmlFile($parts);
        } catch (\Exception $exception) {
            // Show error for failed XML parsing
            $this->error("Failed to parse XML file: {$exception->getMessage()}");

            // Exit with error status code
            return 1;
        }

        // Handle form plugin detection and user interaction
        $this->formPluginCoordinator->handleFormPluginDetection(
            $this->xmlParser,
            $this->input,
            $this->output,
            $this
        );

        // ---
        // Display content types found in XML
        // ---

        // Notify user that content types are being displayed
        $this->info('Content types found in the XML:');

        // Get all content types from the XML parser
        $allContentTypes = $this->xmlParser->getContentTypes();

        // Display content types in a table format
        $this->table(
            [
                'Content Type',
                'Count',
            ],
            $allContentTypes
        );

        // Initialize MinervaKB settings variable for later use when processing kb content
        $minervaKbSettings = null;

        // Extract content type names from the content types array for checking
        $xmlTypes = array_column(
            $allContentTypes,
            0
        );

        // Check if kb content type exists in the XML export. Content type names are
        // strings, so a loose comparison finds the same ones.
        // @pest-mutate-ignore: TrueToFalse
        if (
            \in_array(
                'kb',
                $xmlTypes,
                true
            )
        ) {
            // Prompt user for MinervaKB settings export file to map configuration values
            $minervaKbSettings = $this->minervaKbSettingsSelector->select(
                $this->input,
                $this->output,
                $this
            );
        }

        // ---
        // Check for supported content types before proceeding
        // ---

        // Filter content types to only include supported ones, comparing names as
        // strings either way
        // @pest-mutate-ignore: TrueToFalse
        $supportedContentTypes = \array_filter(
            $allContentTypes,
            fn (array $type): bool => \in_array(
                $type[0],
                $this->supportedContentTypes,
                true
            )
        );

        // Check if no supported content types were found
        if ($supportedContentTypes === []) {
            // Show error for missing supported content types
            $this->error('No supported content types (page, post, uamspeople, redirect_rule, tablepress_table, kb) found in the XML.');

            // Exit with error status code
            return 1;
        }

        // ---
        // Resolve --content-type, the unattended caller's way of naming what to import
        // ---

        // The handles actually present in this export, for validation and for the
        // untouched-types report at the end.
        // `nav_menu_item` is deliberately absent: `ContentTypeSelector` filters it out of
        // the selectable options because menu items ride along with the navigation pass
        // rather than being imported as a content type of their own. Including it here
        // would advertise a handle `--content-type=` cannot honour, and would list it as
        // "not imported" forever — telling a caller to re-run with a flag that fails.
        $availableContentTypes = \array_filter(
            \array_map(
                fn (array $type): string => $type[0],
                $supportedContentTypes
            ),
            fn (string $handle): bool => $handle !== 'nav_menu_item'
        );

        $requestedContentType = $this->option('content-type');
        $requestedContentType = \is_string($requestedContentType) && \trim($requestedContentType) !== ''
            ? \trim($requestedContentType)
            : null;

        // Refuse an unknown handle rather than silently importing something else. A typo
        // that fell through to the default selector would import the alphabetically first
        // type and report success, which is the defect this option exists to remove (wordpress-importer-archive#706).
        // Handles are strings, so a loose comparison refuses the same ones.
        // @pest-mutate-ignore: TrueToFalse
        if (
            $requestedContentType !== null
            && ! \in_array($requestedContentType, $availableContentTypes, true)
        ) {
            $this->error(
                \sprintf(
                    "'%s' is not a supported content type present in this export.",
                    $requestedContentType
                )
            );

            $this->info('Available: '.\implode(', ', $availableContentTypes).'.');

            // Exit with error status code
            return 1;
        }

        // ---
        // List unsupported content types found in the XML
        // ---

        // Extract content type names from the content types array
        $xmlTypes = array_column(
            $allContentTypes,
            0
        );

        // Find content types that are not in the supported list
        $unsupportedTypes = array_diff(
            $xmlTypes,
            $this->supportedContentTypes
        );

        // Check if unsupported types were found
        if ($unsupportedTypes !== []) {
            // Inform user about unsupported content types
            $this->comment('Unsupported content types: '.\implode(
                ', ',
                $unsupportedTypes
            ));

        } else { // Otherwise
            // Inform user that all content types are supported
            $this->comment('All content types in XML are supported.');
        }

        // ---
        // Select site
        // ---

        // Prompt user to select target site for import
        $site = $this->siteSelector->select(
            $this->input,
            $this->output,
            $this
        );

        // Store selected site in resolver for later use
        SiteResolver::setSelectedSite($site);

        // Check if the selected site is valid
        if (! $this->siteSelector->validate($site)) {
            // Show error for invalid site
            $this->error("The site '$site' does not exist in the configuration.");

            // Exit with error status code
            return 1;
        }

        // Confirm to user that the selected site is valid
        $this->info("Selected site '$site' is valid.");

        // Apply this site's Departmental News Hub designation (wordpress-importer-archive#768). Runs once per
        // site, before the content-type loop, because the designation is a property
        // of the site rather than of anything being imported into it.
        $this->designateDepartmentOrganization($site);

        // Get base blog URL from XML parser for asset URL processing
        $baseBlogUrl = $this->xmlParser->getBaseBlogUrl();

        // Get upload images configuration setting. Only the || below reads it, which
        // yields a boolean either way, so the cast only types it.
        // @pest-mutate-ignore: RemoveBooleanCast
        $uploadImages = (bool) config(
            'wordpress-import.upload_images',
            true
        );

        // Get upload files configuration setting, read the same way
        // @pest-mutate-ignore: RemoveBooleanCast
        $uploadFiles = (bool) config(
            'wordpress-import.upload_files',
            true
        );

        // Determine if asset uploads are enabled
        $assetsEnabled = $uploadImages || $uploadFiles;

        // Set the base blog URL on the AssetImporter for site-specific asset filtering
        $this->assetImporter->setBaseBlogUrl($baseBlogUrl);

        // Track content types already handled this run. In non-interactive mode the
        // selector deterministically returns the first option every iteration, so a
        // content type skipped at a gate (or at any other `continue` in the loop body)
        // would otherwise be re-selected and re-skipped forever; recording handled types
        // and excluding them lets the loop advance to the next type or terminate (wordpress-importer-archive#389).
        // Content types this run actually selected. Reported at the end so a run that
        // imported one type of seven cannot pass for a complete one (wordpress-importer-archive#706).
        /** @var list<string> $importedContentTypes */
        $importedContentTypes = [];

        // Content types abandoned at a destination gate. Tracked because a gate skip
        // discards *every* item of its type, and the command used to report that as a
        // clean success — a 39-page import that wrote nothing exited 0 (wordpress-importer-archive#704).
        /** @var list<string> $skippedAtGate */
        $skippedAtGate = [];

        // ---
        // Loop to process multiple content types. Each pass selects a type not yet
        // handled, so a run makes at most one pass per supported type; iterating the
        // supported types bounds the loop there, keeping it finite whatever its body does.
        // The empty-pool check below still ends a run that has handled every type before
        // its passes are spent.
        // ---
        foreach ($supportedContentTypes as $unused) {
            // Narrow the pool to types not yet handled this run, and to the requested
            // type when one was named. `nav_menu_item` is excluded by
            // `ContentTypeSelector` itself, so that filter is not duplicated here.
            //
            // Excluding handled types is wordpress-importer-archive#389's guard and it is load-bearing, not
            // bookkeeping: a `continue` from any skip gate below re-enters this loop
            // *before* `$continue` is ever assigned, so the deterministic selector
            // would return the same type forever. wordpress-importer-archive#707 removed it on the reasoning
            // that the continuation prompt ends the loop; the prompt is never reached
            // on that path. Restored with the empty-pool `break`, which is what
            // actually terminates the run (wordpress-importer-archive#711).
            // Handled types are names, compared as strings either way
            // @pest-mutate-ignore: TrueToFalse
            $selectableContentTypes = \array_values(
                \array_filter(
                    $supportedContentTypes,
                    fn (array $type): bool => ! \in_array($type[0], $importedContentTypes, true)
                        && ($requestedContentType === null || $type[0] === $requestedContentType)
                )
            );

            // Nothing left to select — every candidate has been handled, so end the
            // loop rather than re-offering a type the selector would return again.
            // The pass bound would end it as well, each remaining pass finding the same
            // empty pool, so continuing instead changes nothing a run can observe.
            if ($selectableContentTypes === []) {
                // @pest-mutate-ignore: BreakToContinue
                break;
            }

            // Select content type
            $contentType = $this->contentTypeSelector->select(
                $selectableContentTypes,
                $this->input,
                $this->output,
                $this
            );

            $importedContentTypes[] = $contentType;

            // Confirm selected content type to user
            $this->info("Selected content type: '$contentType'");

            // ---
            // Skip navigation menu items during main import loop. Navigation import will be
            // handled after all content types are imported to ensure all entries are fully
            // processed and cached before navigation import.
            // ---

            // Check if content type is navigation menu item
            if ($contentType === 'nav_menu_item') {
                // Inform user that navigation import is deferred until after content import
                $this->info('Navigation menu items will be imported after all content types are processed.');

                // Skip to the import completion prompt without processing navigation items
                goto import_complete;
            }

            // Scan for mixed Classic and Gutenberg block content
            $classicPages = $this->scanForClassicBlocks($contentType);

            // Check if Classic block pages were found
            if ($classicPages !== []) {
                // Warn user about detected Classic block content
                $this->warn("Mixed Classic and Gutenberg block content detected in '$contentType' content:");

                // Loop through Classic block pages to display details
                foreach ($classicPages as $page) {
                    // Display page details to user
                    $this->line(" - Title: '{$page['title']}', ID: {$page['id']}, Permalink: {$page['link']}");
                }

                // Warn user about mapping limitations and provide guidance
                $this->warn('Mixed Classic and Gutenberg block content is not currently mapped to the Statamic Bard format. To import it correctly, edit the original page(s) and choose the "Convert to blocks" option. Once the affected pages have been updated, re-run the WordPress export tool and then restart this importer with the new XML file.');

                // Resolve the policy: prompt when interactive, otherwise honor --unsupported-content
                $decision = $this->resolveUnsupportedContent(
                    "Would you like to continue importing '$contentType' content?",
                    $unsupportedContentPolicy,
                    $this->input,
                    $this->output,
                    $this
                );

                // Check if the run should abort on unsupported content
                if ($decision === 'abort') {
                    // Fail the run so unattended imports cannot silently drop content
                    $this->error("Aborting import of '$contentType' due to Classic block presence (see affected pages above).");

                    // Exit with error status code
                    return 1;
                }

                // Check if the content type should be skipped
                if ($decision !== 'import') {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' due to Classic block presence.");

                    // Skip to next iteration of loop
                    continue;
                }

                // Pass Classic block pages to EntryCreator for note addition
                $this->entryCreator->setClassicBlockPages($classicPages); // Pass to EntryCreator for note addition
            }

            // Scan for Freeform blocks
            $freeformPages = $this->scanForFreeformBlocks($contentType);

            // Check if Freeform block pages were found
            if ($freeformPages !== []) {
                // Warn user about detected Freeform block content
                $this->warn("Freeform block content detected in '$contentType' content:");

                // Loop through Freeform block pages to display details
                foreach ($freeformPages as $page) {
                    // Display page details to user
                    $this->line(" - Title: '{$page['title']}', ID: {$page['id']}, Permalink: {$page['link']}");
                }

                // Warn user about mapping limitations and provide guidance
                $this->warn('Freeform blocks are not currently mapped to the Statamic Bard format. To import it correctly, edit the original page(s) and choose the "Convert to blocks" option. Once the affected pages have been updated, re-run the WordPress export tool and then restart this importer with the new XML file.');

                // Resolve the policy: prompt when interactive, otherwise honor --unsupported-content
                $decision = $this->resolveUnsupportedContent(
                    "Would you like to continue importing '$contentType' content?",
                    $unsupportedContentPolicy,
                    $this->input,
                    $this->output,
                    $this
                );

                // Check if the run should abort on unsupported content
                if ($decision === 'abort') {
                    // Fail the run so unattended imports cannot silently drop content
                    $this->error("Aborting import of '$contentType' due to Freeform block presence (see affected pages above).");

                    // Exit with error status code
                    return 1;
                }

                // Check if the content type should be skipped
                if ($decision !== 'import') {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' due to Freeform block presence.");

                    // Skip to next iteration of loop
                    continue;
                }

                // Pass to EntryCreator for note addition
                $this->entryCreator->setFreeformBlockPages($freeformPages);
            }

            // Scan for Details blocks
            $detailsPages = $this->scanForDetailsBlocks($contentType);

            // Check if Details block pages were found
            if ($detailsPages !== []) {
                // Warn user about detected Details block content
                $this->warn("Details block content detected in '$contentType' content:");

                // Loop through Details block pages to display details
                foreach ($detailsPages as $page) {
                    // Display page details to user
                    $this->line(" - Title: '{$page['title']}', ID: {$page['id']}, Permalink: {$page['link']}");
                }

                // Warn user about mapping limitations and provide guidance
                $this->warn('Details blocks are not currently mapped to the Statamic Bard format. To import it correctly, edit the original page(s) and choose the "Convert to blocks" option. Once the affected pages have been updated, re-run the WordPress export tool and then restart this importer with the new XML file.');

                // Resolve the policy: prompt when interactive, otherwise honor --unsupported-content
                $decision = $this->resolveUnsupportedContent(
                    "Would you like to continue importing '$contentType' content?",
                    $unsupportedContentPolicy,
                    $this->input,
                    $this->output,
                    $this
                );

                // Check if the run should abort on unsupported content
                if ($decision === 'abort') {
                    // Fail the run so unattended imports cannot silently drop content
                    $this->error("Aborting import of '$contentType' due to Details block presence (see affected pages above).");

                    // Exit with error status code
                    return 1;
                }

                // Check if the content type should be skipped
                if ($decision !== 'import') {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' due to Details block presence.");

                    // Skip to next iteration of loop
                    continue;
                }

                // Pass to EntryCreator for note addition
                $this->entryCreator->setDetailsBlockPages($detailsPages);
            }

            // ---
            // Scan for kb entries without associated topics
            // ---

            // Check if content type is knowledge base
            if ($contentType === 'kb') {
                // Call the method to scan for kb entries missing associated topics
                $kbWithoutTopics = $this->scanForKbWithoutTopics($contentType);

                // Check if any entries without topics were found
                if ($kbWithoutTopics !== []) {
                    // Warn the user about the detected entries without topics
                    $this->warn("Knowledge Base entries without associated topics detected in '$contentType' content:");

                    // ---
                    // List each affected entry with details for user reference
                    // ---

                    // Loop through entries without topics to display details
                    foreach ($kbWithoutTopics as $entry) {
                        // Display entry details to user
                        $this->line(" - Title: '{$entry['title']}', ID: {$entry['id']}, Permalink: {$entry['link']}");
                    }

                    // Provide guidance on resolving the issue before import
                    $this->warn('Knowledge Base entries without associated topics will not be imported. To include these entries, resolve the issue on the source site by assigning topics to all entries, then re-run the WordPress export tool and restart this importer with the new XML file.');

                    // ---
                    // Resolve the policy: prompt when interactive, otherwise honor --unsupported-content
                    // ---
                    $decision = $this->resolveUnsupportedContent(
                        "Would you like to continue importing '$contentType' content despite entries without topics?",
                        $unsupportedContentPolicy,
                        $this->input,
                        $this->output,
                        $this
                    );

                    // ---
                    // Abort, skip, or import the affected entries per the resolved decision
                    // ---

                    // Check if the run should abort on unsupported content
                    if ($decision === 'abort') {
                        // Fail the run so unattended imports cannot silently drop content
                        $this->error("Aborting import of '$contentType' due to entries without associated topics (see affected entries above).");

                        // Exit with error status code
                        return 1;
                    }

                    // Check if the content type should be skipped
                    if ($decision !== 'import') {
                        // Inform user that import is being skipped
                        $this->info("Skipping import of '$contentType' due to entries without associated topics.");

                        // Skip to next iteration of loop
                        continue;
                    }

                    // Pass the list of affected entries to EntryCreator for potential note addition
                    $this->entryCreator->setKbWithoutTopics($kbWithoutTopics);
                }
            }

            // Check for default mapping
            if (isset($this->defaultMappings[$contentType])) {
                // Get default mapping configuration for this content type
                $default = $this->defaultMappings[$contentType];

                // Extract default handle from mapping
                $defaultHandle = $default['handle'];

                // Extract default type from mapping
                $defaultType = $default['type'];

                // Set destination from default mapping
                $destination = ['type' => $defaultType, 'handle' => $defaultHandle];

                // Check if default type is collection
                if ($defaultType === 'collection') {
                    // Find collection by handle
                    $collection = Collection::findByHandle($defaultHandle);

                    // Check if collection does not exist
                    if ($collection === null) {
                        // Warn user that collection is missing
                        $this->warn("The '$defaultHandle' collection does not exist in Statamic.");

                        // Inform user that collection needs to be created
                        $this->info("Please create and configure the '$defaultHandle' collection before importing '$contentType' content.");

                        // Record the abandonment so the exit code reflects it (#291)
                        $skippedAtGate[] = "$contentType (needs the '$defaultHandle' collection)";

                        // Skip to next iteration of loop
                        continue;
                    }

                    // Check if collection is not enabled for the site
                    if (
                        ! $this->isCollectionEnabledForSite(
                            $site,
                            $defaultHandle
                        )
                    ) {
                        // Enable collection for the site
                        $this->enableCollectionForSite(
                            $site,
                            $defaultHandle
                        );

                        // Confirm to user that collection was enabled
                        $this->info("Enabled '$site' for collection '$defaultHandle'.");
                    }
                } elseif ($defaultType === 'global') { // Check if default type is global
                    // Find global set by handle
                    $global = GlobalSet::findByHandle($defaultHandle);

                    // Check if global set does not exist
                    if ($global === null) {
                        // Warn user that global set is missing
                        $this->warn("The '$defaultHandle' global set does not exist in Statamic.");

                        // Inform user that global set needs to be created
                        $this->info("Please create and configure the '$defaultHandle' global set before importing '$contentType' content.");

                        // Record the abandonment so the exit code reflects it (#291)
                        $skippedAtGate[] = "$contentType (needs the '$defaultHandle' global set)";

                        // Skip to next iteration of loop
                        continue;
                    }

                    // Check if global set is not enabled for the site
                    if (
                        ! $this->isGlobalEnabledForSite(
                            $site,
                            $defaultHandle
                        )
                    ) {
                        // Enable global set for the site
                        $this->enableGlobalForSite(
                            $site,
                            $defaultHandle
                        );

                        // Confirm to user that global set was enabled
                        $this->info("Enabled '$site' for global set '$defaultHandle'.");
                    }
                }
            } else {
                // Report a content type with no default mapping, and end the run (#213)
                $this->error("'$contentType' content has no default mapping to a Statamic collection or global set.");

                // Exit with error status code
                return 1;
            }

            $destinationType = $destination['type'];
            $destinationHandle = $destination['handle'];

            // Check and enable relevant taxonomies
            if (
                $destinationType === 'collection'
                && $contentType === 'page'
            ) {
                // Check logos_item_categories only if wp:acf/logo-list blocks are present
                if (
                    $this->hasAcfBlockType(
                        $contentType,
                        'logo-list'
                    )
                ) {
                    // Check if taxonomy enabling was successful
                    if (
                        ! $this->enableTaxonomyIfNeeded(
                            $site,
                            'logos_item_categories',
                            $contentType
                        )
                    ) {
                        // Inform user that import is being skipped
                        $this->info("Skipping import of '$contentType' as 'logos_item_categories' is not enabled for '$site'.");

                        // Record the abandonment so the exit code reflects it.
                        $skippedAtGate[] = "$contentType (needs 'logos_item_categories')";

                        // Skip to next iteration of loop
                        continue;
                    }
                }

                // ---
                // Check region only if wp:acf/fad-providers or wp:acf/fad-locations blocks are
                // present
                // ---
                if (
                    $this->hasAcfBlockType(
                        $contentType,
                        'fad-providers'
                    )
                    || $this->hasAcfBlockType(
                        $contentType,
                        'fad-locations'
                    )
                ) {
                    // Check if taxonomy enabling was successful
                    if (
                        ! $this->enableTaxonomyIfNeeded(
                            $site,
                            'regions',
                            $contentType
                        )
                    ) {
                        // Inform user that import is being skipped
                        $this->info("Skipping import of '$contentType' as 'regions' is not enabled for '$site'.");

                        // Record the abandonment so the exit code reflects it.
                        $skippedAtGate[] = "$contentType (needs 'regions')";

                        // Skip to next iteration of loop
                        continue;
                    }
                }

                // Check location_type only if wp:acf/fad-locations blocks are present
                if (
                    $this->hasAcfBlockType(
                        $contentType,
                        'fad-locations'
                    )
                ) {
                    // Check if taxonomy enabling was successful
                    if (
                        ! $this->enableTaxonomyIfNeeded(
                            $site,
                            'location_types',
                            $contentType
                        )
                    ) {
                        // Inform user that import is being skipped
                        $this->info("Skipping import of '$contentType' as 'location_types' is not enabled for '$site'.");

                        // Record the abandonment so the exit code reflects it.
                        $skippedAtGate[] = "$contentType (needs 'location_types')";

                        // Skip to next iteration of loop
                        continue;
                    }
                }
            }

            // ---
            // Check people_groups for uamspeople and tshowcase. Pages with show-people
            // shortcodes need no check here: EntryCreator::createEntries() enables
            // people and people_groups for them once they are imported.
            // ---
            if (
                $destinationType === 'collection'
                && (
                    $contentType === 'uamspeople'
                    || $contentType === 'tshowcase'
                )
            ) {
                // Check if taxonomy enabling was successful
                if (
                    ! $this->enableTaxonomyIfNeeded(
                        $site,
                        'people_groups',
                        $contentType
                    )
                ) {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' as 'people_groups' is not enabled for '$site'.");

                    // Record the abandonment so the exit code reflects it.
                    $skippedAtGate[] = "$contentType (needs 'people_groups')";

                    // Skip to next iteration of loop
                    continue;
                }
            }

            // Check classified_categories for WP Adverts listings
            if (
                $destinationType === 'collection'
                && $contentType === 'advert'
            ) {
                // Check if taxonomy enabling was successful
                if (
                    ! $this->enableTaxonomyIfNeeded(
                        $site,
                        'classified_categories',
                        $contentType
                    )
                ) {
                    // Inform user that import is being skipped
                    $this->info("Skipping import of '$contentType' as 'classified_categories' is not enabled for '$site'.");

                    // Record the abandonment so the exit code reflects it.
                    $skippedAtGate[] = "$contentType (needs 'classified_categories')";

                    // Skip to next iteration of loop
                    continue;
                }
            }

            // ---
            // Handle collection-specific imports
            // ---

            // Check if destination type is collection
            if ($destinationType === 'collection') {
                // ---
                // Prompt for entry overwrite behavior
                // ---

                // Define options for entry overwrite behavior
                $entryOptions = [
                    'Overwrite matching files',
                    'Skip matching files',
                    'Create an additional file',
                ];

                // Prompt user to select entry overwrite behavior
                $entryOverwriteChoice = $this->isPromptsSupported() && $this->input->isInteractive()
                    ? select(
                        label: "How should existing entries in the '{$destinationHandle}' collection with matching IDs be handled?",
                        options: $entryOptions,
                        default: $entryOptions[0]
                    )
                    : $this->choice(
                        "How should existing entries in the '{$destinationHandle}' collection with matching IDs be handled?",
                        $entryOptions,
                        0
                    );

                // Set entry overwrite behavior on EntryCreator. Both prompts answer one of the options,
                // a string, so the assertion only types it.
                // @pest-mutate-ignore: RemoveFunctionCall
                \assert(is_string($entryOverwriteChoice));
                $this->entryCreator->setEntryOverwriteBehavior($entryOverwriteChoice);

                // Prompt for taxonomy overwrite behavior
                $taxonomyOverwriteChoice = $this->taxonomyOverwriteBehaviorSelector->select(
                    $this->input,
                    $this->output,
                    $this
                );

                // Set the taxonomy overwrite behavior for the entry creator
                $this->entryCreator->setTaxonomyOverwriteBehavior($taxonomyOverwriteChoice);

                // ---
                // Prompt for asset overwrite behavior
                // ---

                // Check if asset uploads are enabled
                if ($assetsEnabled) {
                    // Define options for asset overwrite behavior
                    $assetOptions = [
                        'Overwrite matching files',
                        'Skip matching files',
                        'Create an additional file',
                    ];

                    // Prompt user to select asset overwrite behavior
                    $assetOverwriteChoice = $this->isPromptsSupported() && $this->input->isInteractive()
                        ? select(
                            label: 'How should existing assets in the relevant container be handled?',
                            options: $assetOptions,
                            default: $assetOptions[0]
                        )
                        : $this->choice(
                            'How should existing assets in the relevant container be handled?',
                            $assetOptions,
                            0
                        );

                    // Set asset overwrite behavior on AssetImporter. Both prompts answer one of the options,
                    // a string, so the assertion only types it.
                    // @pest-mutate-ignore: RemoveFunctionCall
                    \assert(is_string($assetOverwriteChoice));
                    $this->assetImporter->setAssetOverwriteBehavior($assetOverwriteChoice);
                } else { // Otherwise
                    // Inform user that asset uploads are disabled
                    $this->info('Asset uploads disabled (images and files off); skipping overwrite prompt.');

                    // Set default asset overwrite behavior to skip
                    $this->assetImporter->setAssetOverwriteBehavior('Skip matching files');
                }

                // ---
                // Prompt for table overwrite behavior if TablePress content is detected
                // ---

                // Check if TablePress content is present in this content type
                if ($this->hasTablePressContent($contentType)) {
                    // Define options for table overwrite behavior
                    $tableOptions = [
                        'Overwrite matching files',
                        'Skip matching files',
                        'Create an additional file',
                    ];

                    // Prompt user to select table overwrite behavior
                    $tableOverwriteChoice = $this->isPromptsSupported() && $this->input->isInteractive()
                        ? select(
                            label: 'How should existing table entries with matching IDs be handled?',
                            options: $tableOptions,
                            default: $tableOptions[0]
                        )
                        : $this->choice(
                            "How should existing entries in the 'tables' collection with matching IDs be handled?",
                            $tableOptions,
                            0
                        );

                    // Set table overwrite behavior on EntryCreator. Both prompts answer one of the options,
                    // a string, so the assertion only types it.
                    // @pest-mutate-ignore: RemoveFunctionCall
                    \assert(is_string($tableOverwriteChoice));
                    $this->entryCreator->setTableOverwriteBehavior($tableOverwriteChoice);
                }

                // ---
                // Set dependencies and create entries
                // ---

                // Set XML parser on EntryCreator
                $this->entryCreator->setXmlParser($this->xmlParser);

                // Set asset importer on EntryCreator
                $this->entryCreator->setAssetImporter($this->assetImporter);

                // Check if content type is not knowledge base
                if ($contentType !== 'kb') {
                    // Create entries for this content type
                    $this->entryCreator->createEntries(
                        $site,
                        $contentType,
                        $destinationHandle,
                        $this->xmlParser,
                        $this->assetImporter,
                        $this->output,
                        $baseBlogUrl,
                        $minervaKbSettings
                    );
                }

                // ---
                // Validate taxonomy terms after entry creation
                // ---

                // Check if content type is page
                if ($contentType === 'page') {
                    // Check if logo-list ACF blocks are present
                    if (
                        $this->hasAcfBlockType(
                            $contentType,
                            'logo-list'
                        )
                    ) {
                        // ---
                        // Validate logos_item_categories terms
                        // ---

                        // Create validator instance for logos item categories
                        $categoriesValidator = new LogosItemCategoriesValidator;

                        // Validate logos item categories terms
                        $categoriesValidator->validateTerms(
                            LogoListParser::getTermsUsed()['logos_item_categories'] ?? [],
                            $destinationHandle,
                            $site,
                            $this->output
                        );

                        // ---
                        // Labels are not validated: `logos_item_labels` is a
                        // site-scoped collection whose entries the term pass
                        // creates, and the block records only resolved entry
                        // ids (wordpress-importer-archive#1220).
                        // ---
                    }

                    // Note: Region and LocationType validators not yet implemented
                    // These will need to be created as specific validators in the future

                    // Check if fad-providers or fad-locations ACF blocks are present
                    if (
                        $this->hasAcfBlockType(
                            $contentType,
                            'fad-providers'
                        )
                        || $this->hasAcfBlockType(
                            $contentType,
                            'fad-locations'
                        )
                    ) {
                        // Warn user that Region taxonomy validation is not yet implemented
                        $this->output->writeln('<comment>Warning: Region taxonomy validation not yet implemented with new validator system.</comment>');
                    }

                    // Check if fad-locations ACF blocks are present
                    if (
                        $this->hasAcfBlockType(
                            $contentType,
                            'fad-locations'
                        )
                    ) {
                        // Warn user that LocationType taxonomy validation is not yet implemented
                        $this->output->writeln('<comment>Warning: LocationType taxonomy validation not yet implemented with new validator system.</comment>');
                    }
                } elseif ($contentType === 'post') { // Posts reference no taxonomy terms left to validate
                    // ---
                    // Post CATEGORIES are not validated here. `post_categories`
                    // stopped being a taxonomy in `UAMS-Web/uams-statamic-archive#1433`
                    // and is now a structured collection, so the validator that
                    // ran here scanned an absent `content/taxonomies/post_categories`,
                    // judged every category missing, and tried to create terms in
                    // a taxonomy that does not exist (wordpress-importer-archive#422). The entries the field
                    // references are created by the term pass instead.
                    // ---

                    // ---
                    // Post TAGS are not validated either, for the same reason:
                    // `UAMS-Web/uams-statamic-archive#2532` made `post_tags` a
                    // site-scoped structured collection, and the term pass
                    // creates the entries a post references (wordpress-importer-archive#1220).
                    // ---
                } elseif ($contentType === 'kb') { // Process knowledge base content with specialized KbEntryCreator
                    // Set taxonomy overwrite behavior for KbEntryCreator
                    $this->kbEntryCreator->setTaxonomyOverwriteBehavior($taxonomyOverwriteChoice);

                    // Process KbEntry content
                    $this->kbEntryCreator->process(
                        $this->xmlParser,
                        $this->assetImporter,
                        $this->output,
                        $baseBlogUrl,
                        $site,
                        $this->entryCreator->getSiteManager(),
                        $this->collectionStructureManager,
                        $minervaKbSettings
                    );
                }

                // ---
                // Only build tree for non-KbEntry content types
                // ---

                // Check if content type is not knowledge base
                if ($contentType !== 'kb') {
                    // Build and save collection tree structure
                    $this->treeBuilder->buildAndSaveTree(
                        $site,
                        $contentType,
                        $destinationHandle,
                        $this->entryCreator->getPageData(),
                        $this->output,
                        $baseBlogUrl
                    );
                }
            } elseif ($destinationType === 'global') { // Check if destination type is global
                // ---
                // Prompt for redirect overwrite behavior
                // ---

                // Check if content type is redirect rule
                if ($contentType === 'redirect_rule') {
                    // Define options for redirect overwrite behavior
                    $redirectOptions = [
                        'Overwrite matching sets',
                        'Skip matching sets',
                        'Create an additional set',
                    ];

                    // Prompt user to select redirect overwrite behavior
                    $redirectOverwriteChoice = $this->isPromptsSupported() && $this->input->isInteractive()
                        ? select(
                            label: 'How should existing redirect sets with matching source URLs be handled?',
                            options: $redirectOptions,
                            default: $redirectOptions[0]
                        )
                        : $this->choice(
                            'How should existing redirect sets with matching source URLs be handled?',
                            $redirectOptions,
                            0
                        );

                    // Set redirect overwrite behavior on GlobalCreator. Both prompts answer one of the options,
                    // a string, so the assertion only types it.
                    // @pest-mutate-ignore: RemoveFunctionCall
                    \assert(is_string($redirectOverwriteChoice));
                    $this->globalCreator->setGlobalOverwriteBehavior($redirectOverwriteChoice);
                }

                // Create global sets for this content type
                $this->globalCreator->createGlobals(
                    $site,
                    $contentType,
                    $destinationHandle,
                    $this->xmlParser,
                    $this->assetImporter,
                    $this->output
                );
            } else { // Otherwise
                // Inform user that taxonomy import is not yet implemented
                $this->info('Taxonomy import not implemented yet. Stopping this cycle.');
            }

            // ---
            // Reset term usage for next cycle
            // ---

            // Reset terms used by PeopleShortcodeProcessor
            PeopleShortcodeProcessor::resetTermsUsed();

            // Reset terms used by LogoListParser
            LogoListParser::resetTermsUsed();

            // Reset terms used by FindADocProvidersParser
            FindADocProvidersParser::resetTermsUsed();

            // Reset terms used by FindADocLocationsParser
            FindADocLocationsParser::resetTermsUsed();

            // Clear any deferred taxonomy terms queued during this cycle. The flush
            // before validation already drains the queue per site; this guards
            // against leakage across content-type cycles and test runs.
            BaseEntryCreator::clearDeferredTermQueue();

            // ---
            // Display failed asset downloads for this content type
            // ---

            // Check if there were any failed asset downloads
            if (FailedAssetCollector::hasFailedDownloads()) {
                // Add blank line for readability
                $this->line('');

                // Display formatted list of failed asset URLs
                $this->warn(FailedAssetCollector::formatFailedUrlsForDisplay());

                // Add blank line for readability
                $this->line('');
            }

            // Clear failed assets for next cycle
            FailedAssetCollector::clearFailedUrls();

            // Label for import complete
            import_complete:

            // Prompt to import more content
            $continue = $this->isPromptsSupported() && $this->input->isInteractive()
                ? confirm(
                    label: 'Would you like to import more content?',
                    default: false
                )
                : $this->confirm(
                    'Would you like to import more content?',
                    false
                );

            // End the loop when no more content is wanted
            if (! $continue) {
                break;
            }
        }

        // ---
        // Clear and refresh Stache cache before navigation import. This ensures all newly
        // created entries are properly indexed and findable.
        // ---
        $this->clearStacheBeforeNavigation();

        // Handle navigation menu import after all content types are imported
        // This ensures all entries are fully processed and cached before navigation import.
        // A run whose imported types none declare the pass skips it, so a redirect run,
        // scoped or picked interactively, neither reads the menus nor changes the site's
        // navigation setting (#109).
        if (ContentTypeReads::runsPass(ContentTypeReads::PASS_NAVIGATION, $importedContentTypes)) {
            $this->handleNavigationMenuImport();
        } else {
            $this->info('Skipping navigation menu import - no imported content type declares it.');
        }

        // ---
        // Process final deferred operations after all content types are imported
        // ---

        // ---
        // Process any remaining deferred internal links after all content types are
        // imported. This handles cross-post-type links that couldn't be resolved during
        // individual post type imports.
        // ---
        $this->deferredProcessingCoordinator->processFinal(
            $site,
            $this->output
        );

        // ---
        // Measure final filesize and display difference when user is done importing (when enabled)
        // ---

        // An if reads the setting as a boolean, so the cast only types it
        // @pest-mutate-ignore: RemoveBooleanCast
        if (
            (bool) config(
                'wordpress-import.measure_filesize',
                true
            )
        ) {
            // Notify user that final filesize measurement is starting
            $this->info('Measuring final filesize of the codebase...');

            // Get the absolute path to the application root directory
            $applicationDirectory = base_path();

            // Calculate final filesize of the application directory
            $finalFilesize = $this->calculateDirectoryFilesize($applicationDirectory);

            // Display the filesize difference to the user
            $this->displayFilesizeDifference($finalFilesize);

            // Clean up variables to free up memory
            unset(
                $finalFilesize,
                $applicationDirectory
            );
        }

        // Clear the selected site to avoid persistence
        SiteResolver::clearSelectedSite();

        // Clear form creation cache
        $this->formPluginCoordinator->clearAllCaches();

        // A verification run's cache is its own, and is dropped with the workspace
        if (! $this->isVerificationRun()) {
            // Clear Laravel cache
            Artisan::call('cache:clear');

            // Confirm to user that cache was cleared
            $this->info('Laravel cache cleared.');
        }

        // Sanitize Bard content (YAML separators and unquoted nbsp text) in all
        // entries so YAML is formatted properly when the import is completed. The pass
        // rewrites entries whichever run wrote them, so a run whose imported types none
        // declare it, such as a redirect run, leaves them alone (#109).
        if (ContentTypeReads::runsPass(ContentTypeReads::PASS_BARD_SANITIZATION, $importedContentTypes)) {
            $this->runBardContentSanitization();
        } else {
            $this->info('Skipping Bard content sanitization - no imported content type declares it.');
        }

        // Rebuild search indexes once now that all content is imported. Live
        // search-index updates were disabled for the run (see handle()), so this
        // single pass replaces the hundreds of per-save full rebuilds that would
        // otherwise have dominated the import. A verification run skips it: search
        // indexes live outside the workspace, so a rebuild would fill the host's real
        // indexes with content that is about to be deleted (#254). A run whose imported
        // types none declare the pass wrote nothing for reader-facing search, but its
        // redirect domain entries belong in the Control Panel's own index, so only that
        // one is rebuilt (#229).
        if ($this->isVerificationRun()) {
            $this->info('Skipping search index rebuild - a verification run leaves the indexes as they are.');
        } elseif (ContentTypeReads::runsPass(ContentTypeReads::PASS_SEARCH_INDEX, $importedContentTypes)) {
            SearchIndexUpdates::reindexAll($this->output);
        } else {
            $this->info('Rebuilding only the Control Panel search index - no imported content type declares the full rebuild.');
            SearchIndexUpdates::reindexAll($this->output, [SearchIndexUpdates::CP_INDEX]);
        }

        // Remove any dataless entry files this run materialized, before the summary is
        // rendered so the removals appear in it rather than after it (wordpress-importer-archive#1160).
        $this->sweepDatalessEntries();

        // Report each link that names an id the import dropped, now that every page
        // this run imports has recorded its ids (#502)
        DroppedAnchorTracker::reportLinksToDroppedIds();

        // Display all import warnings
        ImportWarningDisplayService::displayAllWarnings($this->output);

        // Notify user that import process is complete
        $this->info('Import process complete.');

        // A run imports one content type. That is the intended unit — the interactive
        // continuation prompt defaults to "no" as well — but an unattended caller that
        // named no type got one picked for it, and used to be told nothing about the
        // rest. Naming them, and failing, is what stops 1-of-7 passing for a whole
        // import (wordpress-importer-archive#706). A caller that *did* name a type asked for exactly this, so it
        // succeeds.
        $untouched = \array_diff(
            $availableContentTypes,
            $importedContentTypes
        );

        if ($requestedContentType === null && $untouched !== []) {
            $this->newLine();
            $this->error('This export contains supported content types that were not imported:');

            foreach ($untouched as $type) {
                $this->line("  - $type");
            }

            $this->info('Re-run with --content-type=<handle> for each, or import them interactively.');

            // Return failure so a partial import cannot be read as a complete one.
            return 1;
        }

        // A gate skip drops every item of its content type, so a run that skipped one
        // imported nothing for it. Reporting that as success is how 39 pages went
        // missing behind an exit 0 (wordpress-importer-archive#704) — name what was dropped and fail.
        if ($skippedAtGate !== []) {
            $this->newLine();
            $this->error('Nothing was imported for the following content types, because a destination was missing:');

            foreach ($skippedAtGate as $skipped) {
                $this->line("  - $skipped");
            }

            $this->warn('Create the missing destinations and re-run; the import is incomplete.');

            // Return failure so a caller, a script or CI cannot read this as a success.
            return 1;
        }

        // Return success status code
        return 0;
    }

    /**
     * Clear the whole Stache before the navigation import, so the menus resolve
     * every entry the run created.
     *
     * A transient cache-write failure skips the clear rather than ending the run
     * (#325): `StacheRebuildGuard` records it in the run's warnings, and the
     * navigation import goes ahead against the cache as it stands.
     */
    protected function clearStacheBeforeNavigation(): void
    {
        // Clear the Stache, retrying once if a cache write is refused
        $cleared = StacheRebuildGuard::run(
            'before the navigation import',
            'all',
            static function (): void {
                Stache::clear();
            }
        );

        // Tell the user whether the Stache was refreshed
        if ($cleared) {
            $this->info('Stache cache cleared before navigation import.');

            return;
        }

        $this->warn('Stache cache could not be cleared before navigation import; continuing with the cache as it stands.');
    }
}
