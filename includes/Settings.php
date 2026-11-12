<?php
/**
 * Plugin settings: storage, REST route and the React settings screen.
 *
 * @package AgileCustomTables
 */

namespace AgileCustomTables;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stores settings in one option and serves them to the settings app.
 */
final class Settings {

	/**
	 * Option holding the settings array.
	 */
	public const OPTION = 'agile_ct_settings';

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'agile-ct-settings';

	/**
	 * REST namespace for the plugin's internal routes.
	 */
	public const REST_NAMESPACE = 'agile-ct/v1';

	/**
	 * Absolute path to the build folder, with a trailing slash.
	 *
	 * @var string
	 */
	private string $build_dir;

	/**
	 * URL of the build folder, with a trailing slash.
	 *
	 * @var string
	 */
	private string $build_url;

	/**
	 * Hook suffix of the settings screen, set once the menu is registered.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Constructor.
	 *
	 * @param string $build_dir Absolute path to the build folder, with a trailing slash.
	 * @param string $build_url URL of the build folder, with a trailing slash.
	 */
	public function __construct( string $build_dir, string $build_url ) {
		$this->build_dir = $build_dir;
		$this->build_url = $build_url;
	}

	/**
	 * Current settings, with defaults filled in and unknown keys dropped.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'delete_data_on_uninstall' => ! empty( $saved['delete_data_on_uninstall'] ),
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 20 ); // After the content type entries.
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register GET/POST agile-ct/v1/settings.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'rest_update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'delete_data_on_uninstall' => array(
							'type'     => 'boolean',
							'required' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Settings are part of shaping content types, so they share its capability.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( Capabilities::manage_types() );
	}

	/**
	 * GET handler.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_get(): WP_REST_Response {
		return new WP_REST_Response( self::get() );
	}

	/**
	 * POST handler. Only the settings sent are changed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_update( WP_REST_Request $request ): WP_REST_Response {
		$settings = self::get();
		if ( $request->has_param( 'delete_data_on_uninstall' ) ) {
			$settings['delete_data_on_uninstall'] = (bool) $request->get_param( 'delete_data_on_uninstall' );
		}
		update_option( self::OPTION, $settings, false );
		return new WP_REST_Response( self::get() );
	}

	/**
	 * Add the Settings screen as the last item under Content Types.
	 *
	 * @return void
	 */
	public function menu(): void {
		$hook       = add_submenu_page(
			Admin::MENU,
			__( 'Settings', 'agile-custom-tables' ),
			__( 'Settings', 'agile-custom-tables' ),
			Capabilities::manage_types(),
			self::PAGE,
			array( $this, 'render' )
		);
		$this->hook = $hook ? $hook : '';
	}

	/**
	 * Path to the built app's asset manifest.
	 *
	 * @return string
	 */
	private function asset_file(): string {
		return $this->build_dir . 'settings.asset.php';
	}

	/**
	 * Enqueue the settings app on its own screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function assets( string $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook || ! is_readable( $this->asset_file() ) ) {
			return;
		}
		$asset = require $this->asset_file();

		wp_enqueue_script( 'agile-ct-settings', $this->build_url . 'settings.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'agile-ct-settings', 'agile-custom-tables' );
		wp_add_inline_script(
			'agile-ct-settings',
			'window.agileCtSettings = ' . wp_json_encode(
				array(
					'settings' => self::get(),
					'path'     => '/' . self::REST_NAMESPACE . '/settings',
				)
			) . ';',
			'before'
		);
		wp_enqueue_style( 'agile-ct-settings', $this->build_url . 'settings.css', array( 'wp-components' ), $asset['version'] );
	}

	/**
	 * Screen markup. The header is server-rendered so admin notices land
	 * below it; the React app mounts into the root.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'agile-custom-tables' ), 403 );
		}

		echo '<header class="agile-ct-header"><div class="agile-ct-header__inner">';
		echo '<span class="agile-ct-header__brand">' . esc_html__( 'Agile Custom Tables', 'agile-custom-tables' ) . '</span>';
		echo '<h1 class="agile-ct-header__title">' . esc_html__( 'Settings', 'agile-custom-tables' ) . '</h1>';
		echo '</div></header>';
		echo '<div class="wrap agile-ct-settings"><hr class="wp-header-end">';

		if ( ! is_readable( $this->asset_file() ) ) {
			echo '<div class="notice notice-error inline"><p>';
			esc_html_e( 'The settings screen has not been built. Run "npm install && npm run build" in the plugin folder.', 'agile-custom-tables' );
			echo '</p></div></div>';
			return;
		}

		echo '<div id="agile-ct-settings-root"><p>' . esc_html__( 'Loading…', 'agile-custom-tables' ) . '</p></div>';
		echo '</div>';
	}
}
