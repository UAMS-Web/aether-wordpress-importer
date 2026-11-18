Written against v0.35.0.

By the end of this page you will have imported one content type from a WordPress export file into a Statamic site, and seen the entries in the Control Panel.

It assumes the package is installed (see [Installation (README)](https://github.com/UAMS-Web/aether-wordpress-importer#installation)) in a Statamic application with at least one site configured, and that you have a WordPress WXR export: in WordPress, **Tools, Export**, then **Download Export File**.

## 1. Put the export where PHP can read it

Copy the `.xml` file into the application, for example `storage/app/imports/example-site.xml`. The command refuses a path it cannot read, so a permissions problem shows up before anything is imported.

## 2. Start the command

```bash
php artisan statamic:import:wordpress storage/app/imports/example-site.xml
```

The command scans the export and lists the content types it found with a count for each. Types it does not import are listed too, so you can see what will be left behind.

## 3. Choose the site and the content type

Answer the prompts for the Statamic site to import into and the content type to import, for example `page`. Where the type has a default destination (`page` goes to the `pages` collection), it is used; otherwise the command asks for one. The defaults are on [Content types and destinations](Content-types-and-destinations.md#default-destinations).

## 4. Read the warnings

Before anything is written, the command warns about content that usually needs review by hand: Classic editor content, and Row, Details and Freeform blocks. You choose whether to continue.

**Note:** an unattended run cannot answer this prompt. `--unsupported-content` sets the answer ahead of time; see [Command reference](Command-reference.md#options).

## 5. Choose what happens on a collision

For each kind of data the type carries (entries, assets, tables, redirects), choose whether an item that already exists is overwritten, skipped, or imported as an additional item. The choices are on [Content types and destinations](Content-types-and-destinations.md#overwrite-choices).

## 6. Let it import, then stop or continue

The command imports the entries and their assets, enables any taxonomy or collection the content needs on the site, and builds the collection tree. It then asks whether to import another content type from the same file. Answer **no** when you are done: the command then finalizes the run (clears the Stache and caches, runs the deferred work such as navigation, sanitizes the entries and prints the warnings it collected).

## 7. Check the result

Open the destination collection in the Control Panel. The imported entries are there, and a structured collection such as `pages` has its tree built from the WordPress parent pages. If they are not, see [An import does not show in the Control Panel](Troubleshooting.md#an-import-does-not-show-in-the-control-panel).

## Go further

- [Previewing an import with verify](Previewing-an-import-with-verify.md): run the same import against a copy first.
- [Importing from the migration API](Importing-from-the-migration-API.md): read the site directly instead of from an export file.
- [Importing navigation menus](Importing-navigation-menus.md) and [Importing MinervaKB articles](Importing-MinervaKB-articles.md): the two content types with their own flow.
