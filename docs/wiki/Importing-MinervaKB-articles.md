Written against v0.35.0.

You want a MinervaKB knowledge base in Statamic: the knowledge base itself, its topics and its articles (`kb`).

## Steps

1. Check that every article has a topic. The command warns about MinervaKB entries without a topic assignment before anything is imported; an unattended run decides with `--unsupported-content`.
2. Choose `kb` as the content type. Articles go to the `knowledge_bases` collection.
3. With `--source=api`, say which MinervaKB generation the site runs if the command cannot tell; see [The MinervaKB generation](#the-minervakb-generation).
4. Finish the run. The tree is built as knowledge base, then topics, then articles.

## How the structure is mapped

- The root knowledge base entry uses the site name from `resources/sites.yaml`.
- Topics come from `wp:term` with `wp:term_taxonomy` `kbtopic`; articles are `wp:post_type` `kb`.
- Each article uses **one** topic: the first in the article's category list.
- The tree is built by `MinervaKBProcessor`, not the generic page `TreeBuilder`.

**Note:** MinervaKB content restrictions are not imported. Configure them again in Statamic, and on the source site as needed.

## The MinervaKB generation

With `--source=api`, where the migration API serves the installed plugin's header, read from disk, its `Text Domain` decides the generation and `--minerva-generation` is not needed; a declaration that header contradicts is refused. Otherwise the option is required to import from a site that serves both generations' settings rows. Read the generation from the network plugins page (`wp-admin/network/plugins.php`) before the run. A declared generation whose row was not served is refused, and so is the option on a file-sourced run.

## Reference

- [Command reference](Command-reference.md): `--minerva-generation` and `--unsupported-content`.
- The [technical reference](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/technical-reference.md) covers the MinervaKB processor classes.
