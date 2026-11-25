<?php
/**
 * Admin screens.
 *
 * @package AgileCustomTables
 */

namespace AgileCustomTables;

/**
 * Type builder (for whoever may manage types) and per-type entry screens
 * (for whoever may manage that type's entries).
 */
final class Admin {

	/**
	 * Menu slug of the content type list, also the parent of every screen.
	 */
	public const MENU = 'agile-ct-types';

	/**
	 * URL of the assets folder, with a trailing slash.
	 *
	 * @var string
	 */
	private string $assets_url;

	/**
	 * The entry list being shown, built before the screen renders.
	 *
	 * @var EntriesTable|null
	 */
	private ?EntriesTable $table = null;

	/**
	 * Constructor.
	 *
	 * @param string $assets_url URL of the assets folder, with a trailing slash.
	 */
	public function __construct( string $assets_url ) {
		$this->assets_url = $assets_url;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_agile_ct_save_type', array( $this, 'handle_save_type' ) );
		add_action( 'admin_post_agile_ct_delete_type', array( $this, 'handle_delete_type' ) );
		add_action( 'admin_post_agile_ct_save_entry', array( $this, 'handle_save_entry' ) );
		add_action( 'admin_post_agile_ct_delete_entry', array( $this, 'handle_delete_entry' ) );
		add_action( 'delete_user_form', array( $this, 'delete_user_note' ), 10, 2 );
	}

	/**
	 * Add the menu. Users who may only manage entries see the type screens
	 * hidden, and WordPress points the top-level item at their first type.
	 *
	 * @return void
	 */
	public function menu(): void {
		$types_cap = Capabilities::manage_types();

		add_menu_page(
			__( 'Content Types', 'agile-custom-tables' ),
			__( 'Content Types', 'agile-custom-tables' ),
			$types_cap,
			self::MENU,
			array( $this, 'page_types' ),
			'dashicons-database',
			58
		);
		add_submenu_page( self::MENU, __( 'Content Types', 'agile-custom-tables' ), __( 'All Types', 'agile-custom-tables' ), $types_cap, self::MENU, array( $this, 'page_types' ) );
		add_submenu_page( self::MENU, __( 'Add Content Type', 'agile-custom-tables' ), __( 'Add Type', 'agile-custom-tables' ), $types_cap, 'agile-ct-type-new', array( $this, 'page_type_new' ) );

		foreach ( Registry::all() as $slug => $type ) {
			$hook = add_submenu_page(
				self::MENU,
				$type['plural'],
				$type['plural'],
				Capabilities::manage_entries( $slug ),
				'agile-ct-entries-' . $slug,
				function () use ( $slug ): void {
					$this->page_entries( $slug );
				}
			);
			if ( $hook ) {
				add_action(
					'load-' . $hook,
					function () use ( $slug ): void {
						$this->load_entries( $slug );
					}
				);
			}
		}
	}

