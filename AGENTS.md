# Agile Custom Tables

When reporting information to me, be extremely concise and sacrifice grammar for sake of concision.

The free WordPress.org plugin: site owners define content types in wp-admin, and each type's entries live in their own table, `{$wpdb->prefix}agile_ct_{slug}`.

Before writing or reviewing PHP, SQL or naming, read `coding_standards.md`.

## Git

Work on a feature branch and ask the user before opening a pull request, then open it with `gh pr create`; commit to `main` only when the user asks for it in that session. This repo's local git config sets a GitHub noreply address as the commit email so the user's personal email stays out of the public history; keep it.

## Architecture

- `Admin` renders the type builder and entry screens server-side; `assets/admin.js` and `assets/admin.css` are served as written, with no build step.
- `EntriesTable` (a `WP_List_Table`) is built in `Admin::load_entries()` on `load-{$hook}`, so bulk actions and page redirects run before any output.
- `src/settings/` is the React settings app, built by `@wordpress/scripts` into `build/`. Use `@wordpress/components`; WordPress supplies React and the `@wordpress/*` packages at runtime.
- `Capabilities` holds both permission checks, each filterable: content types need `manage_options` (Administrators), entries `edit_others_posts` (Editors and up).
- `uninstall.php` is self-contained and drops data only when the "delete data on uninstall" setting is on.

## Free and Pro

This plugin stays fully functional: WordPress.org forbids locked features, quotas and trial limits.
