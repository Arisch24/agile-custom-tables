<?php
/**
 * Content type validation and table sync.
 */

use AgileCustomTables\Registry;

global $wpdb;

$type  = agile_ct_test_type(
	'zz_test_reg',
	array(
		array(
			'label'    => 'Title',
			'type'     => 'text',
			'required' => '1',
		),
		array(
			'label' => 'Body',
			'key'   => 'body',
			'type'  => 'textarea',
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
			'label' => 'Link',
			'key'   => 'link',
			'type'  => 'url',
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
$table = Registry::table( 'zz_test_reg' );

agile_ct_check( 'table uses the agile_ct_ prefix', $wpdb->prefix . 'agile_ct_zz_test_reg' === $table );

$columns = array();
foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ) as $col ) {
	// MySQL 8 omits integer display widths; MariaDB keeps them.
	$columns[ $col->Field ] = preg_replace( '/^bigint\(\d+\)/', 'bigint', strtolower( $col->Type ) );
}
$expected = array(
	'id'         => 'bigint unsigned',
	'title'      => 'varchar(255)',
	'body'       => 'longtext',
	'price'      => 'double',
	'email'      => 'varchar(255)',
	'link'       => 'varchar(255)',
	'starts'     => 'date',
	'featured'   => 'tinyint(1)',
	'tier'       => 'varchar(255)',
	'created_by' => 'bigint unsigned',
	'created_at' => 'datetime',
	'updated_at' => 'datetime',
);
agile_ct_check( 'columns match the fields', $expected === $columns, wp_json_encode( $columns ) );
agile_ct_check( 'created_by is indexed', null !== $wpdb->get_row( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'created_by'" ) );

// Re-syncing an unchanged type must not alter the table.
$agile_ct_altered = array();
$agile_ct_record  = static function ( $sql ) use ( &$agile_ct_altered ) {
	if ( preg_match( '/^\s*(ALTER|CREATE)\b/i', $sql ) ) {
		$agile_ct_altered[] = $sql;
	}
	return $sql;
};
add_filter( 'query', $agile_ct_record );
Registry::sync_table( $type );
remove_filter( 'query', $agile_ct_record );
agile_ct_check( 're-sync of an unchanged type is a no-op', array() === $agile_ct_altered, implode( ' | ', $agile_ct_altered ) );

// Validation.
$rejects = static function ( array $input, bool $existing = false ): bool {
	return is_wp_error( Registry::sanitize_type( $input, $existing ) );
};
$base    = array(
	'slug'     => 'zz_test_v',
	'singular' => 'V',
	'plural'   => 'Vs',
	'fields'   => array( array( 'label' => 'Name' ) ),
);
$with    = static function ( array $fields ) use ( $base ): array {
	return array_merge( $base, array( 'fields' => $fields ) );
};

agile_ct_check( 'valid type accepted', ! $rejects( $base ) );
agile_ct_check( 'bad slug rejected', $rejects( array_merge( $base, array( 'slug' => '9bad' ) ) ) );
agile_ct_check( 'taken slug rejected', $rejects( array_merge( $base, array( 'slug' => 'zz_test_reg' ) ) ) );
agile_ct_check( 'missing names rejected', $rejects( array_merge( $base, array( 'plural' => '' ) ) ) );
agile_ct_check( 'type without fields rejected', $rejects( $with( array() ) ) );
agile_ct_check(
	'select without options rejected',
	$rejects(
		$with(
			array(
				array(
					'label' => 'S',
					'type'  => 'select',
				),
			)
		)
	)
);
agile_ct_check(
	'duplicate keys rejected',
	$rejects(
		$with(
			array(
				array(
					'label' => 'A',
					'key'   => 'a',
				),
				array(
					'label' => 'B',
					'key'   => 'a',
				),
			)
		)
	)
);
foreach ( array( 'id', 'created_by', 'created_at', 'key', 'index', 'primary' ) as $reserved ) {
	agile_ct_check(
		"reserved key '$reserved' rejected",
		$rejects(
			$with(
				array(
					array(
						'label' => 'X',
						'key'   => $reserved,
					),
				)
			)
		)
	);
}
$keyed = Registry::sanitize_type( $with( array( array( 'label' => 'Event Title' ) ) ) );
agile_ct_check( 'key generated from label', ! is_wp_error( $keyed ) && 'event_title' === $keyed['fields'][0]['key'] );

// Type changes on a table that already has columns.
$input                      = agile_ct_as_input( $type );
$input['fields'][0]['type'] = 'number';
agile_ct_check( 'text to number rejected', $rejects( $input, true ) );

$input                      = agile_ct_as_input( $type );
$input['fields'][0]['type'] = 'textarea';
agile_ct_check( 'text to textarea allowed', ! $rejects( $input, true ) );

$input                      = agile_ct_as_input( $type );
$input['fields'][1]['type'] = 'text';
agile_ct_check( 'textarea to text rejected', $rejects( $input, true ) );

$input                         = agile_ct_as_input( $type );
$input['fields'][0]['type']    = 'select';
$input['fields'][0]['options'] = "A\nB";
agile_ct_check( 'text to select allowed', ! $rejects( $input, true ) );

// Removing a field keeps its column, and blocks re-adding it with another type.
$input = agile_ct_as_input( $type );
array_splice( $input['fields'], 2, 1 ); // Price.
Registry::save_type( Registry::sanitize_type( $input, true ) );
agile_ct_check( 'removed field keeps its column', null !== $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'price'" ) );

$input['fields'][] = array(
	'label' => 'Price',
	'key'   => 'price',
	'type'  => 'text',
);
agile_ct_check( 're-adding a field with another type rejected', $rejects( $input, true ) );

array_pop( $input['fields'] );
$input['fields'][] = array(
	'label' => 'Venue',
	'key'   => 'venue',
	'type'  => 'text',
);
Registry::save_type( Registry::sanitize_type( $input, true ) );
agile_ct_check( 'new field adds a column', null !== $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'venue'" ) );

// Deleting a type keeps its table unless asked.
Registry::delete_type( 'zz_test_reg' );
agile_ct_check( 'deleted type is gone', null === Registry::get( 'zz_test_reg' ) );
agile_ct_check( 'deleted type keeps its table', agile_ct_table_exists( $table ) );
