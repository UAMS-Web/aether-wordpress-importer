Written against v0.35.0.

You want a WordPress menu in Statamic navigation. Menu items (`nav_menu_item`) have their own flow, because a menu links to content that has to be imported first.

## Steps

1. Import the pages and posts the menu links to, from the same export, before the menu. A link to a post type resolves to the imported Statamic entry, so an entry that does not exist yet cannot be linked.
2. Choose `nav_menu_item` as the content type. The command confirms the active primary menu context (in the UAMS themes, a theme label such as "White Bar" or "Primary Navigation Menu").
3. Choose the WordPress menu to import from the menus the export holds. Only items from that menu are imported (`category domain="nav_menu"`).
4. Finish the run. Navigation is imported during finalization, after you answer **no** to importing more content types.

**Warning:** the site's existing navigation is cleared before the menu is imported.

## What is carried across

| WordPress item | In Statamic |
| --- | --- |
| Custom URL (`_menu_item_type: custom`) | A link to `_menu_item_url` |
| Post type link (`_menu_item_type: post_type`) | A link to the imported entry |
| Taxonomy link (`_menu_item_type: taxonomy`) | Skipped |

Parent and child items and their order are kept (`_menu_item_menu_item_parent`). A collection the menu links into may be enabled on the site when linked entries exist.

## Reference

- [Content types and destinations](Content-types-and-destinations.md): `nav_menu_item` goes to `primary_navigation`.
- The [technical reference](https://github.com/UAMS-Web/aether-wordpress-importer/blob/main/docs/technical-reference.md) covers `NavigationMenuSelector` and `NavigationMenuImporter`.
