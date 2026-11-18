Written against v0.35.0.

What the importer reads from WordPress, where each kind of content lands in Statamic, and what a run does in order.

## Content types

| WordPress type | What it is | Default destination |
| --- | --- | --- |
| `page` | Pages | `pages` collection |
| `post` | Posts | `post` collection |
| `uamspeople` | People | `people` collection |
| `redirect_rule` | Redirect rules | `redirects` global set |
| `tablepress_table` | TablePress tables | `tables` collection |
| `kb` | MinervaKB articles | `knowledge_bases` collection |
| `nav_menu_item` | Navigation menu items | `primary_navigation` |

Other post types in the export are reported but not imported.

## Default destinations

The destinations in the table above are used when they exist. When a default destination is not available, the command asks for one.

## Overwrite choices

For each kind of data a content type carries, the command asks how an item that already exists is handled:

| Data | Choices |
| --- | --- |
| Entries | Overwrite, skip, or create additional entries for matching ids |
| Assets | Overwrite, skip, or create additional files (when uploads are enabled) |
| Tables | The same choices, when TablePress content applies |
| Redirects | Choices for matching source URLs in the redirect globals |

## Collections, globals and taxonomies enabled for the site

The importer enables a collection, global set or taxonomy for the selected site when the content needs it. For example:

| Enabled | When |
| --- | --- |
| `people_group` | `uamspeople` imports, or pages using `show-people` shortcodes |
| `region` and `location_type` | Pages with Find-a-Doc blocks |
| `logos_item_categories` | Pages with logo list blocks |

## Safeguards

Before import, the command warns about content patterns that usually need review by hand, and you choose whether to continue:

- Classic editor content (no Gutenberg block wrappers)
- WordPress Row blocks
- WordPress Details blocks
- WordPress Freeform blocks
- MinervaKB entries without required topic assignments

An unattended run answers with `--unsupported-content` ([Command reference](Command-reference.md#options)).

## What a run does, in order

1. Validates the export path.
2. Scans the export and shows the content types found, with counts.
3. Asks for the target site and content type.
4. Uses the default destination, or asks for one.
5. Asks for the overwrite choices that apply (entries, assets, tables, redirects).
6. Imports the content and its assets.
7. Validates and enables the taxonomies and site configuration the content needs.
8. Builds collection trees where needed.
9. Offers to import another content type from the same export.

When you answer **no** to importing more content types, the command finalizes: it clears the Stache, runs deferred work (including navigation import where it applies), optionally measures the change in codebase file size (`WORDPRESS_IMPORT_MEASURE_FILESIZE`), clears the relevant caches, runs Bard and YAML sanitization on the collection entries, shows the warnings it collected, and completes.

The per-step scans, the streaming parser and the completion order are described in the [technical reference](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/technical-reference.md).

## Two types with their own flow

- [Importing navigation menus](Importing-navigation-menus.md): `nav_menu_item`.
- [Importing MinervaKB articles](Importing-MinervaKB-articles.md): `kb`.
