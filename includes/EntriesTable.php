<?php
/**
 * Entry list table.
 *
 * @package AgileCustomTables
 */

namespace AgileCustomTables;

use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * A type's entries as a core-style list: search, sortable columns, bulk
 * delete and paging. Build it on the screen's load-{$hook} action so
 * prepare_items() can still redirect an out-of-range page.
 */
final class EntriesTable extends WP_List_Table {

	/**
	 * Entries per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * How many fields get a column.
	 */
	private const FIELD_COLUMNS = 5;

	/**
	 * Nonce action for bulk actions, as WP_List_Table prints it.
	 */
	public const BULK_NONCE = 'bulk-agile-ct-entries';

	/**
	 * Type definition.
	 *
	 * @var array
	 */
	private array $type;

	/**
	 * URL of the type's entry screen.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param array  $type     Type definition.
	 * @param string $base_url URL of the type's entry screen.
	 */
	public function __construct( array $type, string $base_url ) {
		$this->type     = $type;
		$this->base_url = $base_url;
		parent::__construct(
			array(
				'singular' => 'agile-ct-entry',
				'plural'   => 'agile-ct-entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The search term from the request.
	 *
	 * @return string
	 */
	public static function search_term(): string {
		return isset( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
	}

	/**
	 * Field columns, keyed by column name. Prefixed so a field key such as
	 * "author" cannot clash with the plugin's own columns.
	 *
	 * @return array[]
	 */
	private function field_columns(): array {
		$columns = array();
		foreach ( array_slice( $this->type['fields'], 0, self::FIELD_COLUMNS ) as $field ) {
			$columns[ 'field_' . $field['key'] ] = $field;
		}
		return $columns;
	}

	/**
	 * Column headings.
	 *
	 * @return string[]
	 */
	public function get_columns() {
		$columns = array(
			'cb' => '<input type="checkbox" />',
			'id' => esc_html__( 'ID', 'agile-custom-tables' ),
		);
		foreach ( $this->field_columns() as $name => $field ) {
			$columns[ $name ] = esc_html( $field['label'] );
		}
		$columns['author']     = esc_html__( 'Author', 'agile-custom-tables' );
		$columns['created_at'] = esc_html__( 'Created', 'agile-custom-tables' );
		return $columns;
	}

	/**
	 * Sortable columns: ID and created date start descending, fields ascending.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		$sortable = array(
			'id'         => array( 'id', true, '', '', 'desc' ),
			'created_at' => array( 'created_at', true ),
		);
		foreach ( $this->field_columns() as $name => $field ) {
			$sortable[ $name ] = array( $field['key'], false );
		}
		return $sortable;
	}

	/**
	 * The column that holds the title link and row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		$names = array_keys( $this->field_columns() );
		return $names ? $names[0] : 'id';
	}

	/**
	 * Bulk actions.
	 *
	 * @return string[]
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'agile-custom-tables' ) );
	}

	/**
	 * Load the current page of entries.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$search = self::search_term();
		$this->set_pagination_args(
			array(
				'total_items' => Entries::count( $this->type, $search ),
				'per_page'    => self::PER_PAGE,
			)
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only sorting.
		$this->items = Entries::query(
			$this->type,
			array(
				'page'     => $this->get_pagenum(),
				'per_page' => self::PER_PAGE,
				'search'   => $search,
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
			)
		);
		// phpcs:enable

		// Load every author in one query instead of one per row.
		$author_ids = array_filter( array_map( 'absint', wp_list_pluck( $this->items, 'created_by' ) ) );
		if ( $author_ids ) {
			cache_users( $author_ids );
		}
	}

	/**
	 * Message for an empty list.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( '' !== self::search_term() ) {
			esc_html_e( 'No entries match your search.', 'agile-custom-tables' );
		} else {
			esc_html_e( 'Nothing here yet.', 'agile-custom-tables' );
		}
	}

	/**
	 * Row checkbox.
	 *
	 * @param array $item Entry row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		$id = (int) $item['id'];
		return sprintf(
			'<input id="cb-select-%1$d" type="checkbox" name="entry[]" value="%1$d" /><label for="cb-select-%1$d"><span class="screen-reader-text">%2$s</span></label>',
			$id,
			/* translators: %d: entry ID */
			esc_html( sprintf( __( 'Select entry %d', 'agile-custom-tables' ), $id ) )
		);
	}

	/**
	 * Every other column.
	 *
	 * @param array  $item        Entry row.
	 * @param string $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return esc_html( (string) (int) $item['id'] );
			case 'author':
				return esc_html( Entries::author_name( $item ) );
			case 'created_at':
				return esc_html( ! empty( $item['created_at'] ) ? get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) ) : '' );
		}

		$fields = $this->field_columns();
		if ( ! isset( $fields[ $column_name ] ) ) {
			return '';
		}
		$field   = $fields[ $column_name ];
		$display = $this->display_value( $field, isset( $item[ $field['key'] ] ) ? $item[ $field['key'] ] : null );

		if ( $column_name === $this->get_primary_column_name() ) {
			return '<strong><a class="row-title" href="' . esc_url( $this->edit_url( $item ) ) . '">' . esc_html( '' !== $display ? $display : __( '(empty)', 'agile-custom-tables' ) ) . '</a></strong>';
		}
		return esc_html( $display );
	}

	/**
	 * Edit and Delete links under the title.
	 *
	 * @param array  $item        Entry row.
	 * @param string $column_name Column being printed.
	 * @param string $primary     Primary column name.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}
		$id         = (int) $item['id'];
		$slug       = $this->type['slug'];
		$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=agile_ct_delete_entry&type=' . $slug . '&id=' . $id ), 'agile_ct_delete_entry_' . $slug . '_' . $id );

		return $this->row_actions(
			array(
				'edit'   => '<a href="' . esc_url( $this->edit_url( $item ) ) . '">' . esc_html__( 'Edit', 'agile-custom-tables' ) . '</a>',
				'delete' => '<a class="agile-ct-confirm submitdelete" data-confirm="' . esc_attr__( 'Delete this entry?', 'agile-custom-tables' ) . '" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'agile-custom-tables' ) . '</a>',
			)
		);
	}

	/**
	 * URL of an entry's edit form.
	 *
	 * @param array $item Entry row.
	 * @return string
	 */
	private function edit_url( array $item ): string {
		return add_query_arg(
			array(
				'action' => 'edit',
				'id'     => (int) $item['id'],
			),
			$this->base_url
		);
	}

	/**
	 * Short text for a value in the list.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private function display_value( array $field, $value ): string {
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
}
