<?php
/**
 * Who may do what.
 *
 * @package AgileCustomTables
 */

namespace AgileCustomTables;

/**
 * Capabilities for the two kinds of work in the plugin: shaping content
 * types (structure) and managing their entries (content).
 */
final class Capabilities {

	/**
	 * Capability needed to create, edit and delete content types and to
	 * change plugin settings.
	 *
	 * @return string
	 */
	public static function manage_types(): string {
		/**
		 * Filters the capability needed to manage content types and settings.
		 *
		 * @param string $capability Default 'manage_options' (Administrators).
		 */
		return (string) apply_filters( 'agile_ct_manage_types_capability', 'manage_options' );
	}

	/**
	 * Capability needed to add, edit and delete the entries of one content type.
	 *
	 * Entries are shared rather than owned, so the default matches editing
	 * other people's posts: Editors and Administrators.
	 *
	 * @param string $slug Content type slug.
	 * @return string
	 */
	public static function manage_entries( string $slug ): string {
		/**
		 * Filters the capability needed to manage one content type's entries.
		 *
		 * @param string $capability Default 'edit_others_posts' (Editors and Administrators).
		 * @param string $slug       Content type slug.
		 */
		return (string) apply_filters( 'agile_ct_manage_entries_capability', 'edit_others_posts', $slug );
	}
}
