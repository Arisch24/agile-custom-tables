<?php
/**
 * Searching, sorting and bulk-deleting entries, and asking who takes over a
 * deleted user's entries.
 */

use AgileCustomTables\Admin;
use AgileCustomTables\Entries;

$type = agile_ct_test_type(
	'zz_test_list',
	array(
		array(
			'label' => 'Title',
			'key'   => 'title',
		),
		array(
			'label' => 'Notes',
			'key'   => 'notes',
			'type'  => 'textarea',
		),
		array(
			'label' => 'Price',
			'key'   => 'price',
			'type'  => 'number',
		),
	)
);
$add  = static function ( string $title, string $price, string $notes = '' ) use ( $type ): int {
	return (int) Entries::insert(
		$type,
		Entries::sanitize(
			$type,
			array(
				'title' => $title,
				'price' => $price,
				'notes' => $notes,
			)
		)
	);
};
$ids  = static function ( array $rows ): array {
	return array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
};

$admin   = agile_ct_test_user( 'administrator' );
$editor  = agile_ct_test_user( 'editor' );
$nobody  = agile_ct_test_user( 'editor' );
$display = get_userdata( $editor )->display_name;

wp_set_current_user( $editor );
$alpha   = $add( 'Alpha', '30', 'first launch notes' );
$bravo   = $add( 'Bravo', '5' );
$charlie = $add( 'Charlie 100%', '10' );
$delta   = $add( 'Delta_x', '50' );
wp_set_current_user( $admin );

// Search.
$search = static function ( string $term ) use ( $type, $ids ): array {
	return $ids( Entries::query( $type, array( 'search' => $term ) ) );
};
agile_ct_check( 'search matches part of a text field, any case', array( $bravo ) === $search( 'bRA' ) );
agile_ct_check( 'search looks in textareas', array( $alpha ) === $search( 'launch' ) );
agile_ct_check( 'search treats % literally', array( $charlie ) === $search( '%' ) );
agile_ct_check( 'search treats _ literally', array( $delta ) === $search( '_' ) );
agile_ct_check( 'search by ID', in_array( $alpha, $search( (string) $alpha ), true ) );
agile_ct_check( 'search with no match', array() === $search( 'zzz-nothing' ) );
agile_ct_check( 'count with a search', 1 === Entries::count( $type, 'bravo' ) && 4 === Entries::count( $type ) );
agile_ct_check(
	'only types with text-like fields get a search box',
	! Entries::is_searchable( array( 'fields' => array( array( 'type' => 'number' ) ) ) ) && Entries::is_searchable( $type )
);

// Sorting and paging.
$sorted = static function ( array $args ) use ( $type, $ids ): array {
	return $ids( Entries::query( $type, $args ) );
};
agile_ct_check( 'default order is newest first', array( $delta, $charlie, $bravo, $alpha ) === $sorted( array() ) );
agile_ct_check(
	'sort by a number field',
	array( $bravo, $charlie, $alpha, $delta ) === $sorted(
		array(
			'orderby' => 'price',
			'order'   => 'asc',
		)
	)
);
agile_ct_check(
	'sort by a text field, descending',
	array( $delta, $charlie, $bravo, $alpha ) === $sorted(
		array(
			'orderby' => 'title',
			'order'   => 'DESC',
		)
	)
);
agile_ct_check( 'unknown sort column falls back to ID', array( $delta, $charlie, $bravo, $alpha ) === $sorted( array( 'orderby' => 'price`; DROP TABLE x; --' ) ) );
agile_ct_check(
	'pages follow the sort',
	array( $alpha, $delta ) === $sorted(
		array(
			'orderby'  => 'price',
			'order'    => 'asc',
			'per_page' => 2,
			'page'     => 2,
		)
	)
);

// Bulk delete.
agile_ct_check( 'bulk delete with nothing selected', 0 === Entries::delete_many( $type, array() ) );
agile_ct_check( 'bulk delete counts only real entries', 2 === Entries::delete_many( $type, array( $bravo, $charlie, 0, 99999999 ) ) );
agile_ct_check( 'bulk delete leaves the rest', array( $delta, $alpha ) === $sorted( array() ) );

// Deleting a user: WordPress asks who takes over when they have entries.
$asks = static function ( int $user_id ): bool {
	return (bool) apply_filters( 'users_have_additional_content', false, array( $user_id ) );
};
agile_ct_check( 'delete screen asks about a user with entries', $asks( $editor ) );
agile_ct_check( 'delete screen does not ask about a user without content', ! $asks( $nobody ) );
agile_ct_check( 'other plugins\' answer is kept', (bool) apply_filters( 'users_have_additional_content', true, array( $nobody ) ) );

ob_start();
( new Admin( '' ) )->delete_user_note( wp_get_current_user(), array( $editor ) );
$note = ob_get_clean();
ob_start();
( new Admin( '' ) )->delete_user_note( wp_get_current_user(), array( $nobody ) );
$no_note = ob_get_clean();
agile_ct_check( 'delete screen explains entries are kept', false !== strpos( $note, 'never deleted' ) && '' === $no_note );

// Multisite: removing a user from a site with a new owner hands over entries there.
agile_ct_check( 'author shown by name', $display === Entries::author_name( Entries::get( $type, $alpha ) ) );
do_action( 'remove_user_from_blog', $editor, get_current_blog_id(), 0 );
agile_ct_check( 'removal without a new owner keeps the author', $editor === (int) Entries::get( $type, $alpha )['created_by'] );
do_action( 'remove_user_from_blog', $editor, get_current_blog_id(), $admin );
agile_ct_check( 'removal with a new owner hands entries over', $admin === (int) Entries::get( $type, $alpha )['created_by'] );
agile_ct_check( 'unknown author shown as a dash', '—' === Entries::author_name( array( 'created_by' => 99999999 ) ) );

wp_set_current_user( 0 );
