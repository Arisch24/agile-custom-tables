# Agile Custom Tables contributor rules

Agile Custom Tables lets site owners define content types in the admin and stores each type's entries in its own database table. This is the free plugin for WordPress.org. Runtime code supports WordPress 6.6+ and PHP 7.4+; keep the plugin header and `readme.txt` requirements in sync, and use PHP 7.4 syntax and APIs in `agile-custom-tables.php`, `uninstall.php` and `includes/`.

## Architecture

- `includes/`: PSR-4 PHP classes, namespace `AgileCustomTables\`.
  - `Registry`: type definitions (option `agile_ct_types`) and table sync through `dbDelta()`.
  - `Entries`: entry CRUD, search/sort/paging queries, sanitising and `created_by` handling.
  - `Capabilities`: `manage_types()` (default `manage_options`) and `manage_entries( $slug )` (default `edit_others_posts`), both filterable.
  - `Admin`: server-rendered type builder and entry screens; `assets/admin.js` and `assets/admin.css` are plain files, not built.
  - `EntriesTable`: the entry list, a `WP_List_Table`. `Admin::load_entries()` builds it on `load-{$hook}` and runs bulk actions there, before any output.
  - `Settings`: option `agile_ct_settings`, REST route `agile-ct/v1/settings`, and the React settings screen.
- `src/settings/`: the settings app, built with `@wordpress/scripts` into `build/settings.*`. Use `@wordpress/components`; WordPress provides React and the `@wordpress/*` packages at runtime.
- `uninstall.php`: self-contained; drops data only when `delete_data_on_uninstall` is on.
- `tests/php/`: checks run inside WordPress. `bin/check-admin.mjs` drives the admin in a browser.

Use text domain `agile-custom-tables`, global prefixes `agile_ct_` / `AGILE_CT_`, admin slugs and handles `agile-ct-*`, and tables `{$wpdb->prefix}agile_ct_{slug}`. WordPress.org rejects two- and three-letter prefixes.

## Data safety

- Never drop a column or table outside `uninstall.php`. Removing a field or a type leaves its column or table in place.
- A field type change must not convert stored data: `Registry::sanitize_type()` rejects any column type change other than VARCHAR to LONGTEXT, including a removed field re-added under the same key.
- Field keys may not use `Registry::RESERVED` (plugin columns and words `dbDelta()` reads as index definitions).
- Pass numbers to `$wpdb` as `%s`; `%f` rounds to six decimals.
- Deleting a user never deletes entries. `users_have_additional_content` makes WordPress ask who takes over a user with entries; the entries go to that user, or `created_by` is cleared if "Delete all content" is chosen.
- Sort columns and search columns come from the type definition only; never put request values into SQL identifiers.

## Free and Pro

This plugin must stay fully functional: WordPress.org forbids locked features, quotas and trial limits. Paid features live in a separate Agile Custom Tables Pro add-on that extends this plugin through actions and filters. Licence checks and update code belong only in Pro.

## Verification

WSL has no PHP. Run PHP and Composer inside DDEV:

```sh
npm test
npm run lint:js
npm run lint:css
ddev exec -d /var/www/html/wp-content/plugins/agile-custom-tables composer lint
npm run check:admin
```

`npm test` rebuilds the settings app and runs the PHP checks. All gates must exit zero, and with `WP_DEBUG` enabled plugin code must produce no warnings, notices, deprecations or database errors. Checks may only create content types whose slug starts with `zz_test_` and users whose login starts with `test-agile-ct-`, and must remove them and restore any setting they change. The uninstall checks skip themselves on a site that has its own content types.

`npm run release` builds the installable ZIP in `dist/`.
