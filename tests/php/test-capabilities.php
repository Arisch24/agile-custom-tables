<?php
/**
 * Who may manage types and entries.
 */

use AgileCustomTables\Capabilities;

$agile_ct_roles = array(
	// Role => array( may manage types, may manage entries ).
	'administrator' => array( true, true ),
	'editor'        => array( false, true ),
	'author'        => array( false, false ),
	'contributor'   => array( false, false ),
	'subscriber'    => array( false, false ),
);
foreach ( $agile_ct_roles as $role => $expected ) {
	wp_set_current_user( agile_ct_test_user( $role ) );
	agile_ct_check( "$role: manage types", $expected[0] === current_user_can( Capabilities::manage_types() ) );
	agile_ct_check( "$role: manage entries", $expected[1] === current_user_can( Capabilities::manage_entries( 'zz_test_any' ) ) );
}

// The entries filter can lock down one type without touching the others.
wp_set_current_user( agile_ct_test_user( 'editor' ) );
$agile_ct_lock = static function ( $capability, $slug ) {
	return 'zz_test_locked' === $slug ? 'manage_options' : $capability;
};
add_filter( 'agile_ct_manage_entries_capability', $agile_ct_lock, 10, 2 );
agile_ct_check( 'filter locks one type', ! current_user_can( Capabilities::manage_entries( 'zz_test_locked' ) ) );
agile_ct_check( 'filter leaves other types alone', current_user_can( Capabilities::manage_entries( 'zz_test_open' ) ) );
remove_filter( 'agile_ct_manage_entries_capability', $agile_ct_lock, 10 );

wp_set_current_user( 0 );
