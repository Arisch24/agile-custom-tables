<?php
defined( 'ABSPATH' ) || exit;

/**
 * CRUD for rows in a content type's table.
 */
class CCT_Data {

	/**
	 * Sanitise submitted values against a type's fields.
	 *
	 * @param array $type Type definition.
	 * @param array $raw  Raw (unslashed) input, keyed by field key.
	 * @return array|WP_Error Array with 'values' and 'formats'.
	 */
	public static function sanitize_values( array $type, array $raw ) {
		$values  = array();
		$formats = array();

		foreach ( $type['fields'] as $field ) {
			$key = $field['key'];
			$in  = isset( $raw[ $key ] ) ? $raw[ $key ] : '';
			$in  = is_scalar( $in ) ? (string) $in : '';

			switch ( $field['type'] ) {
				case 'textarea':
					$val = sanitize_textarea_field( $in );
					$fmt = '%s';
					break;
				case 'number':
					// Pass the validated string through: %f would round to 6 decimals.
					$num = trim( $in );
					$val = ( '' !== $num && is_numeric( $num ) ) ? $num : null;
					$fmt = '%s';
					break;
				case 'email':
					$val = sanitize_email( $in );
					$fmt = '%s';
					break;
				case 'url':
					$val = esc_url_raw( $in );
					$fmt = '%s';
					break;
				case 'date':
					$d   = DateTime::createFromFormat( 'Y-m-d', $in );
					$val = ( $d && $d->format( 'Y-m-d' ) === $in ) ? $in : null;
					$fmt = '%s';
					break;
				case 'checkbox':
					$val = isset( $raw[ $key ] ) && '' !== $raw[ $key ] ? 1 : 0;
					$fmt = '%d';
					break;
				case 'select':
					$val = in_array( $in, $field['options'], true ) ? $in : '';
					$fmt = '%s';
					break;
				case 'text':
				default:
					$val = sanitize_text_field( $in );
					$fmt = '%s';
					break;
			}

			if ( 'email' === $field['type'] && '' !== $in && '' === $val ) {
				/* translators: %s: field label */
				return new WP_Error( 'cct_email', sprintf( __( '"%s" is not a valid email address.', 'agile-custom-tables' ), $field['label'] ) );
			}

			if ( ! empty( $field['required'] ) && 'checkbox' !== $field['type'] && ( null === $val || '' === $val ) ) {
				/* translators: %s: field label */
				return new WP_Error( 'cct_required', sprintf( __( '"%s" is required.', 'agile-custom-tables' ), $field['label'] ) );
			}

			// Store empty strings as NULL for nullable columns.
			if ( '' === $val && 'select' !== $field['type'] ) {
				$val = null;
			}

			$values[ $key ]  = $val;
			$formats[ $key ] = $fmt;
		}

		return array(
			'values'  => $values,
			'formats' => $formats,
		);
	}

	public static function insert( array $type, array $clean ) {
		global $wpdb;
		$now                     = current_time( 'mysql', true );
		$clean['values']['created_at'] = $now;
		$clean['values']['updated_at'] = $now;
		$clean['formats']['created_at'] = '%s';
		$clean['formats']['updated_at'] = '%s';

		$ok = $wpdb->insert( CCT_Registry::table( $type['slug'] ), $clean['values'], array_values( $clean['formats'] ) );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public static function update( array $type, $id, array $clean ) {
		global $wpdb;
		$clean['values']['updated_at']  = current_time( 'mysql', true );
		$clean['formats']['updated_at'] = '%s';

		$ok = $wpdb->update(
			CCT_Registry::table( $type['slug'] ),
			$clean['values'],
			array( 'id' => (int) $id ),
			array_values( $clean['formats'] ),
			array( '%d' )
		);
		return false !== $ok;
	}

	public static function delete( array $type, $id ) {
		global $wpdb;
		return false !== $wpdb->delete( CCT_Registry::table( $type['slug'] ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function get( array $type, $id ) {
		global $wpdb;
		$table = CCT_Registry::table( $type['slug'] );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count( array $type ) {
		global $wpdb;
		$table = CCT_Registry::table( $type['slug'] );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function query( array $type, $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table  = CCT_Registry::table( $type['slug'] );
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", (int) $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}
}
