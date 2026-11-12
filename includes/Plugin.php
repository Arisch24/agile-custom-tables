<?php
/**
 * Plugin bootstrap.
 *
 * @package AgileCustomTables
 */

namespace AgileCustomTables;

/**
 * Hooks the plugin's parts into WordPress.
 */
final class Plugin {

	/**
	 * Plugin version, used to bust asset caches. Keep in sync with the plugin header.
	 */
	public const VERSION = '0.1.0';

	/**
	 * Absolute path to the plugin folder, with a trailing slash.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * URL of the plugin folder, with a trailing slash.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Constructor.
	 *
	 * @param string $file Path to the main plugin file.
	 */
	public function __construct( string $file ) {
		$this->dir = plugin_dir_path( $file );
		$this->url = plugin_dir_url( $file );
	}

	/**
	 * Register every part's hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'deleted_user', array( Entries::class, 'reassign_author' ), 10, 2 );

		( new Settings( $this->dir . 'build/', $this->url . 'build/' ) )->register_hooks();

		if ( is_admin() ) {
			( new Admin( $this->url . 'assets/' ) )->register_hooks();
		}
	}
}
