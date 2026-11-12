<?php
/**
 * Entry storage.
 *
 * @package AgileCustomTables
 */

namespace AgileCustomTables;

use DateTime;
use WP_Error;

/**
 * CRUD for the entries (rows) in a content type's table.
 */
final class Entries {

	/**
	 * Sanitise submitted values against a type's fields.
	 *
	 * @param array $type Type definition.
	 * @param array $raw  Raw (unslashed) input, keyed by field key.
	 * @return array|WP_Error Array with 'values' and 'formats'.
	 */
	public static function sanitize( array $type, array $raw ) {
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
				return new WP_Error( 'agile_ct_email', sprintf( __( '"%s" is not a valid email address.', 'agile-custom-tables' ), $field['label'] ) );
			}

			if ( ! empty( $field['required'] ) && 'checkbox' !== $field['type'] && ( null === $val || '' === $val ) ) {
				/* translators: %s: field label */
				return new WP_Error( 'agile_ct_required', sprintf( __( '"%s" is required.', 'agile-custom-tables' ), $field['label'] ) );
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

	/**
	 * Insert an entry, recording the current user as its author.
	 *
	 * @param array $type  Type definition.
	 * @param array $clean Output of sanitize().
	 * @return int|false New entry ID, or false on failure.
	 */
	public static function insert( array $type, array $clean ) {
		global $wpdb;
		$now    = current_time( 'mysql', true );
		$author = get_current_user_id();

		$clean['values']['created_by']  = $author ? $author : null;
		$clean['values']['created_at']  = $now;
		$clean['values']['updated_at']  = $now;
		$clean['formats']['created_by'] = '%d';
		$clean['formats']['created_at'] = '%s';
		$clean['formats']['updated_at'] = '%s';

		$ok = $wpdb->insert( Registry::table( $type['slug'] ), $clean['values'], array_values( $clean['formats'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an entry. The author is left as it was.
	 *
	 * @param array $type  Type definition.
	 * @param int   $id    Entry ID.
	 * @param array $clean Output of sanitize().
	 * @return bool
	 */
	public static function update( array $type, int $id, array $clean ): bool {
		global $wpdb;
		$clean['values']['updated_at']  = current_time( 'mysql', true );
		$clean['formats']['updated_at'] = '%s';

		$ok = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Registry::table( $type['slug'] ),
			$clean['values'],
			array( 'id' => $id ),
			array_values( $clean['formats'] ),
			array( '%d' )
		);
		return false !== $ok;
	}

	/**
	 * Delete an entry.
	 *
	 * @param array $type Type definition.
	 * @param int   $id   Entry ID.
	 * @return bool
	 */
	public static function delete( array $type, int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( Registry::table( $type['slug'] ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Fetch one entry.
	 *
	 * @param array $type Type definition.
	 * @param int   $id   Entry ID.
	 * @return array|null
	 */
	public static function get( array $type, int $id ): ?array {
		global $wpdb;
		$table = Registry::table( $type['slug'] );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Number of entries.
	 *
	 * @param array $type Type definition.
	 * @return int
	 */
	public static function count( array $type ): int {
		global $wpdb;
		$table = Registry::table( $type['slug'] );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One page of entries, newest first.
	 *
	 * @param array $type     Type definition.
	 * @param int   $page     1-based page number.
	 * @param int   $per_page Entries per page.
	 * @return array[]
	 */
	public static function query( array $type, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$table  = Registry::table( $type['slug'] );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * When a user is deleted, hand their entries to the user WordPress
	 * reassigns posts to, or clear the author. Entries are never deleted.
	 *
	 * @param int      $user_id  Deleted user ID.
	 * @param int|null $reassign User ID that takes over their content, if any.
	 * @return void
	 */
	public static function reassign_author( $user_id, $reassign = null ): void {
		global $wpdb;
		foreach ( Registry::all() as $type ) {
			if ( ! Registry::table_exists( $type['slug'] ) ) {
				continue; // E.g. dropped by hand; nothing to update.
			}
			$table = Registry::table( $type['slug'] );
			if ( $reassign ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET created_by = %d WHERE created_by = %d", (int) $reassign, (int) $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			} else {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET created_by = NULL WHERE created_by = %d", (int) $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}
		}
	}
}
