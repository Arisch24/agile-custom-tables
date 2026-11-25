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
	 * Field types a search looks in.
	 */
	private const SEARCHABLE = array( 'text', 'textarea', 'email', 'url', 'select' );

	/**
	 * Whether a type has fields a search can look in.
	 *
	 * @param array $type Type definition.
	 * @return bool
	 */
	public static function is_searchable( array $type ): bool {
		return (bool) array_filter(
			$type['fields'],
			static function ( array $field ): bool {
				return in_array( $field['type'], self::SEARCHABLE, true );
			}
		);
	}

	/**
	 * Columns a list can be sorted by.
	 *
	 * @param array $type Type definition.
	 * @return string[]
	 */
	public static function sortable_columns( array $type ): array {
		return array_merge( array( 'id' ), wp_list_pluck( $type['fields'], 'key' ), array( 'created_at', 'updated_at' ) );
	}

	/**
	 * WHERE clause, with placeholders, matching a search term: a text-like
	 * field containing it, or an ID equal to it.
	 *
	 * @param array  $type   Type definition.
	 * @param string $search Search term; empty matches everything.
	 * @return array SQL (empty or starting with " WHERE ") and its values.
	 */
	private static function search_clause( array $type, string $search ): array {
		global $wpdb;
		$search = trim( $search );
		if ( '' === $search ) {
			return array( '', array() );
		}

		$parts  = array();
		$values = array();
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		foreach ( $type['fields'] as $field ) {
			if ( in_array( $field['type'], self::SEARCHABLE, true ) ) {
				$parts[]  = "`{$field['key']}` LIKE %s"; // Keys are validated on save: [a-z][a-z0-9_]*.
				$values[] = $like;
			}
		}
		if ( ctype_digit( $search ) ) {
			$parts[]  = 'id = %d';
			$values[] = (int) $search;
		}

		return array( ' WHERE ' . ( $parts ? '(' . implode( ' OR ', $parts ) . ')' : '1 = 0' ), $values );
	}

	/**
	 * Number of entries, optionally only those matching a search.
	 *
	 * @param array  $type   Type definition.
	 * @param string $search Search term.
	 * @return int
	 */
	public static function count( array $type, string $search = '' ): int {
		global $wpdb;
		$table = Registry::table( $type['slug'] );
		list( $where, $values ) = self::search_clause( $type, $search );
		$sql                    = "SELECT COUNT(*) FROM `{$table}`{$where}";
		return (int) $wpdb->get_var( $values ? $wpdb->prepare( $sql, $values ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table and column names are validated, values are placeholders.
	}

	/**
	 * One page of entries.
	 *
	 * @param array $type Type definition.
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $page     1-based page number. Default 1.
	 *     @type int    $per_page Entries per page. Default 20.
	 *     @type string $search   Search term. Default empty.
	 *     @type string $orderby  A column from sortable_columns(). Default 'id'.
	 *     @type string $order    'asc' or 'desc'. Default 'desc'.
	 * }
	 * @return array[]
	 */
	public static function query( array $type, array $args = array() ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 20,
				'search'   => '',
				'orderby'  => 'id',
				'order'    => 'desc',
			)
		);

		$orderby  = in_array( $args['orderby'], self::sortable_columns( $type ), true ) ? $args['orderby'] : 'id';
		$order    = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$table    = Registry::table( $type['slug'] );

		list( $where, $values ) = self::search_clause( $type, (string) $args['search'] );
		$values[]               = $per_page;
		$values[]               = $offset;
		// Tie-break on id so rows with equal values keep a stable order across pages.
		$order_sql = "`{$orderby}` {$order}" . ( 'id' === $orderby ? '' : ", id {$order}" );

		// Table, columns and order are validated above; $values holds the search values, LIMIT and OFFSET.
		$sql = "SELECT * FROM `{$table}`{$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete several entries.
	 *
	 * @param array $type Type definition.
	 * @param int[] $ids  Entry IDs.
	 * @return int Number of entries deleted.
	 */
	public static function delete_many( array $type, array $ids ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table        = Registry::table( $type['slug'] );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Display name of an entry's author, or a dash when unknown.
	 *
	 * @param array $entry Entry row.
	 * @return string
	 */
	public static function author_name( array $entry ): string {
		$user = ! empty( $entry['created_by'] ) ? get_userdata( (int) $entry['created_by'] ) : false;
		return $user ? $user->display_name : '—';
	}

	/**
	 * Whether any of these users created an entry of any type.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return bool
	 */
	public static function authored_by_any( array $user_ids ): bool {
		global $wpdb;
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return false;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
		foreach ( Registry::all() as $type ) {
			if ( ! Registry::table_exists( $type['slug'] ) ) {
				continue;
			}
			$table = Registry::table( $type['slug'] );
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$table}` WHERE created_by IN ({$placeholders}) LIMIT 1", $user_ids ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
				return true;
			}
		}
		return false;
	}

	/**
	 * Make WordPress's Delete Users screen ask what to do with a user's
	 * content when they have entries, even if they have no posts.
	 *
	 * @param bool  $has_content Whether WordPress already found other content.
	 * @param int[] $user_ids    Users being deleted.
	 * @return bool
	 */
	public static function users_have_entries( $has_content, $user_ids ): bool {
		return $has_content || self::authored_by_any( (array) $user_ids );
	}

	/**
	 * Multisite: when a user is removed from a site and their content is
	 * handed to someone, hand over their entries on that site too. WordPress
	 * has already switched to that site.
	 *
	 * @param int $user_id  Removed user ID.
	 * @param int $blog_id  Site ID.
	 * @param int $reassign User ID that takes over their content, or 0.
	 * @return void
	 */
	public static function reassign_on_site_removal( $user_id, $blog_id, $reassign = 0 ): void {
		if ( $reassign ) {
			self::reassign_author( (int) $user_id, (int) $reassign );
		}
	}

	/**
	 * When a user is deleted, hand their entries to whoever was chosen on the
	 * Delete Users screen, or clear the author. Entries are never deleted.
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
