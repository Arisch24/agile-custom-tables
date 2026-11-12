<?php
/**
 * Settings storage and the agile-ct/v1/settings route.
 */

use AgileCustomTables\Settings;

$agile_ct_original = get_option( Settings::OPTION, null );
$agile_ct_request  = static function ( string $method, array $body = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/agile-ct/v1/settings' );
	if ( $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
};

try {
	delete_option( Settings::OPTION );
	agile_ct_check( 'data deletion is off by default', false === Settings::get()['delete_data_on_uninstall'] );

	wp_set_current_user( 0 );
	agile_ct_check( 'logged-out users cannot read settings', 401 === $agile_ct_request( 'GET' )->get_status() );

	wp_set_current_user( agile_ct_test_user( 'editor' ) );
	agile_ct_check( 'editors cannot read settings', 403 === $agile_ct_request( 'GET' )->get_status() );
	agile_ct_check( 'editors cannot save settings', 403 === $agile_ct_request( 'POST', array( 'delete_data_on_uninstall' => true ) )->get_status() );
	agile_ct_check( 'refused save changed nothing', false === Settings::get()['delete_data_on_uninstall'] );

	wp_set_current_user( agile_ct_test_user( 'administrator' ) );
	$agile_ct_response = $agile_ct_request( 'GET' );
	agile_ct_check( 'admins can read settings', 200 === $agile_ct_response->get_status() && false === $agile_ct_response->get_data()['delete_data_on_uninstall'] );

	$agile_ct_response = $agile_ct_request( 'POST', array( 'delete_data_on_uninstall' => true ) );
	agile_ct_check( 'admins can save settings', 200 === $agile_ct_response->get_status() && true === $agile_ct_response->get_data()['delete_data_on_uninstall'] );
	agile_ct_check( 'saved setting persists', true === Settings::get()['delete_data_on_uninstall'] );
	agile_ct_check( 'non-boolean value rejected', 400 === $agile_ct_request( 'POST', array( 'delete_data_on_uninstall' => 'maybe' ) )->get_status() );
	agile_ct_check( 'empty save changes nothing', true === $agile_ct_request( 'POST' )->get_data()['delete_data_on_uninstall'] );
} finally {
	if ( null === $agile_ct_original ) {
		delete_option( Settings::OPTION );
	} else {
		update_option( Settings::OPTION, $agile_ct_original, false );
	}
	wp_set_current_user( 0 );
}
