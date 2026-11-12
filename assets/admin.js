/**
 * Type builder and entry screens: confirm destructive links, add/remove
 * field rows, fill field keys from labels and show options for selects.
 */
( function () {
	'use strict';

	// Confirm before destructive links.
	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( 'a.agile-ct-confirm' );
		// eslint-disable-next-line no-alert -- a native confirm is enough here.
		if ( link && ! window.confirm( link.dataset.confirm ) ) {
			event.preventDefault();
		}
	} );

	const body = document.getElementById( 'agile-ct-fields-body' );
	const template = document.getElementById( 'agile-ct-field-template' );
	const addButton = document.getElementById( 'agile-ct-add-field' );
	if ( ! body || ! template || ! addButton ) {
		return;
	}

	// New rows get indexes that cannot clash with the server-rendered ones.
	let counter = body.querySelectorAll( 'tr' ).length + 1000;

	const slugify = ( text ) =>
		text
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.replace( /^[0-9]+/, '' );

	const toggleOptions = ( row ) => {
		const isSelect =
			row.querySelector( '.agile-ct-type' ).value === 'select';
		row.querySelector( '.agile-ct-options' ).style.visibility = isSelect
			? 'visible'
			: 'hidden';
	};

	const wire = ( row ) => {
		const label = row.querySelector( '.agile-ct-label' );
		const key = row.querySelector( '.agile-ct-key' );
		let touched = key.value !== '';

		key.addEventListener( 'input', () => {
			touched = true;
		} );
		label.addEventListener( 'input', () => {
			if ( ! touched && ! key.readOnly ) {
				key.value = slugify( label.value );
			}
		} );
		row.querySelector( '.agile-ct-type' ).addEventListener( 'change', () =>
			toggleOptions( row )
		);
		toggleOptions( row );
	};

	body.querySelectorAll( 'tr' ).forEach( wire );

	addButton.addEventListener( 'click', () => {
		const row = template.content.querySelector( 'tr' ).cloneNode( true );
		const index = String( counter++ );
		row.querySelectorAll( '[name]' ).forEach( ( input ) => {
			input.name = input.name.replace( '__i__', index );
		} );
		body.appendChild( row );
		wire( row );
		row.querySelector( '.agile-ct-label' ).focus();
	} );

	body.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.agile-ct-remove-field' );
		if ( button && body.querySelectorAll( 'tr' ).length > 1 ) {
			button.closest( 'tr' ).remove();
		}
	} );
} )();
