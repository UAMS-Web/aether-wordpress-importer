Written against v0.35.0.

The package registers one console command, `statamic:import:wordpress`, through `UAMSWeb\Aether\WordPressImporter\ServiceProvider`. The command class is `UAMSWeb\Aether\WordPressImporter\Console\Commands\ImportWordPress`.

## Signature

```bash
statamic:import:wordpress [<file>] [--source=wxr|api] [--site=<id>] [--modified-after=<when>]
                          [--network=<url>] [--key-from-stdin]
                          [--unsupported-content=abort|skip|import] [--content-type=<type>]
                          [--minerva-generation=v1|v2] [--verify]
```

`<file>` is **optional**: with `--source=wxr` it is the export to read, and with `--source=api` it is the directory the run writes the WXR it reads into.

## Options

| Option | Default | What it does |
| --- | --- | --- |
| `--source` | `wxr` | Where content comes from: `wxr` (a local export file) or `api` (the migration API). |
| `--site` | none | The network site id to read, with `--source=api`. A positive whole number; anything else refuses the run. |
| `--modified-after` | none | Read only posts modified after this timestamp, with `--source=api`. `YYYY-MM-DD`, `"YYYY-MM-DD HH:MM"` (GMT, seconds optional), or ISO 8601; anything else is refused by the API. |
| `--network` | none | The network's WordPress address, with `--source=api`. An absolute `https://` URL, supplying without a prompt what the run would otherwise ask for. Required by `--key-from-stdin`. |
| `--key-from-stdin` | off | Read the network's migration API key from the first line of standard input instead of asking for it, so an API-sourced run needs no terminal. Requires `--network`. |
| `--unsupported-content` | `abort` | Non-interactive policy for content carrying unsupported blocks (Classic, Freeform, Details), or KB entries without topics: `abort`, `skip` or `import`. |
| `--content-type` | none | The single content type to import, for an unattended run. Without it a non-interactive run imports the first supported type, reports the rest as untouched, and exits non-zero. |
| `--minerva-generation` | none | The MinervaKB generation the site runs, `v1` or `v2`, with `--source=api`. See [Importing MinervaKB articles](Importing-MinervaKB-articles.md#the-minervakb-generation) for when it is needed and when it is refused. |
| `--verify` | off | Run the whole import against a temporary copy of the content and assets, print the same counts, and leave the application's own directories unchanged. See [Previewing an import with verify](Previewing-an-import-with-verify.md). |

**`--site` does not name a network**, only a site within one: a blog id is unique only inside its own network, so the same id means a different site on each. The network comes from `--network`, or from the address you paste at the prompt when that option is absent.

**No option carries the migration API key as a value.** It is asked for, hidden, or read with `--key-from-stdin`; see [Importing from the migration API](Importing-from-the-migration-API.md#3-put-nothing-in-this-application).

## Examples

Import from an export file, answering the prompts:

```bash
php artisan statamic:import:wordpress storage/app/imports/example-site.xml
```

Import pages unattended, skipping content with unsupported blocks:

```bash
php artisan statamic:import:wordpress storage/app/imports/example-site.xml --content-type=page --unsupported-content=skip
```

Read posts changed since a date from a site in a network:

```bash
php artisan statamic:import:wordpress storage/app/imports/example-site --source=api --network=https://wordpress.example.test --site=3 --modified-after=2026-01-01
```

## Prompts

Prompts use **Laravel Prompts** where it is supported (macOS, Linux, WSL), with a fallback to Symfony Console questions elsewhere.

## Configuration

The host application can set `migration_api.per_page` (environment variable `UAMSWP_MIGRATION_API_PER_PAGE`, default 100, clamped by the API to 200). The other environment flags, such as `WORDPRESS_IMPORT_MEASURE_FILESIZE`, are listed under Configuration in the [technical reference](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/technical-reference.md). The package has no configuration file to publish.

## Starting from a clean log

To clear Laravel's cache and truncate the log before a run:

- **macOS and Linux:**

  ```bash
  php artisan cache:clear && > storage/logs/laravel.log && php artisan statamic:import:wordpress storage/app/imports/example-site.xml
  ```

- **Windows (cmd):**

  ```bat
  php artisan cache:clear && echo. > storage\logs\laravel.log && php artisan statamic:import:wordpress storage\app\imports\example-site.xml
  ```
