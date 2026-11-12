<?php
/**
 * uninstall.php honours the "delete all data" setting.
 *
 * The routine drops every agile_ct_ table on the site, so these checks only
 * run when the site has no content types or tables of its own.
 */

use AgileCustomTables\Registry;
use AgileCustomTables\Settings;

global $wpdb;

$agile_ct_test_prefix = Registry::table( 'zz_test_' );
$agile_ct_own_tables  = array_filter(
	$wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . Registry::TABLE_PREFIX ) . '%' ) ),
	static function ( $table ) use ( $agile_ct_test_prefix ) {
		return 0 !== strpos( $table, $agile_ct_test_prefix );
	}
);
$agile_ct_own_types   = array_filter(
	array_keys( Registry::all() ),
	static function ( $slug ) {
		return 0 !== strpos( $slug, 'zz_test_' );
	}
);
if ( $agile_ct_own_tables || $agile_ct_own_types ) {
	WP_CLI::log( 'Skipped uninstall checks: this site has its own content types or tables.' );
	return;
}

$agile_ct_saved_settings = get_option( Settings::OPTION, null );
$agile_ct_saved_types    = get_option( Registry::OPTION, null );
$agile_ct_uninstall      = static function (): void {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'agile-custom-tables/agile-custom-tables.php' );
	}
	require dirname( __DIR__, 2 ) . '/uninstall.php';
};

try {
	agile_ct_test_type( 'zz_test_keep', array( array( 'label' => 'Name' ) ) );
	agile_ct_test_type( 'zz_test_orphan', array( array( 'label' => 'Name' ) ) );
	Registry::delete_type( 'zz_test_orphan' ); // Its table stays behind.

	update_option( Settings::OPTION, array( 'delete_data_on_uninstall' => false ), false );
	$agile_ct_uninstall();
	agile_ct_check( 'uninstall keeps data unless opted in', agile_ct_table_exists( Registry::table( 'zz_test_keep' ) ) && null !== Registry::get( 'zz_test_keep' ) );

	update_option( Settings::OPTION, array( 'delete_data_on_uninstall' => true ), false );
	set_transient( 'agile_ct_notice_0', array( 'success', 'x' ), 60 );
	$agile_ct_uninstall();
	wp_cache_flush(); // Options were removed with direct queries.
	agile_ct_check( 'opted-in uninstall drops type tables', ! agile_ct_table_exists( Registry::table( 'zz_test_keep' ) ) );
	agile_ct_check( 'opted-in uninstall drops tables of deleted types', ! agile_ct_table_exists( Registry::table( 'zz_test_orphan' ) ) );
	agile_ct_check( 'opted-in uninstall deletes options', false === get_option( Registry::OPTION ) && false === get_option( Settings::OPTION ) );
	agile_ct_check(
		'opted-in uninstall deletes transients',
		0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( '_transient_agile_ct_' ) . '%' ) )
	);
} finally {
	foreach ( array(
		Settings::OPTION => $agile_ct_saved_settings,
		Registry::OPTION => $agile_ct_saved_types,
	) as $agile_ct_option => $agile_ct_value ) {
		if ( null === $agile_ct_value ) {
			delete_option( $agile_ct_option );
		} else {
			update_option( $agile_ct_option, $agile_ct_value, false );
		}
	}
}
