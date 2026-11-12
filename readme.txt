=== Agile Custom Tables ===
Contributors: arisch24
Tags: custom tables, content types, custom fields, database, data
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Define content types in the admin and store their entries in dedicated database tables instead of posts and post meta.

== Description ==

Agile Custom Tables lets you build your own content types, such as Events, Products or Locations, without code. Each content type gets a real database table with one column per field, so your data stays fast to query and simple to work with, instead of being spread across wp_posts and wp_postmeta.

* Build content types with text, textarea, number, email, URL, date, checkbox and select fields.
* Add, edit and delete entries from their own admin screens.
* Editors and Administrators manage entries; only Administrators change the structure.
* Every entry records who created it and when.
* Removing a field or a content type never deletes data: columns and tables stay in place.
* Changes that would convert existing data, such as turning a Text field into a Number field, are blocked.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/agile-custom-tables/`, or install it from the Plugins screen.
2. Activate it.
3. Go to Content Types > Add Type to create your first content type.

== Frequently Asked Questions ==

= Where is my data stored? =

In a table named after the content type, for example `wp_agile_ct_events` for a type with the slug `events`.

= What happens to my data if I deactivate or delete the plugin? =

Deactivating never removes anything. Deleting the plugin also keeps your tables, unless you turn on "Delete all data when the plugin is deleted" under Content Types > Settings.

= Who can manage entries? =

Editors and Administrators. Only Administrators can create or change content types. Developers can change both with the `agile_ct_manage_types_capability` and `agile_ct_manage_entries_capability` filters.

== Changelog ==

= 0.1.0 =
* First release.
