# Coding standards

## Targets

Runtime code targets WordPress 6.6+ and PHP 7.4+, so write PHP 7.4 syntax in `agile-custom-tables.php`, `uninstall.php` and `includes/`, and keep the plugin header and `readme.txt` requirements in sync.

## Naming

Text domain `agile-custom-tables`, global prefixes `agile_ct_` / `AGILE_CT_`, admin slugs and handles `agile-ct-*`, PHP namespace `AgileCustomTables\` (PSR-4 in `includes/`). WordPress.org rejects two- and three-letter prefixes.

## Data safety

Entries are the site owner's data; every change keeps them intact.

- Columns and tables are dropped only in `uninstall.php`. Removing a field or a type leaves its column or table in place.
- A field type change keeps stored values as they are: `Registry::sanitize_type()` allows only VARCHAR to LONGTEXT, including for a removed field re-added under the same key.
- Field keys stay clear of `Registry::RESERVED` (the plugin's own columns, plus words `dbDelta()` reads as index definitions).
- Numbers go to `$wpdb` as `%s`, because `%f` rounds to six decimals.
- SQL identifiers such as sort and search columns come from the type definition; request values reach SQL only as placeholders.
- Deleting a user keeps their entries: `users_have_additional_content` makes WordPress ask who takes them over, and "Delete all content" clears `created_by`.
