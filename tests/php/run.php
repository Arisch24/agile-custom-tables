<?php
/**
 * Run every tests/php/test-*.php file inside WordPress:
 * ddev wp eval-file /var/www/html/wp-content/plugins/agile-custom-tables/tests/php/run.php
 */

require __DIR__ . '/bootstrap.php';

// Database errors are printed, not thrown: capture output to catch them.
ob_start();
agile_ct_test_cleanup();
foreach ( glob( __DIR__ . '/test-*.php' ) as $agile_ct_test ) {
	try {
		require $agile_ct_test;
	} catch ( Throwable $agile_ct_error ) {
		agile_ct_check( basename( $agile_ct_test ) . ' ran to the end', false, $agile_ct_error->getMessage() );
	}
}
agile_ct_test_cleanup();
restore_error_handler();
$agile_ct_output = ob_get_clean();
echo $agile_ct_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
agile_ct_check( 'no database errors', false === strpos( $agile_ct_output, 'wpdberror' ) );

if ( $GLOBALS['agile_ct_failures'] ) {
	WP_CLI::error( $GLOBALS['agile_ct_failures'] . ' of ' . $GLOBALS['agile_ct_checks'] . ' checks failed' );
}
WP_CLI::success( $GLOBALS['agile_ct_checks'] . ' checks passed' );
