# Agile Custom Tables

The free WordPress.org plugin: site owners define content types in wp-admin, and each type's entries live in their own table, `{$wpdb->prefix}agile_ct_{slug}`. Runtime code targets WordPress 6.6+ and PHP 7.4+, so write PHP 7.4 syntax in `agile-custom-tables.php`, `uninstall.php` and `includes/`, and keep the plugin header and `readme.txt` requirements in sync.

## Git

Work on a feature branch and open a pull request with `gh pr create`; commit to `main` only when the user asks for it in that session. This repo's local git config sets a GitHub noreply address as the commit email so the user's personal email stays out of the public history; keep it.

## Naming

Text domain `agile-custom-tables`, global prefixes `agile_ct_` / `AGILE_CT_`, admin slugs and handles `agile-ct-*`, PHP namespace `AgileCustomTables\` (PSR-4 in `includes/`). WordPress.org rejects two- and three-letter prefixes.

## Architecture

- `Admin` renders the type builder and entry screens server-side; `assets/admin.js` and `assets/admin.css` are served as written, with no build step.
- `EntriesTable` (a `WP_List_Table`) is built in `Admin::load_entries()` on `load-{$hook}`, so bulk actions and page redirects run before any output.
- `src/settings/` is the React settings app, built by `@wordpress/scripts` into `build/`. Use `@wordpress/components`; WordPress supplies React and the `@wordpress/*` packages at runtime.
- `Capabilities` holds both permission checks, each filterable: content types need `manage_options` (Administrators), entries `edit_others_posts` (Editors and up).
- `uninstall.php` is self-contained and drops data only when the "delete data on uninstall" setting is on.

## Data safety

Entries are the site owner's data; every change keeps them intact.

- Columns and tables are dropped only in `uninstall.php`. Removing a field or a type leaves its column or table in place.
- A field type change keeps stored values as they are: `Registry::sanitize_type()` allows only VARCHAR to LONGTEXT, including for a removed field re-added under the same key.
- Field keys stay clear of `Registry::RESERVED` (the plugin's own columns, plus words `dbDelta()` reads as index definitions).
- Numbers go to `$wpdb` as `%s`, because `%f` rounds to six decimals.
- SQL identifiers such as sort and search columns come from the type definition; request values reach SQL only as placeholders.
- Deleting a user keeps their entries: `users_have_additional_content` makes WordPress ask who takes them over, and "Delete all content" clears `created_by`.

## Free and Pro

This plugin stays fully functional: WordPress.org forbids locked features, quotas and trial limits.
