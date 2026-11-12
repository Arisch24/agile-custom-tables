<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screens: type builder and per-type data management.
 */
class CCT_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_cct_save_type', array( __CLASS__, 'handle_save_type' ) );
		add_action( 'admin_post_cct_delete_type', array( __CLASS__, 'handle_delete_type' ) );
		add_action( 'admin_post_cct_save_item', array( __CLASS__, 'handle_save_item' ) );
		add_action( 'admin_post_cct_delete_item', array( __CLASS__, 'handle_delete_item' ) );
	}

	/** Capability required for everything in this plugin. */
	public static function cap() {
		return apply_filters( 'cct_capability', 'manage_options' );
	}

	/* ------------------------------------------------------------------ */
	/* Menus and assets                                                    */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_menu_page(
			__( 'Content Types', 'agile-custom-tables' ),
			__( 'Content Types', 'agile-custom-tables' ),
			self::cap(),
			'cct-types',
			array( __CLASS__, 'page_types' ),
			'dashicons-database',
			58
		);
		add_submenu_page( 'cct-types', __( 'Manage Types', 'agile-custom-tables' ), __( 'Manage Types', 'agile-custom-tables' ), self::cap(), 'cct-types', array( __CLASS__, 'page_types' ) );
		add_submenu_page( 'cct-types', __( 'Add Type', 'agile-custom-tables' ), __( 'Add Type', 'agile-custom-tables' ), self::cap(), 'cct-type-new', array( __CLASS__, 'page_type_new' ) );

		foreach ( CCT_Registry::all() as $slug => $type ) {
			add_submenu_page(
				'cct-types',
				$type['plural'],
				$type['plural'],
				self::cap(),
				'cct-data-' . $slug,
				function () use ( $slug ) {
					self::page_data( $slug );
				}
			);
		}
	}

	public static function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, 'cct-' ) ) {
			return;
		}
		wp_enqueue_style( 'cct-admin', CCT_URL . 'assets/admin.css', array(), CCT_VERSION );
		wp_enqueue_script( 'cct-admin', CCT_URL . 'assets/admin.js', array(), CCT_VERSION, true );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	protected static function guard() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'agile-custom-tables' ), 403 );
		}
	}

	protected static function notice( $type, $message ) {
		set_transient( 'cct_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	protected static function render_notice() {
		$key  = 'cct_notice_' . get_current_user_id();
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

	protected static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	protected static function types_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=cct-types' ) );
	}

	protected static function data_url( $slug, $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=cct-data-' . $slug ) );
	}

	/* ------------------------------------------------------------------ */
	/* Type list and builder                                               */
	/* ------------------------------------------------------------------ */

	public static function page_types() {
		self::guard();

		$edit = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $edit && CCT_Registry::get( $edit ) ) {
			self::render_type_form( CCT_Registry::get( $edit ) );
			return;
		}

		$types = CCT_Registry::all();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Content Types', 'agile-custom-tables' ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=cct-type-new' ) ) . '">' . esc_html__( 'Add New', 'agile-custom-tables' ) . '</a><hr class="wp-header-end">';
		self::render_notice();

		if ( empty( $types ) ) {
			echo '<p>' . esc_html__( 'No content types yet. Create one to get started.', 'agile-custom-tables' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'agile-custom-tables' ), __( 'Table', 'agile-custom-tables' ), __( 'Fields', 'agile-custom-tables' ), __( 'Rows', 'agile-custom-tables' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $types as $slug => $type ) {
			$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=cct_delete_type&type=' . $slug ), 'cct_delete_type_' . $slug );
			echo '<tr><td><strong><a href="' . esc_url( self::data_url( $slug ) ) . '">' . esc_html( $type['plural'] ) . '</a></strong>';
			echo '<div class="row-actions visible">';
			echo '<a href="' . esc_url( self::data_url( $slug ) ) . '">' . esc_html__( 'Data', 'agile-custom-tables' ) . '</a> | ';
			echo '<a href="' . esc_url( self::types_url( array( 'type' => $slug ) ) ) . '">' . esc_html__( 'Edit type', 'agile-custom-tables' ) . '</a> | ';
			echo '<a class="cct-confirm" data-confirm="' . esc_attr__( 'Delete this content type? Its table and data are kept in the database.', 'agile-custom-tables' ) . '" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete type', 'agile-custom-tables' ) . '</a>';
			echo '</div></td>';
			echo '<td><code>' . esc_html( CCT_Registry::table( $slug ) ) . '</code></td>';
			echo '<td>' . esc_html( count( $type['fields'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( CCT_Data::count( $type ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_type_new() {
		self::guard();
		self::render_type_form( null );
	}

	protected static function render_type_form( $type ) {
		$is_edit = is_array( $type );
		$old     = get_transient( 'cct_form_' . get_current_user_id() );
		if ( ! $is_edit && is_array( $old ) ) {
			$type = $old; // Re-show submitted values after a validation error.
		}
		if ( $is_edit && is_array( $old ) && isset( $old['slug'] ) && $old['slug'] === $type['slug'] ) {
			$type = $old;
		}
		delete_transient( 'cct_form_' . get_current_user_id() );

		$type = wp_parse_args(
			is_array( $type ) ? $type : array(),
			array(
				'slug'     => '',
				'singular' => '',
				'plural'   => '',
				'fields'   => array(
					array( 'key' => '', 'label' => '', 'type' => 'text', 'options' => array(), 'required' => false ),
				),
			)
		);

		$existing_keys = array();
		if ( $is_edit && CCT_Registry::get( $type['slug'] ) ) {
			foreach ( CCT_Registry::get( $type['slug'] )['fields'] as $f ) {
				$existing_keys[] = $f['key'];
			}
		}

		echo '<div class="wrap"><h1>' . esc_html( $is_edit ? __( 'Edit Content Type', 'agile-custom-tables' ) : __( 'Add Content Type', 'agile-custom-tables' ) ) . '</h1>';
		self::render_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cct-type-form">';
		echo '<input type="hidden" name="action" value="cct_save_type">';
		echo '<input type="hidden" name="is_edit" value="' . ( $is_edit ? '1' : '0' ) . '">';
		wp_nonce_field( 'cct_save_type' );

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="cct-singular">' . esc_html__( 'Singular name', 'agile-custom-tables' ) . '</label></th><td><input type="text" id="cct-singular" name="singular" class="regular-text" value="' . esc_attr( $type['singular'] ) . '" placeholder="Event" required></td></tr>';
		echo '<tr><th><label for="cct-plural">' . esc_html__( 'Plural name', 'agile-custom-tables' ) . '</label></th><td><input type="text" id="cct-plural" name="plural" class="regular-text" value="' . esc_attr( $type['plural'] ) . '" placeholder="Events" required></td></tr>';
		echo '<tr><th><label for="cct-slug">' . esc_html__( 'Slug', 'agile-custom-tables' ) . '</label></th><td><input type="text" id="cct-slug" name="slug" class="regular-text" value="' . esc_attr( $type['slug'] ) . '" placeholder="event" pattern="[a-z][a-z0-9_]{0,31}" required' . ( $is_edit ? ' readonly' : '' ) . '>';
		echo '<p class="description">' . esc_html__( 'Used in the table name. Lowercase letters, numbers and underscores. Cannot be changed later.', 'agile-custom-tables' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Fields', 'agile-custom-tables' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each field becomes a column. Field keys cannot be changed once saved. Removing a field keeps its column and data in the table.', 'agile-custom-tables' ) . '</p>';
		echo '<table class="widefat cct-fields"><thead><tr><th>' . esc_html__( 'Label', 'agile-custom-tables' ) . '</th><th>' . esc_html__( 'Key (column)', 'agile-custom-tables' ) . '</th><th>' . esc_html__( 'Type', 'agile-custom-tables' ) . '</th><th>' . esc_html__( 'Options (select, one per line)', 'agile-custom-tables' ) . '</th><th>' . esc_html__( 'Required', 'agile-custom-tables' ) . '</th><th></th></tr></thead><tbody id="cct-fields-body">';
		foreach ( array_values( $type['fields'] ) as $i => $field ) {
			self::render_field_row( $i, $field, in_array( $field['key'], $existing_keys, true ) );
		}
		echo '</tbody></table>';

		echo '<p><button type="button" class="button" id="cct-add-field">' . esc_html__( 'Add field', 'agile-custom-tables' ) . '</button></p>';

		echo '<template id="cct-field-template">';
		self::render_field_row( '__i__', array( 'key' => '', 'label' => '', 'type' => 'text', 'options' => array(), 'required' => false ), false );
		echo '</template>';

		submit_button( $is_edit ? __( 'Update Type', 'agile-custom-tables' ) : __( 'Create Type', 'agile-custom-tables' ) );
		echo '</form></div>';
	}

	protected static function render_field_row( $i, $field, $locked ) {
		$n = 'fields[' . $i . ']';
		echo '<tr class="cct-field-row">';
		echo '<td><input type="text" name="' . esc_attr( $n ) . '[label]" value="' . esc_attr( $field['label'] ) . '" class="cct-label"></td>';
		echo '<td><input type="text" name="' . esc_attr( $n ) . '[key]" value="' . esc_attr( $field['key'] ) . '" class="cct-key"' . ( $locked ? ' readonly' : '' ) . '></td>';
		echo '<td><select name="' . esc_attr( $n ) . '[type]" class="cct-type">';
		foreach ( CCT_Registry::field_types() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $field['type'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><textarea name="' . esc_attr( $n ) . '[options]" rows="2" class="cct-options">' . esc_textarea( implode( "\n", (array) $field['options'] ) ) . '</textarea></td>';
		echo '<td><input type="checkbox" name="' . esc_attr( $n ) . '[required]" value="1"' . checked( ! empty( $field['required'] ), true, false ) . '></td>';
		echo '<td><button type="button" class="button-link button-link-delete cct-remove-field">' . esc_html__( 'Remove', 'agile-custom-tables' ) . '</button></td>';
		echo '</tr>';
	}

	public static function handle_save_type() {
		self::guard();
		check_admin_referer( 'cct_save_type' );

		$raw     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in CCT_Registry::sanitize_type().
		$is_edit = ! empty( $raw['is_edit'] );

		if ( $is_edit ) {
			$slug = isset( $raw['slug'] ) ? sanitize_key( $raw['slug'] ) : '';
			if ( ! CCT_Registry::get( $slug ) ) {
				self::redirect( self::types_url() );
			}
		}

		$type = CCT_Registry::sanitize_type( $raw, $is_edit );

		if ( is_wp_error( $type ) ) {
			self::notice( 'error', $type->get_error_message() );
			// Keep what the user typed so they do not lose their work.
			set_transient(
				'cct_form_' . get_current_user_id(),
				array(
					'slug'     => isset( $raw['slug'] ) ? sanitize_text_field( $raw['slug'] ) : '',
					'singular' => isset( $raw['singular'] ) ? sanitize_text_field( $raw['singular'] ) : '',
					'plural'   => isset( $raw['plural'] ) ? sanitize_text_field( $raw['plural'] ) : '',
					'fields'   => self::echo_back_fields( $raw ),
				),
				60
			);
			self::redirect( $is_edit ? self::types_url( array( 'type' => sanitize_key( $raw['slug'] ) ) ) : admin_url( 'admin.php?page=cct-type-new' ) );
		}

		CCT_Registry::save_type( $type );
		self::notice( 'success', __( 'Content type saved.', 'agile-custom-tables' ) );
		self::redirect( self::types_url() );
	}

	/** Light sanitising of submitted field rows, only for re-displaying the form. */
	protected static function echo_back_fields( $raw ) {
		$out   = array();
		$types = array_keys( CCT_Registry::field_types() );
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

	public static function handle_delete_type() {
		self::guard();
		$slug = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		check_admin_referer( 'cct_delete_type_' . $slug );

		CCT_Registry::delete_type( $slug, false );
		self::notice( 'success', __( 'Content type removed. Its database table was left untouched.', 'agile-custom-tables' ) );
		self::redirect( self::types_url() );
	}

	/* ------------------------------------------------------------------ */
	/* Data screens                                                        */
	/* ------------------------------------------------------------------ */

	public static function page_data( $slug ) {
		self::guard();
		$type = CCT_Registry::get( $slug );
		if ( ! $type ) {
			wp_die( esc_html__( 'Unknown content type.', 'agile-custom-tables' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'add' === $action ) {
			self::render_item_form( $type, null );
		} elseif ( 'edit' === $action ) {
			$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$row = CCT_Data::get( $type, $id );
			if ( ! $row ) {
				wp_die( esc_html__( 'Item not found.', 'agile-custom-tables' ) );
			}
			self::render_item_form( $type, $row );
		} else {
			self::render_item_list( $type );
		}
	}

	protected static function render_item_list( array $type ) {
		$slug     = $type['slug'];
		$per_page = 20;
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total    = CCT_Data::count( $type );
		$rows     = CCT_Data::query( $type, $page, $per_page );
		$columns  = array_slice( $type['fields'], 0, 5 );

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html( $type['plural'] ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( self::data_url( $slug, array( 'action' => 'add' ) ) ) . '">' . esc_html__( 'Add New', 'agile-custom-tables' ) . '</a><hr class="wp-header-end">';
		self::render_notice();

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Nothing here yet.', 'agile-custom-tables' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat striped"><thead><tr><th style="width:60px">ID</th>';
		foreach ( $columns as $col ) {
			echo '<th>' . esc_html( $col['label'] ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Created', 'agile-custom-tables' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id         = (int) $row['id'];
			$edit_url   = self::data_url( $slug, array( 'action' => 'edit', 'id' => $id ) );
			$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=cct_delete_item&type=' . $slug . '&id=' . $id ), 'cct_delete_item_' . $slug . '_' . $id );

			echo '<tr><td>' . esc_html( $id ) . '</td>';
			foreach ( $columns as $i => $col ) {
				echo '<td>';
				$display = self::display_value( $col, isset( $row[ $col['key'] ] ) ? $row[ $col['key'] ] : '' );
				if ( 0 === $i ) {
					echo '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( '' !== $display ? $display : __( '(empty)', 'agile-custom-tables' ) ) . '</a></strong>';
					echo '<div class="row-actions visible"><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'agile-custom-tables' ) . '</a> | <a class="cct-confirm" data-confirm="' . esc_attr__( 'Delete this item?', 'agile-custom-tables' ) . '" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'agile-custom-tables' ) . '</a></div>';
				} else {
					echo esc_html( $display );
				}
				echo '</td>';
			}
			echo '<td>' . esc_html( ! empty( $row['created_at'] ) ? get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::data_url( $slug ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '</div>';
	}

	protected static function display_value( array $field, $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( 'checkbox' === $field['type'] ) {
			return $value ? __( 'Yes', 'agile-custom-tables' ) : __( 'No', 'agile-custom-tables' );
		}
		if ( 'textarea' === $field['type'] ) {
			return wp_trim_words( (string) $value, 12 );
		}
		return (string) $value;
	}

	protected static function render_item_form( array $type, $row ) {
		$slug    = $type['slug'];
		$is_edit = is_array( $row );
		$old     = get_transient( 'cct_item_' . get_current_user_id() );
		delete_transient( 'cct_item_' . get_current_user_id() );
		if ( is_array( $old ) ) {
			$row = array_merge( is_array( $row ) ? $row : array(), $old );
		}

		/* translators: %s: content type singular name */
		$title = $is_edit ? sprintf( __( 'Edit %s', 'agile-custom-tables' ), $type['singular'] ) : sprintf( __( 'Add %s', 'agile-custom-tables' ), $type['singular'] );

		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		self::render_notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cct_save_item">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $slug ) . '">';
		echo '<input type="hidden" name="id" value="' . esc_attr( $is_edit && isset( $row['id'] ) ? (int) $row['id'] : 0 ) . '">';
		wp_nonce_field( 'cct_save_item_' . $slug );

		echo '<table class="form-table" role="presentation">';
		foreach ( $type['fields'] as $field ) {
			$key   = $field['key'];
			$name  = 'cct[' . $key . ']';
			$id    = 'cct-f-' . $key;
			$value = is_array( $row ) && isset( $row[ $key ] ) ? $row[ $key ] : '';

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
		echo ' <a href="' . esc_url( self::data_url( $slug ) ) . '">' . esc_html__( 'Cancel', 'agile-custom-tables' ) . '</a>';
		echo '</form></div>';
	}

	public static function handle_save_item() {
		self::guard();
		$slug = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		check_admin_referer( 'cct_save_item_' . $slug );

		$type = CCT_Registry::get( $slug );
		if ( ! $type ) {
			self::redirect( self::types_url() );
		}

		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$raw = isset( $_POST['cct'] ) && is_array( $_POST['cct'] ) ? wp_unslash( $_POST['cct'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field type in CCT_Data.

		$clean = CCT_Data::sanitize_values( $type, $raw );
		if ( is_wp_error( $clean ) ) {
			self::notice( 'error', $clean->get_error_message() );
			$keep = array();
			foreach ( $raw as $k => $v ) {
				$keep[ sanitize_key( $k ) ] = is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : '';
			}
			set_transient( 'cct_item_' . get_current_user_id(), $keep, 60 );
			self::redirect( $id ? self::data_url( $slug, array( 'action' => 'edit', 'id' => $id ) ) : self::data_url( $slug, array( 'action' => 'add' ) ) );
		}

		if ( $id ) {
			if ( ! CCT_Data::get( $type, $id ) ) {
				self::notice( 'error', __( 'Item not found.', 'agile-custom-tables' ) );
				self::redirect( self::data_url( $slug ) );
			}
			$ok = CCT_Data::update( $type, $id, $clean );
		} else {
			$ok = (bool) CCT_Data::insert( $type, $clean );
		}

		self::notice( $ok ? 'success' : 'error', $ok ? __( 'Saved.', 'agile-custom-tables' ) : __( 'Could not save. Check the database error log.', 'agile-custom-tables' ) );
		self::redirect( self::data_url( $slug ) );
	}

	public static function handle_delete_item() {
		self::guard();
		$slug = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'cct_delete_item_' . $slug . '_' . $id );

		$type = CCT_Registry::get( $slug );
		if ( $type && $id ) {
			CCT_Data::delete( $type, $id );
			self::notice( 'success', __( 'Deleted.', 'agile-custom-tables' ) );
		}
		self::redirect( $type ? self::data_url( $slug ) : self::types_url() );
	}
}
