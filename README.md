# WordPress Importer for Statamic

Import WordPress XML exports into Statamic with an interactive Artisan command.

This addon is designed for real migration work: it parses WordPress exports, lets you choose what to import, maps content into Statamic collections/globals/navigation, imports related assets, and helps you catch problematic content patterns before import.

## Documentation

- **[The wiki](https://github.com/UAMS-Web/aether-wordpress-importer/wiki)**: your first import, importing from the migration API, previewing an import with `--verify`, navigation menus and MinervaKB, the command reference, content types and destinations, and troubleshooting. Its pages are kept in [`docs/wiki/`](docs/wiki) and published from there.
- **This file**: requirements, installation, a quick start, development, the declared public surface and releasing.
- **[Technical reference](docs/technical-reference.md)**: the full command workflow (scans, completion), class catalog, configuration and environment flags, mixed content, the modules template, and other internals.
- **[Migration API contract](docs/migration-api-contract.md)**: endpoints, pagination, query semantics and the places a request succeeds while returning less than you asked for.
- **[Migration API secret runbook](docs/runbooks/migration-api-secret.md)**: generating, placing, verifying, rotating and removing the credential the `--source=api` import needs.

## Requirements

- PHP `^8.4`
- Statamic `statamic/cms` **pinned exactly** to the version in [`composer.json`](composer.json), not a caret range. The exact pin makes this package resolve the same Statamic build the host app runs, which keeps both on one `ueberdosis/tiptap-php`; moving it is a coupled change made here first and then in the host. See [`CLAUDE.md`](CLAUDE.md).

## Installation

Install with Composer:

```bash
composer require uams-web/aether-wordpress-importer
```

The addon service provider is auto-discovered by Laravel/Statamic.

**Moving from `uams-web/wordpress-importer`:** the package and its namespace were renamed, and the old package is abandoned. A consumer changes three things:

- Require `uams-web/aether-wordpress-importer` in place of `uams-web/wordpress-importer`. GitHub redirects the old repository URL; Composer does not redirect the old package name.
- Replace `UAMSWeb\WordPressImporter\` with `UAMSWeb\Aether\WordPressImporter\` in any import of a class from this package.
- Replace `vendor/uams-web/wordpress-importer/` with `vendor/uams-web/aether-wordpress-importer/` in any path, such as a host-app test command.

The artisan command, its options and the environment variables keep their names. The package has no configuration file, publish tag or published asset to rename.

**Code layout:** this repository’s PHP lives under `src/` (Composer package `uams-web/aether-wordpress-importer`). The import command class is `UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress`, registered by `UAMSWeb\Aether\WordPressImporter\ServiceProvider`.

## Quick start

Import from a WordPress XML (WXR) export file, and answer the prompts:

```bash
php artisan statamic:import:wordpress /path/to/export.xml
```

Reading a site directly through the migration API (`--source=api`) needs a credential issued on the WordPress side first: see [Importing from the migration API](https://github.com/UAMS-Web/aether-wordpress-importer/wiki/Importing-from-the-migration-API). Every option is on the [command reference](https://github.com/UAMS-Web/aether-wordpress-importer/wiki/Command-reference), and what each content type imports and where it lands is on [Content types and destinations](https://github.com/UAMS-Web/aether-wordpress-importer/wiki/Content-types-and-destinations).

## Development

Run tests with:

```bash
./vendor/bin/phpunit
```

### Test fixtures (the export corpus)

The WXR and forms fixtures are WordPress exports of UAMS sites, so they are **private**. They live only in the private repository `UAMS-Web/wordpress-importer-exports`, which only accounts granted access to it can fetch. This repository does not carry them (#1426). With access, clone the corpus anywhere and name the clone in `WORDPRESS_IMPORTER_EXPORTS`:

```bash
git clone --depth 1 https://github.com/UAMS-Web/wordpress-importer-exports.git ../wordpress-importer-exports
WORDPRESS_IMPORTER_EXPORTS=../wordpress-importer-exports composer test
```

**Without the corpus, everything in the gate still runs.** [`ci.yml`](.github/workflows/ci.yml) and `node scripts/ci-local.mjs` run only `--testsuite=Unit,Integration`, and every job passes without the fixtures. The two standalone tests that read the corpus, `TaxonomyDestinationTest` and `AdvertCorpusImportTest`, skip when the variable is unset, naming it in the skip message. A variable that is set but names no directory fails those tests instead, so a mistyped path cannot pass as a skip. The host-app-bound `tests/Feature` audits read the corpus through the host app's own settings (`FRM_AUDIT_FIXTURES_DIR`, `GF_AUDIT_FIXTURES_DIR` in `uams-statamic`), not through this package.

**Keep the clone current.** Nothing pins it, so a clone left alone audits content that no longer resembles production; the corpus once went seven weeks and seven commits stale before anyone noticed (#474). Pull it before an audit run: `git -C ../wordpress-importer-exports pull --ff-only`.

A tree that had the old submodule initialized keeps the clone at `src/Services/WordPressImport/Tests/Exports` after updating; `.gitignore` covers that path. It can be deleted, or used as the value of `WORDPRESS_IMPORTER_EXPORTS`.

## Public surface

This package declares the surface below. **It is the surface a release decision is judged against** — a change that removes, renames, or alters the contract of anything here is breaking, and nothing else is, however `public` it happens to be. The [`cutting-releases`](.claude/rules/cutting-releases.md) rule applies that test; this section is what it reads.

The declaration is deliberately local. Measuring what `uams-statamic` consumes is the more accurate question and it makes a release decision depend on reading another repository — so the table is *seeded* from that measurement and then maintained here.

| Channel | Declared |
| --- | --- |
| Console command | `statamic:import:wordpress`, and the eight options documented on the wiki's [Command reference](https://github.com/UAMS-Web/aether-wordpress-importer/wiki/Command-reference#options): `--source`, `--site`, `--modified-after`, `--network`, `--key-from-stdin`, `--unsupported-content`, `--content-type`, `--minerva-generation` |
| Service provider | `UAMSWeb\Aether\WordPressImporter\ServiceProvider`, auto-discovered through `extra.laravel.providers`; the `ImportWordPress` command binding it performs; and the `protect.entry` middleware alias it registers |
| Classes | the 22 below |

Classes are listed relative to `UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\`. **Consumed by** records where the app reached for it when the table was last seeded — `runtime` in the app's own code, `tests` in the app's own test suite. Both count: removing either breaks the consumer.

| Class | Consumed by |
| --- | --- |
| `Assets\AssetImporter` | tests |
| `Entries\Creators\KbEntryCreator` | tests |
| `Entries\EntryCreator` | tests |
| `Globals\Creators\RedirectRuleGlobalCreator` | tests |
| `Parsers\XmlParser` | tests |
| `Prompts\MinervaKbSettings\Extractor` | tests |
| `Prompts\MinervaKbSettings\Loader` | tests |
| `Prompts\MinervaKbSettings\Prompter` | tests |
| `Prompts\MinervaKbSettings\Selector` | tests |
| `Prompts\MinervaKbSettings\Validator` | tests |
| `Structures\TreeBuilder` | tests |
| `Tiptap\SetMarkupNonce` | tests |
| `Utilities\BardParser` | tests |
| `Utilities\CollectionStructureManager` | tests |
| `Utilities\ContentProcessor` | tests |
| `Utilities\FieldDefaultHelper` | runtime |
| `Utilities\MetadataMapper` | tests |
| `Utilities\PasswordVerifier` | runtime |
| `Utilities\SearchIndexUpdates` | tests |
| `Utilities\SiteManager` | tests |
| `Utilities\TextProcessor` | tests |
| `Utilities\XpathHelper` | tests |

**Re-measure when the app's pin moves.** The declaration is a local claim about a cross-repo fact, which is exactly what makes it cheap and exactly how it drifts. Seeded on 2026-09-21 against `uams-statamic` at `2ba68850a`, pinning `^0.20`.

## Releasing

Releases are **retrospective semver tags on `main`** — a changelog checkpoint, not a deploy gate. Cut a tag whenever a batch of vetted changes has landed, and always **before `uams-statamic` consumes them**: the app pins this package by a tagged caret range (`"uams-web/aether-wordpress-importer": "^0.20"` as of 2026-09-21), not `dev-main`, so a tag has to exist for the new work to be requirable. A tag survives a force-push to `main`; a raw `dev-main` branch commit does not (the failure behind `UAMS-Web/uams-statamic`#904 / #905).

**Versioning** — stay in `0.x` until the eventual `1.0.0` (Launch). Every `0.x` tag is published as a full GitHub release, not a pre-release: the flag changes nothing Composer resolves, and the reasoning is in [`docs/DECISIONS.md`](docs/DECISIONS.md) (#634).

- `0.x.0` (minor) — a feature or structural milestone.
- `0.x.y` (patch) — refinement only.

**Cutting a release:**

```bash
# changes are merged to main; tag the tip you want to release
git checkout main && git pull
git tag -a v0.6.0 -m 'v0.6.0 — <Theme>'
git push origin v0.6.0

# publish the GitHub release (a full release, not a pre-release)
gh release create v0.6.0 --title 'v0.6.0 — <Theme>' --notes-file notes.md --verify-tag
```

**Release-note style** — title `vX.Y.Z — <Theme>` (em dash), a one-sentence milestone lead, then a closed, ordered set of `##` buckets: `Breaking changes`, `What's new`, `What's fixed`, `Security`, `Maintenance and tooling`. One linked bullet per change (`[#N]` for a PR, a backticked short SHA for a direct commit); no `by @author`; write "and", never `&`; use the Oxford comma. The full style guide and a body generator (`gen_release_notes.py`) live in the consumer repo `UAMS-Web/uams-statamic` under `.claude/skills/writing-release-notes/`.

**After tagging**, bump the constraint in `uams-statamic` (edit `composer.json`, then `composer update uams-web/aether-wordpress-importer`) when the app should adopt the new release — normally through its recurring dependency-update pass.

## Support

- Issues: [GitHub Issues](https://github.com/UAMS-Web/aether-wordpress-importer/issues)
- Source: [GitHub Repository](https://github.com/UAMS-Web/aether-wordpress-importer)

## License

Released under the [MIT License](LICENSE). Copyright (c) 2026 University of Arkansas for Medical Sciences.
