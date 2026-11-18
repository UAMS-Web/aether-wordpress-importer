Where the importer is developed, how to report a problem, and where the developer documentation lives.

- [Report a problem](https://github.com/UAMS-Web/aether-wordpress-importer/issues)
- [Releases](https://github.com/UAMS-Web/aether-wordpress-importer/releases)
- [Repository](https://github.com/UAMS-Web/aether-wordpress-importer)

## For developers

The developer documentation stays in the repository, next to the code it describes:

- [README](https://github.com/UAMS-Web/aether-wordpress-importer#readme): development, the test fixtures, the declared public surface a release decision is judged against, and how releases are cut.
- [Technical reference](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/technical-reference.md): the command workflow, the class catalog, configuration flags and other internals.
- [Migration API contract](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/migration-api-contract.md): endpoints, pagination and query semantics.
- [Decision log](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/DECISIONS.md): decisions that span tickets, with the alternatives rejected.

## Editing this wiki

These pages are kept in [`docs/wiki/`](https://github.com/UAMS-Web/aether-wordpress-importer/tree/main/docs/wiki) and published by the `Publish wiki` workflow when a release is published, or by hand. Each publish replaces the whole wiki with that folder, so an edit made in the wiki itself is lost; change the page in the repository through a pull request instead.
