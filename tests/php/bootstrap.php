<?php
/**
 * Helpers for checks executed inside WordPress by `npm run test:php`.
 *
 * Checks only create content types whose slug starts with zz_test_ and users
 * whose login starts with test-agile-ct-, and remove them again.
 */

use AgileCustomTables\Registry;

$GLOBALS['agile_ct_checks']   = 0;
$GLOBALS['agile_ct_failures'] = 0;

function agile_ct_check( string $label, bool $passed, string $detail = '' ): void {
	++$GLOBALS['agile_ct_checks'];
	if ( ! $passed ) {
		++$GLOBALS['agile_ct_failures'];
		WP_CLI::warning( $label . ( '' !== $detail ? ': ' . $detail : '' ) );
	}
}

/** Create a throwaway user with a role. */
function agile_ct_test_user( string $role ): int {
	$suffix = strtolower( wp_generate_password( 8, false ) );
	$id     = wp_insert_user(
		array(
			'user_login' => 'test-agile-ct-' . $role . '-' . $suffix,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'test-agile-ct-' . $suffix . '@example.invalid',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return $id;
}

/** Save a test content type from form-style input. */
function agile_ct_test_type( string $slug, array $fields ): array {
	$type = Registry::sanitize_type(
		array(
			'slug'     => $slug,
			'singular' => 'Thing',
			'plural'   => 'Things',
			'fields'   => $fields,
		)
	);
	if ( is_wp_error( $type ) ) {
		throw new RuntimeException( $type->get_error_message() );
	}
	Registry::save_type( $type );
	return $type;
}

/** Turn a saved type back into form input (select options as text). */
function agile_ct_as_input( array $type ): array {
	foreach ( $type['fields'] as &$field ) {
		$field['options'] = implode( "\n", $field['options'] );
	}
	return $type;
}

/** Whether a table exists. */
function agile_ct_table_exists( string $table ): bool {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/** Remove every test user, test type and test table. */
function agile_ct_test_cleanup(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_set_current_user( 0 );

	// Types first, so deleting users does not touch test tables.
	foreach ( array_keys( Registry::all() ) as $slug ) {
		if ( 0 === strpos( $slug, 'zz_test_' ) ) {
			Registry::delete_type( $slug, true );
		}
	}
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Registry::table( 'zz_test_' ) ) . '%' ) );
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
	$users = get_users(
		array(
			'search'         => 'test-agile-ct-*',
			'search_columns' => array( 'user_login' ),
			'fields'         => 'ID',
		)
	);
	foreach ( $users as $id ) {
		wp_delete_user( (int) $id );
	}
}

// Any warning, notice or deprecation raised from plugin code fails the run.
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		if ( 0 === strpos( $file, dirname( __DIR__, 2 ) . '/' ) ) {
			agile_ct_check( 'no plugin PHP diagnostics', false, "$message at $file:$line" );
			return true;
		}
		return false;
	}
);
