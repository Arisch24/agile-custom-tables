<?php
/**
 * Plugin Name:       Agile Custom Tables
 * Description:       Define your own content types and store their data in dedicated database tables instead of wp_posts and wp_postmeta.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Arischvaran Puvanesvaran
 * Author URI:        https://github.com/Arisch24
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agile-custom-tables
 *
 * @package AgileCustomTables
 */

defined( 'ABSPATH' ) || exit;

define( 'CCT_VERSION', '0.1.0' );
define( 'CCT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CCT_URL', plugin_dir_url( __FILE__ ) );

require_once CCT_DIR . 'includes/class-cct-registry.php';
require_once CCT_DIR . 'includes/class-cct-data.php';
require_once CCT_DIR . 'includes/class-cct-admin.php';

register_activation_hook( __FILE__, array( 'CCT_Registry', 'sync_all_tables' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( is_admin() ) {
			CCT_Admin::init();
		}
	}
);