	/**
	 * Enqueue the type builder and entry screen assets.
	 *
	 * @return void
	 */
	public function assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, 'agile-ct-' ) || Settings::PAGE === $page ) {
			return;
		}
		wp_enqueue_style( 'agile-ct-admin', $this->assets_url . 'admin.css', array(), Plugin::VERSION );
		wp_enqueue_script( 'agile-ct-admin', $this->assets_url . 'admin.js', array(), Plugin::VERSION, true );
	}

	/**
	 * Stop unless the user may manage content types.
	 *
	 * @return void
	 */
	private function guard_types(): void {
		if ( ! current_user_can( Capabilities::manage_types() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'agile-custom-tables' ), 403 );
		}
	}

	/**
	 * Stop unless the user may manage this type's entries.
	 *
	 * @param string $slug Type slug.
	 * @return void
	 */
	private function guard_entries( string $slug ): void {
		if ( ! current_user_can( Capabilities::manage_entries( $slug ) ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'agile-custom-tables' ), 403 );
		}
	}

	/**
	 * Queue a notice for the next screen this user loads.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Plain-text message.
	 * @return void
	 */
	private function notice( string $type, string $message ): void {
		set_transient( 'agile_ct_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	/**
	 * Print and clear the queued notice.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$key  = 'agile_ct_notice_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! $data ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( 'error' === $data[0] ? 'error' : 'success' ),
			esc_html( $data[1] )
		);
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Destination.
	 * @return void
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * URL of the type list.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	private function types_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::MENU ) );
	}

	/**
	 * URL of a type's entry screens.
	 *
	 * @param string $slug Type slug.
	 * @param array  $args Query args.
	 * @return string
	 */
	private function entries_url( string $slug, array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=agile-ct-entries-' . $slug ) );
	}

	/**
	 * Type list, or the edit form when ?type= is set.
	 *
	 * @return void
	 */
	public function page_types(): void {
		$this->guard_types();

		$edit = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $edit && Registry::get( $edit ) ) {
			$this->render_type_form( Registry::get( $edit ) );
			return;
		}

		$types = Registry::all();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Content Types', 'agile-custom-tables' ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=agile-ct-type-new' ) ) . '">' . esc_html__( 'Add New', 'agile-custom-tables' ) . '</a><hr class="wp-header-end">';
		$this->render_notice();

		if ( empty( $types ) ) {
			echo '<p>' . esc_html__( 'No content types yet. Create one to get started.', 'agile-custom-tables' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'agile-custom-tables' ), __( 'Table', 'agile-custom-tables' ), __( 'Fields', 'agile-custom-tables' ), __( 'Entries', 'agile-custom-tables' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $types as $slug => $type ) {
			$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=agile_ct_delete_type&type=' . $slug ), 'agile_ct_delete_type_' . $slug );
			echo '<tr><td><strong><a href="' . esc_url( $this->entries_url( $slug ) ) . '">' . esc_html( $type['plural'] ) . '</a></strong>';
			echo '<div class="row-actions visible">';
			echo '<a href="' . esc_url( $this->entries_url( $slug ) ) . '">' . esc_html__( 'Entries', 'agile-custom-tables' ) . '</a> | ';
			echo '<a href="' . esc_url( $this->types_url( array( 'type' => $slug ) ) ) . '">' . esc_html__( 'Edit type', 'agile-custom-tables' ) . '</a> | ';
			echo '<a class="agile-ct-confirm" data-confirm="' . esc_attr__( 'Delete this content type? Its table and entries are kept in the database.', 'agile-custom-tables' ) . '" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete type', 'agile-custom-tables' ) . '</a>';
			echo '</div></td>';
			echo '<td><code>' . esc_html( Registry::table( $slug ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) count( $type['fields'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( Entries::count( $type ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Empty type form.
	 *
	 * @return void
	 */
	public function page_type_new(): void {
		$this->guard_types();
		$this->render_type_form( null );
	}

	/**
	 * Type form, for a new type (null) or an existing one.
	 *
	 * @param array|null $type Type definition.
	 * @return void
	 */
	private function render_type_form( ?array $type ): void {
		$is_edit = is_array( $type );
		$old     = get_transient( 'agile_ct_form_' . get_current_user_id() );
		if ( ! $is_edit && is_array( $old ) ) {
			$type = $old; // Re-show submitted values after a validation error.
		}
		if ( $is_edit && is_array( $old ) && isset( $old['slug'] ) && $old['slug'] === $type['slug'] ) {
			$type = $old;
		}
		delete_transient( 'agile_ct_form_' . get_current_user_id() );

		$type = wp_parse_args(
			is_array( $type ) ? $type : array(),
			array(
				'slug'     => '',
				'singular' => '',
				'plural'   => '',
				'fields'   => array( $this->blank_field() ),
			)
		);

		$existing_keys = array();
		$saved         = $is_edit ? Registry::get( $type['slug'] ) : null;
		if ( $saved ) {
			$existing_keys = wp_list_pluck( $saved['fields'], 'key' );
		}

		echo '<div class="wrap"><h1>' . esc_html( $is_edit ? __( 'Edit Content Type', 'agile-custom-tables' ) : __( 'Add Content Type', 'agile-custom-tables' ) ) . '</h1>';
		$this->render_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="agile-ct-type-form">';
		echo '<input type="hidden" name="action" value="agile_ct_save_type">';
		echo '<input type="hidden" name="is_edit" value="' . ( $is_edit ? '1' : '0' ) . '">';
		wp_nonce_field( 'agile_ct_save_type' );

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="agile-ct-singular">' . esc_html__( 'Singular name', 'agile-custom-tables' ) . '</label></th><td><input type="text" id="agile-ct-singular" name="singular" class="regular-text" value="' . esc_attr( $type['singular'] ) . '" placeholder="' . esc_attr__( 'Event', 'agile-custom-tables' ) . '" required></td></tr>';
		echo '<tr><th><label for="agile-ct-plural">' . esc_html__( 'Plural name', 'agile-custom-tables' ) . '</label></th><td><input type="text" id="agile-ct-plural" name="plural" class="regular-text" value="' . esc_attr( $type['plural'] ) . '" placeholder="' . esc_attr__( 'Events', 'agile-custom-tables' ) . '" required></td></tr>';
		echo '<tr><th><label for="agile-ct-slug">' . esc_html__( 'Slug', 'agile-custom-tables' ) . '</label></th><td><input type="text" id="agile-ct-slug" name="slug" class="regular-text" value="' . esc_attr( $type['slug'] ) . '" placeholder="event" pattern="[a-z][a-z0-9_]{0,31}" required' . ( $is_edit ? ' readonly' : '' ) . '>';
		echo '<p class="description">' . esc_html__( 'Used in the table name. Lowercase letters, numbers and underscores. Cannot be changed later.', 'agile-custom-tables' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Fields', 'agile-custom-tables' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each field becomes a column. Field keys cannot be changed once saved. Removing a field keeps its column and data in the table.', 'agile-custom-tables' ) . '</p>';
		echo '<table class="widefat agile-ct-fields"><thead><tr><th>' . esc_html__( 'Label', 'agile-custom-tables' ) . '</th><th>' . esc_html__( 'Key (column)', 'agile-custom-tables' ) . '</th><th>' . esc_html__( 'Type', 'agile-custom-tables' ) . '</th><th>' . esc_html__( 'Options (select, one per line)', 'agile-custom-tables' ) . '</th><th>' . esc_html__( 'Required', 'agile-custom-tables' ) . '</th><th></th></tr></thead><tbody id="agile-ct-fields-body">';
		foreach ( array_values( $type['fields'] ) as $i => $field ) {
			$this->render_field_row( (string) $i, $field, in_array( $field['key'], $existing_keys, true ) );
		}
		echo '</tbody></table>';

		echo '<p><button type="button" class="button" id="agile-ct-add-field">' . esc_html__( 'Add field', 'agile-custom-tables' ) . '</button></p>';

		echo '<template id="agile-ct-field-template">';
		$this->render_field_row( '__i__', $this->blank_field(), false );
		echo '</template>';

		submit_button( $is_edit ? __( 'Update Type', 'agile-custom-tables' ) : __( 'Create Type', 'agile-custom-tables' ) );
		echo '</form></div>';
	}

	/**
	 * An empty field row's values.
	 *
	 * @return array
	 */
	private function blank_field(): array {
		return array(
			'key'      => '',
			'label'    => '',
			'type'     => 'text',
			'options'  => array(),
			'required' => false,
		);
	}

	/**
	 * One row of the field table.
	 *
	 * @param string $i      Row index (or a template placeholder).
	 * @param array  $field  Field definition.
	 * @param bool   $locked Whether the key is saved and can no longer change.
	 * @return void
	 */
	private function render_field_row( string $i, array $field, bool $locked ): void {
		$n = 'fields[' . $i . ']';
		echo '<tr class="agile-ct-field-row">';
		echo '<td><input type="text" name="' . esc_attr( $n ) . '[label]" value="' . esc_attr( $field['label'] ) . '" class="agile-ct-label" aria-label="' . esc_attr__( 'Label', 'agile-custom-tables' ) . '"></td>';
		echo '<td><input type="text" name="' . esc_attr( $n ) . '[key]" value="' . esc_attr( $field['key'] ) . '" class="agile-ct-key" aria-label="' . esc_attr__( 'Key', 'agile-custom-tables' ) . '"' . ( $locked ? ' readonly' : '' ) . '></td>';
		echo '<td><select name="' . esc_attr( $n ) . '[type]" class="agile-ct-type" aria-label="' . esc_attr__( 'Type', 'agile-custom-tables' ) . '">';
		foreach ( Registry::field_types() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $field['type'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><textarea name="' . esc_attr( $n ) . '[options]" rows="2" class="agile-ct-options" aria-label="' . esc_attr__( 'Options', 'agile-custom-tables' ) . '">' . esc_textarea( implode( "\n", (array) $field['options'] ) ) . '</textarea></td>';
		echo '<td><input type="checkbox" name="' . esc_attr( $n ) . '[required]" value="1" aria-label="' . esc_attr__( 'Required', 'agile-custom-tables' ) . '"' . checked( ! empty( $field['required'] ), true, false ) . '></td>';
		echo '<td><button type="button" class="button-link button-link-delete agile-ct-remove-field">' . esc_html__( 'Remove', 'agile-custom-tables' ) . '</button></td>';
		echo '</tr>';
	}

	/**
	 * Save a new or edited type.
	 *
	 * @return void
	 */
	public function handle_save_type(): void {
		$this->guard_types();
		check_admin_referer( 'agile_ct_save_type' );

		$raw     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in Registry::sanitize_type().
		$is_edit = ! empty( $raw['is_edit'] );
		$slug    = isset( $raw['slug'] ) ? sanitize_key( $raw['slug'] ) : '';

		if ( $is_edit && ! Registry::get( $slug ) ) {
			$this->redirect( $this->types_url() );
		}

		$type = Registry::sanitize_type( $raw, $is_edit );

		if ( is_wp_error( $type ) ) {
			$this->notice( 'error', $type->get_error_message() );
			// Keep what the user typed so they do not lose their work.
			set_transient(
				'agile_ct_form_' . get_current_user_id(),
				array(
					'slug'     => isset( $raw['slug'] ) ? sanitize_text_field( $raw['slug'] ) : '',
					'singular' => isset( $raw['singular'] ) ? sanitize_text_field( $raw['singular'] ) : '',
					'plural'   => isset( $raw['plural'] ) ? sanitize_text_field( $raw['plural'] ) : '',
					'fields'   => $this->echo_back_fields( $raw ),
				),
				60
			);
			$this->redirect( $is_edit ? $this->types_url( array( 'type' => $slug ) ) : admin_url( 'admin.php?page=agile-ct-type-new' ) );
		}

		Registry::save_type( $type );
		$this->notice( 'success', __( 'Content type saved.', 'agile-custom-tables' ) );
		$this->redirect( $this->types_url() );
	}

	/**
	 * Light sanitising of submitted field rows, only for re-displaying the form.
	 *
	 * @param array $raw Unslashed POST data.
	 * @return array[]
	 */
	private function echo_back_fields( array $raw ): array {
		$out   = array();
		$types = array_keys( Registry::field_types() );
		foreach ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? $raw['fields'] : array() as $row ) {
			$out[] = array(
				'key'      => isset( $row['key'] ) ? sanitize_text_field( $row['key'] ) : '',
				'label'    => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
				'type'     => isset( $row['type'] ) && in_array( $row['type'], $types, true ) ? $row['type'] : 'text',
				'options'  => isset( $row['options'] ) ? array_filter( array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) $row['options'] ) ) ) : array(),
				'required' => ! empty( $row['required'] ),
			);
		}
		return $out;
	}

	/**
	 * Remove a type definition, keeping its table.
	 *
	 * @return void
	 */
	public function handle_delete_type(): void {
		$this->guard_types();
		$slug = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		check_admin_referer( 'agile_ct_delete_type_' . $slug );

		Registry::delete_type( $slug, false );
		$this->notice( 'success', __( 'Content type removed. Its database table was left untouched.', 'agile-custom-tables' ) );
		$this->redirect( $this->types_url() );
	}

	/**
	 * Entry list, add form or edit form for one type.
	 *
	 * @param string $slug Type slug.
	 * @return void
	 */
	public function page_entries( string $slug ): void {
		$this->guard_entries( $slug );
		$type = Registry::get( $slug );
		if ( ! $type ) {
			wp_die( esc_html__( 'Unknown content type.', 'agile-custom-tables' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'add' === $action ) {
			$this->render_entry_form( $type, null );
		} elseif ( 'edit' === $action ) {
			$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$entry = Entries::get( $type, $id );
			if ( ! $entry ) {
				wp_die( esc_html__( 'Entry not found.', 'agile-custom-tables' ) );
			}
			$this->render_entry_form( $type, $entry );
		} else {
			$this->render_entry_list( $type );
		}
	}

	/**
	 * Before an entry list renders: run a bulk action, tidy the URL after a
	 * search, and load the page of entries.
	 *
	 * @param string $slug Type slug.
	 * @return void
	 */
	public function load_entries( string $slug ): void {
		$type   = Registry::get( $slug );
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $type || in_array( $action, array( 'add', 'edit' ), true ) ) {
			return;
		}
		$this->guard_entries( $slug );

		$this->table = new EntriesTable( $type, $this->entries_url( $slug ) );
		$back        = $this->entries_url( $slug, $this->list_state() );

		if ( 'delete' === $this->table->current_action() ) {
			check_admin_referer( EntriesTable::BULK_NONCE );
			$ids     = isset( $_REQUEST['entry'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['entry'] ) ) : array();
			$deleted = Entries::delete_many( $type, $ids );
			$this->notice(
				'success',
				/* translators: %s: number of entries */
				sprintf( _n( '%s entry deleted.', '%s entries deleted.', $deleted, 'agile-custom-tables' ), number_format_i18n( $deleted ) )
			);
			$this->redirect( $back );
		}

		// The list form submits by GET; drop its nonce and empty bulk action from the URL.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: only rebuilds the list URL.
		if ( ! empty( $_GET['_wp_http_referer'] ) ) {
			// Keep the page number for a typed page, not for a new search.
			parse_str( (string) wp_parse_url( sanitize_url( wp_unslash( $_GET['_wp_http_referer'] ) ), PHP_URL_QUERY ), $previous );
			$same_search = ( isset( $previous['s'] ) && is_string( $previous['s'] ) ? trim( sanitize_text_field( $previous['s'] ) ) : '' ) === EntriesTable::search_term();
			$paged       = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 0;
			$this->redirect( $this->entries_url( $slug, $this->list_state() + ( $same_search && $paged > 1 ? array( 'paged' => $paged ) : array() ) ) );
		}
		// phpcs:enable

		$this->table->prepare_items();
	}

	/**
	 * Search and sort arguments of the current list, to keep after an action.
	 *
	 * @return array
	 */
	private function list_state(): array {
		$state = array();
		foreach ( array( 'orderby', 'order' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$state[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		$search = EntriesTable::search_term();
		if ( '' !== $search ) {
			$state['s'] = rawurlencode( $search );
		}
		return $state;
	}

	/**
	 * Entry list with search, sorting, bulk delete and paging.
	 *
	 * @param array $type Type definition.
	 * @return void
	 */
	private function render_entry_list( array $type ): void {
		$slug   = $type['slug'];
		$table  = $this->table ? $this->table : new EntriesTable( $type, $this->entries_url( $slug ) );
		$search = EntriesTable::search_term();
		if ( ! $this->table ) {
			$table->prepare_items();
		}

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html( $type['plural'] ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( $this->entries_url( $slug, array( 'action' => 'add' ) ) ) . '">' . esc_html__( 'Add New', 'agile-custom-tables' ) . '</a>';
		if ( '' !== $search ) {
			/* translators: %s: search term */
			echo '<span class="subtitle">' . sprintf( esc_html__( 'Search results for: %s', 'agile-custom-tables' ), '<strong>' . esc_html( $search ) . '</strong>' ) . '</span>';
		}
		echo '<hr class="wp-header-end">';
		$this->render_notice();

		echo '<form method="get" id="agile-ct-entries-form" data-confirm="' . esc_attr__( 'Delete the selected entries? This cannot be undone.', 'agile-custom-tables' ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( 'agile-ct-entries-' . $slug ) . '">';
		if ( Entries::is_searchable( $type ) ) {
			$table->search_box( __( 'Search entries', 'agile-custom-tables' ), 'agile-ct-search' );
		}
		$table->display();
		echo '</form></div>';
	}

	/**
	 * On the Delete Users screen, say what happens to entries.
	 *
	 * @param \WP_User $current_user Current user.
	 * @param int[]    $user_ids     Users being deleted.
	 * @return void
	 */
	public function delete_user_note( $current_user, $user_ids = array() ): void {
		if ( ! Entries::authored_by_any( (array) $user_ids ) ) {
			return;
		}
		echo '<p class="agile-ct-delete-user-note">' . esc_html__( 'Content type entries are never deleted with a user. If you choose "Delete all content", their entries stay and no longer have an author.', 'agile-custom-tables' ) . '</p>';
	}

	/**
	 * Entry form, for a new entry (null) or an existing one.
	 *
	 * @param array      $type  Type definition.
	 * @param array|null $entry Entry row.
	 * @return void
	 */
	private function render_entry_form( array $type, ?array $entry ): void {
		$slug    = $type['slug'];
		$is_edit = is_array( $entry );
		$old     = get_transient( 'agile_ct_entry_' . get_current_user_id() );
		delete_transient( 'agile_ct_entry_' . get_current_user_id() );
		$values = is_array( $old ) ? array_merge( $is_edit ? $entry : array(), $old ) : ( $is_edit ? $entry : array() );

		/* translators: %s: content type singular name */
		$title = $is_edit ? sprintf( __( 'Edit %s', 'agile-custom-tables' ), $type['singular'] ) : sprintf( __( 'Add %s', 'agile-custom-tables' ), $type['singular'] );

		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		$this->render_notice();

		if ( $is_edit && ! empty( $entry['created_at'] ) ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			echo '<p class="description agile-ct-entry-meta">' . esc_html(
				sprintf(
					/* translators: 1: author name, 2: date created, 3: date last updated */
					__( 'Created by %1$s on %2$s. Last updated %3$s.', 'agile-custom-tables' ),
					Entries::author_name( $entry ),
					get_date_from_gmt( $entry['created_at'], $format ),
					get_date_from_gmt( ! empty( $entry['updated_at'] ) ? $entry['updated_at'] : $entry['created_at'], $format )
				)
			) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="agile_ct_save_entry">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $slug ) . '">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( $is_edit ? (int) $entry['id'] : 0 ) ) . '">';
		wp_nonce_field( 'agile_ct_save_entry_' . $slug );

		echo '<table class="form-table" role="presentation">';
		foreach ( $type['fields'] as $field ) {
			$key   = $field['key'];
			$name  = 'agile_ct[' . $key . ']';
			$id    = 'agile-ct-f-' . $key;
			$value = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . ( $field['required'] ? ' <span class="required">*</span>' : '' ) . '</label></th><td>';
			switch ( $field['type'] ) {
				case 'textarea':
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5" class="large-text">' . esc_textarea( $value ) . '</textarea>';
					break;
				case 'number':
					echo '<input type="number" step="any" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
					break;
				case 'email':
					echo '<input type="email" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
					break;
				case 'url':
					echo '<input type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
					break;
				case 'date':
					echo '<input type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
					break;
				case 'checkbox':
					echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . '>';
					break;
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">&mdash;</option>';
					foreach ( $field['options'] as $opt ) {
						echo '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
					}
					echo '</select>';
					break;
				default:
					echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
			}
			echo '</td></tr>';
		}
		echo '</table>';

		submit_button( $is_edit ? __( 'Update', 'agile-custom-tables' ) : __( 'Save', 'agile-custom-tables' ) );
		echo ' <a href="' . esc_url( $this->entries_url( $slug ) ) . '">' . esc_html__( 'Cancel', 'agile-custom-tables' ) . '</a>';
		echo '</form></div>';
	}

	/**
	 * Save a new or edited entry.
	 *
	 * @return void
	 */
	public function handle_save_entry(): void {
		$slug = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- needed to build the nonce action, verified next.
		$this->guard_entries( $slug );
		check_admin_referer( 'agile_ct_save_entry_' . $slug );

		$type = Registry::get( $slug );
		if ( ! $type ) {
			$this->redirect( admin_url() );
		}

		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$raw = isset( $_POST['agile_ct'] ) && is_array( $_POST['agile_ct'] ) ? wp_unslash( $_POST['agile_ct'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field type in Entries::sanitize().

		$clean = Entries::sanitize( $type, $raw );
		if ( is_wp_error( $clean ) ) {
			$this->notice( 'error', $clean->get_error_message() );
			$keep = array();
			foreach ( $raw as $k => $v ) {
				$keep[ sanitize_key( $k ) ] = is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : '';
			}
			set_transient( 'agile_ct_entry_' . get_current_user_id(), $keep, 60 );
			$this->redirect(
				$id
					? $this->entries_url(
						$slug,
						array(
							'action' => 'edit',
							'id'     => $id,
						)
					)
					: $this->entries_url( $slug, array( 'action' => 'add' ) )
			);
		}

		if ( $id ) {
			if ( ! Entries::get( $type, $id ) ) {
				$this->notice( 'error', __( 'Entry not found.', 'agile-custom-tables' ) );
				$this->redirect( $this->entries_url( $slug ) );
			}
			$ok = Entries::update( $type, $id, $clean );
		} else {
			$ok = (bool) Entries::insert( $type, $clean );
		}

		$this->notice( $ok ? 'success' : 'error', $ok ? __( 'Saved.', 'agile-custom-tables' ) : __( 'Could not save. Check the database error log.', 'agile-custom-tables' ) );
		$this->redirect( $this->entries_url( $slug ) );
	}

	/**
	 * Delete an entry.
	 *
	 * @return void
	 */
	public function handle_delete_entry(): void {
		$slug = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- needed to build the nonce action, verified next.
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$this->guard_entries( $slug );
		check_admin_referer( 'agile_ct_delete_entry_' . $slug . '_' . $id );

		$type = Registry::get( $slug );
		if ( $type && $id ) {
			Entries::delete( $type, $id );
			$this->notice( 'success', __( 'Deleted.', 'agile-custom-tables' ) );
		}
		$this->redirect( $type ? $this->entries_url( $slug ) : admin_url() );
	}
}
