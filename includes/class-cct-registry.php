<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stores content type definitions (in an option) and keeps the
 * matching database tables in sync.
 */
class CCT_Registry {

	const OPTION = 'cct_types';

	/**
	 * Column names the plugin manages itself, plus words dbDelta() reads as
	 * the start of an index definition (a column with one of those names
	 * makes it generate broken ALTER statements).
	 */
	const RESERVED = array( 'id', 'created_at', 'updated_at', 'primary', 'index', 'key', 'unique', 'fulltext', 'spatial' );

	public static function field_types() {
		return array(
			'text'     => __( 'Text', 'agile-custom-tables' ),
			'textarea' => __( 'Textarea', 'agile-custom-tables' ),
			'number'   => __( 'Number', 'agile-custom-tables' ),
			'email'    => __( 'Email', 'agile-custom-tables' ),
			'url'      => __( 'URL', 'agile-custom-tables' ),
			'date'     => __( 'Date', 'agile-custom-tables' ),
			'checkbox' => __( 'Checkbox', 'agile-custom-tables' ),
			'select'   => __( 'Select', 'agile-custom-tables' ),
		);
	}

	public static function all() {
		$types = get_option( self::OPTION, array() );
		return is_array( $types ) ? $types : array();
	}

	public static function get( $slug ) {
		$types = self::all();
		return isset( $types[ $slug ] ) ? $types[ $slug ] : null;
	}

	public static function table( $slug ) {
		global $wpdb;
		return $wpdb->prefix . 'cct_' . $slug;
	}

	/**
	 * Validate and normalise a submitted type definition.
	 *
	 * @param array $raw      Raw (unslashed) input.
	 * @param bool  $existing Whether the type already exists (slug is locked).
	 * @return array|WP_Error
	 */
	public static function sanitize_type( $raw, $existing = false ) {
		$slug     = isset( $raw['slug'] ) ? strtolower( trim( (string) $raw['slug'] ) ) : '';
		$singular = isset( $raw['singular'] ) ? sanitize_text_field( $raw['singular'] ) : '';
		$plural   = isset( $raw['plural'] ) ? sanitize_text_field( $raw['plural'] ) : '';

		if ( '' === $singular || '' === $plural ) {
			return new WP_Error( 'cct_names', __( 'Singular and plural names are required.', 'agile-custom-tables' ) );
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $slug ) ) {
			return new WP_Error( 'cct_slug', __( 'Slug must start with a letter and use only lowercase letters, numbers and underscores (max 32 characters).', 'agile-custom-tables' ) );
		}
		if ( ! $existing && self::get( $slug ) ) {
			return new WP_Error( 'cct_slug_taken', __( 'A content type with that slug already exists.', 'agile-custom-tables' ) );
		}

		$types   = array_keys( self::field_types() );
		$fields  = array();
		$seen    = array();
		$rows    = isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? $raw['fields'] : array();
		$columns = self::existing_columns( $slug );

