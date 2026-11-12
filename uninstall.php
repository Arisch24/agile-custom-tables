<?php
/**
 * Removes plugin data when the plugin is deleted, but only on sites that
 * opted in on the Settings screen. Deactivating never runs this file.
 *
 * Self-contained on purpose: WordPress does not load the plugin (or its
 * autoloader) before running it.
 *
 * @package AgileCustomTables
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$agile_ct_uninstall_site = static function (): void {
	global $wpdb;

	$settings = get_option( 'agile_ct_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// Every table with the plugin's prefix, including those of content types
	// that were deleted earlier (their tables are kept until now).
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'agile_ct_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- names come from SHOW TABLES.
	}

	delete_option( 'agile_ct_types' );
	delete_option( 'agile_ct_settings' );
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_agile_ct_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_agile_ct_' ) . '%'
		)
	);
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $agile_ct_site_id ) {
		switch_to_blog( $agile_ct_site_id );
		$agile_ct_uninstall_site();
		restore_current_blog();
	}
} else {
	$agile_ct_uninstall_site();
}
