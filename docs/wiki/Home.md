The WordPress Importer moves content from WordPress into Statamic through one interactive Artisan command, `statamic:import:wordpress`. It reads a WordPress XML (WXR) export file, or reads a site directly through the UAMS migration API, and maps pages, posts, people, redirects, tables, knowledge base articles and navigation menus into Statamic collections, globals and navigation, with their assets.

This wiki holds the long-form documentation: a first import, task guides, the reference and troubleshooting. The [README](https://github.com/UAMS-Web/aether-wordpress-importer#readme) keeps the overview, the requirements, installation, the package's declared public surface and how releases are cut. Internals (class catalog, scans, configuration flags) are in the [technical reference](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/technical-reference.md).

## Getting started

- [Installation (README)](https://github.com/UAMS-Web/aether-wordpress-importer#installation): add the package to a Statamic application.
- [Your first import](Your-first-import.md): import one content type from a WXR file, from the command to the entries in the Control Panel.

## Guides

- [Importing from the migration API](Importing-from-the-migration-API.md): issue a credential on the WordPress side, check it, and run an import that reads the site directly.
- [Previewing an import with verify](Previewing-an-import-with-verify.md): see what an import would do without changing the application.
- [Importing navigation menus](Importing-navigation-menus.md): bring a WordPress menu into Statamic navigation.
- [Importing MinervaKB articles](Importing-MinervaKB-articles.md): bring a knowledge base, its topics and its articles across.

## Troubleshooting

- [Troubleshooting](Troubleshooting.md): one entry per symptom, with its cause and fix.

## Reference

- [Command reference](Command-reference.md): the command's signature and every option.
- [Content types and destinations](Content-types-and-destinations.md): what is imported, where it lands, the overwrite choices, the safeguards and what a run does in order.

## Project

- [Project](Project.md): where the importer is developed, how to report a problem, and where the developer documentation lives.
