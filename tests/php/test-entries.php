<?php
/**
 * Entry sanitising, storage and authors.
 */

use AgileCustomTables\Entries;

$type   = agile_ct_test_type(
	'zz_test_entries',
	array(
		array(
			'label'    => 'Title',
			'key'      => 'title',
			'required' => '1',
		),
		array(
			'label' => 'Price',
			'key'   => 'price',
			'type'  => 'number',
		),
		array(
			'label' => 'Email',
			'key'   => 'email',
			'type'  => 'email',
		),
		array(
			'label' => 'Starts',
			'key'   => 'starts',
			'type'  => 'date',
		),
		array(
			'label' => 'Featured',
			'key'   => 'featured',
			'type'  => 'checkbox',
		),
		array(
			'label'   => 'Tier',
			'key'     => 'tier',
			'type'    => 'select',
			'options' => "Gold\nSilver",
		),
	)
);
$clean  = static function ( array $raw ) use ( $type ) {
	return Entries::sanitize( $type, $raw );
};
$admin  = agile_ct_test_user( 'administrator' );
$editor = agile_ct_test_user( 'editor' );

// Sanitising.
$values = $clean(
	array(
		'title'    => 'x',
		'price'    => ' 12 ',
		'starts'   => '2026-02-30',
		'featured' => '1',
		'tier'     => 'Bronze',
	)
)['values'];
agile_ct_check( 'number trimmed', '12' === $values['price'] );
agile_ct_check( 'impossible date dropped', null === $values['starts'] );
agile_ct_check( 'checkbox stored as 1', 1 === $values['featured'] );
agile_ct_check( 'unknown select option dropped', '' === $values['tier'] );
agile_ct_check(
	'non-numeric number dropped',
	null === $clean(
		array(
			'title' => 'x',
			'price' => 'abc',
		)
	)['values']['price']
);
agile_ct_check( 'missing required field rejected', is_wp_error( $clean( array( 'title' => '' ) ) ) );
agile_ct_check(
	'invalid email rejected',
	is_wp_error(
		$clean(
			array(
				'title' => 'x',
				'email' => 'nope',
			)
		)
	)
);

// Insert and update keep the author.
wp_set_current_user( $editor );
$id    = Entries::insert(
	$type,
	$clean(
		array(
			'title'    => 'First',
			'price'    => '0.1234567',
			'featured' => '1',
		)
	)
);
$entry = Entries::get( $type, (int) $id );
agile_ct_check( 'insert returns an id', is_int( $id ) && $id > 0 );
agile_ct_check( 'number stored without rounding', '0.1234567' === (string) $entry['price'], (string) $entry['price'] );
agile_ct_check( 'author is the current user', (int) $entry['created_by'] === $editor );
agile_ct_check( 'timestamps set', ! empty( $entry['created_at'] ) && $entry['created_at'] === $entry['updated_at'] );

wp_set_current_user( $admin );
agile_ct_check( 'update succeeds', Entries::update( $type, (int) $id, $clean( array( 'title' => 'Renamed' ) ) ) );
$entry = Entries::get( $type, (int) $id );
agile_ct_check( 'update writes values', 'Renamed' === $entry['title'] );
agile_ct_check( 'update keeps the original author', (int) $entry['created_by'] === $editor );

// Deleting a user hands their entries over, or clears the author; entries stay.
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $editor, $admin );
agile_ct_check( "deleted user's entries reassigned", (int) Entries::get( $type, (int) $id )['created_by'] === $admin );

$author = agile_ct_test_user( 'author' );
wp_set_current_user( $author );
$id2 = Entries::insert( $type, $clean( array( 'title' => 'Second' ) ) );
wp_set_current_user( $admin );
wp_delete_user( $author );
$entry2 = Entries::get( $type, (int) $id2 );
agile_ct_check( 'entries survive their author being deleted', null !== $entry2 );
agile_ct_check( 'author cleared when nobody takes over', null !== $entry2 && null === $entry2['created_by'] );

// A type whose table went missing must not break deleting users.
global $wpdb;
agile_ct_test_type( 'zz_test_ghost', array( array( 'label' => 'Name' ) ) );
$wpdb->query( 'DROP TABLE `' . AgileCustomTables\Registry::table( 'zz_test_ghost' ) . '`' );
$wpdb->last_error = '';
wp_delete_user( agile_ct_test_user( 'author' ) );
agile_ct_check( 'user deletion skips missing tables', '' === $wpdb->last_error, $wpdb->last_error );

// Listing and deleting.
agile_ct_check( 'count', 2 === Entries::count( $type ) );
agile_ct_check( 'query lists newest first', (int) Entries::query( $type )[0]['id'] === (int) $id2 );
agile_ct_check( 'delete', Entries::delete( $type, (int) $id ) && null === Entries::get( $type, (int) $id ) );

wp_set_current_user( 0 );