		foreach ( $rows as $row ) {
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$key   = isset( $row['key'] ) ? strtolower( trim( (string) $row['key'] ) ) : '';
			if ( '' === $label && '' === $key ) {
				continue; // Empty row.
			}
			if ( '' === $key ) {
				$key = sanitize_title_with_dashes( $label );
			}
			$key = trim( preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $key ) ), '_' );
			if ( '' === $label ) {
				$label = $key;
			}
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) ) {
				/* translators: %s: field label */
				return new WP_Error( 'cct_key', sprintf( __( 'The field "%s" needs a key that starts with a letter (letters, numbers, underscores only).', 'agile-custom-tables' ), $label ) );
			}
			if ( in_array( $key, self::RESERVED, true ) ) {
				/* translators: %s: field key */
				return new WP_Error( 'cct_reserved', sprintf( __( 'The field key "%s" is reserved. Pick another one.', 'agile-custom-tables' ), $key ) );
			}
			if ( isset( $seen[ $key ] ) ) {
				/* translators: %s: field key */
				return new WP_Error( 'cct_dupe', sprintf( __( 'Duplicate field key "%s".', 'agile-custom-tables' ), $key ) );
			}
			$seen[ $key ] = true;

			$type = isset( $row['type'] ) && in_array( $row['type'], $types, true ) ? $row['type'] : 'text';

			// dbDelta() would ALTER the column in place and silently convert
			// existing values (e.g. text to 0 when switching to Number). This
			// also covers a removed field re-added with the same key.
			if ( isset( $columns[ $key ] ) && ! self::column_change_is_safe( $columns[ $key ], self::column_base_type( $type ) ) ) {
				/* translators: %s: field label */
				return new WP_Error( 'cct_type_change', sprintf( __( 'The field "%s" already has a column with a different data type, so changing its type could wipe existing values. Keep its current type, or add a new field with a different key.', 'agile-custom-tables' ), $label ) );
			}

			$options = array();
			if ( 'select' === $type && ! empty( $row['options'] ) ) {
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $row['options'] ) as $opt ) {
					$opt = sanitize_text_field( $opt );
					if ( '' !== $opt ) {
						$options[] = $opt;
					}
				}
				$options = array_values( array_unique( $options ) );
			}
			if ( 'select' === $type && empty( $options ) ) {
				/* translators: %s: field label */
				return new WP_Error( 'cct_options', sprintf( __( 'The select field "%s" needs at least one option.', 'agile-custom-tables' ), $label ) );
			}

			$fields[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'options'  => $options,
				'required' => ! empty( $row['required'] ),
			);
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'cct_nofields', __( 'Add at least one field.', 'agile-custom-tables' ) );
		}

		return array(
			'slug'     => $slug,
			'singular' => $singular,
			'plural'   => $plural,
			'fields'   => $fields,
		);
	}

	public static function save_type( array $type ) {
		$types                  = self::all();
		$types[ $type['slug'] ] = $type;
		update_option( self::OPTION, $types, false );
		self::sync_table( $type );
	}

	public static function delete_type( $slug, $drop_table = false ) {
		global $wpdb;
		$types = self::all();
		if ( ! isset( $types[ $slug ] ) ) {
			return;
		}
		unset( $types[ $slug ] );
		update_option( self::OPTION, $types, false );
		if ( $drop_table ) {
			$table = self::table( $slug );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from a validated slug.
		}
	}

	public static function sync_all_tables() {
		foreach ( self::all() as $type ) {
			self::sync_table( $type );
		}
	}

	/**
	 * Create or alter the table. dbDelta only adds columns, it never drops
	 * them, so removing a field from a type is non-destructive.
	 */
	public static function sync_table( array $type ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table( $type['slug'] );
		$charset = $wpdb->get_charset_collate();

		$cols   = array();
		$cols[] = '`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT';
		foreach ( $type['fields'] as $field ) {
			$cols[] = '`' . $field['key'] . '` ' . self::column_sql( $field['type'] );
		}
		$cols[] = '`created_at` DATETIME NULL';
		$cols[] = '`updated_at` DATETIME NULL';
		$cols[] = 'PRIMARY KEY  (`id`)';

		$sql = "CREATE TABLE {$table} (\n" . implode( ",\n", $cols ) . "\n) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Current columns of a type's table, as name => lowercase SQL type.
	 * Empty if the table does not exist yet.
	 */
	protected static function existing_columns( $slug ) {
		global $wpdb;
		$table = self::table( $slug );
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array();
		}
		$columns = array();
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ) as $col ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from a validated slug.
			$columns[ $col->Field ] = strtolower( $col->Type ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		return $columns;
	}

	/** SQL type of a field type's column, e.g. "varchar(255)". */
	protected static function column_base_type( $type ) {
		return strtolower( strtok( self::column_sql( $type ), ' ' ) );
	}

	/** Same type, or widening VARCHAR to LONGTEXT; anything else converts data. */
	protected static function column_change_is_safe( $from, $to ) {
		return $from === $to || ( 0 === strpos( $from, 'varchar' ) && 'longtext' === $to );
	}

	protected static function column_sql( $type ) {
		switch ( $type ) {
			case 'textarea':
				return 'LONGTEXT NULL';
			case 'number':
				return 'DOUBLE NULL';
			case 'date':
				return 'DATE NULL';
			case 'checkbox':
				return 'TINYINT(1) NOT NULL DEFAULT 0';
			case 'email':
			case 'url':
			case 'select':
			case 'text':
			default:
				return 'VARCHAR(255) NULL';
		}
	}
}
