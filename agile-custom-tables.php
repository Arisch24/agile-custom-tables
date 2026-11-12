<?php
/**
 * Plugin Name:       Agile Custom Tables
 * Description:       Define your own content types and store their entries in dedicated database tables instead of wp_posts and wp_postmeta.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Arischvaran Puvanesvaran
 * Author URI:        https://github.com/Arisch24
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agile-custom-tables
 *
 * @package AgileCustomTables
 */

namespace AgileCustomTables;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A source checkout needs `composer install` before the classes can load.
if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Agile Custom Tables is missing its autoloader. Run "composer install" in the plugin folder.', 'agile-custom-tables' );
			echo '</p></div>';
		}
	);
	return;
}

require __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( Registry::class, 'sync_all_tables' ) );

( new Plugin( __FILE__ ) )->boot();
