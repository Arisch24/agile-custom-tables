( function () {
	'use strict';

	// Confirm before destructive links.
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( 'a.cct-confirm' );
		if ( link && ! window.confirm( link.getAttribute( 'data-confirm' ) || 'Are you sure?' ) ) {
			e.preventDefault();
		}
	} );

	var body = document.getElementById( 'cct-fields-body' );
	var tpl = document.getElementById( 'cct-field-template' );
	var addBtn = document.getElementById( 'cct-add-field' );
	if ( ! body || ! tpl || ! addBtn ) {
		return;
	}

	var counter = body.querySelectorAll( 'tr' ).length + 1000;

	function slugify( str ) {
		return str
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.replace( /^[0-9]+/, '' );
	}

	function toggleOptions( row ) {
		var type = row.querySelector( '.cct-type' ).value;
		row.querySelector( '.cct-options' ).style.visibility = type === 'select' ? 'visible' : 'hidden';
	}

	function wire( row ) {
		var label = row.querySelector( '.cct-label' );
		var key = row.querySelector( '.cct-key' );
		var touched = key.value !== '';

		key.addEventListener( 'input', function () {
			touched = true;
		} );
		label.addEventListener( 'input', function () {
			if ( ! touched && ! key.readOnly ) {
				key.value = slugify( label.value );
			}
		} );
		row.querySelector( '.cct-type' ).addEventListener( 'change', function () {
			toggleOptions( row );
		} );
		toggleOptions( row );
	}

	Array.prototype.forEach.call( body.querySelectorAll( 'tr' ), wire );

	addBtn.addEventListener( 'click', function () {
		var html = tpl.innerHTML.replace( /__i__/g, String( counter++ ) );
		var holder = document.createElement( 'tbody' );
		holder.innerHTML = html;
		var row = holder.querySelector( 'tr' );
		body.appendChild( row );
		wire( row );
		row.querySelector( '.cct-label' ).focus();
	} );

	body.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.cct-remove-field' );
		if ( ! btn ) {
			return;
		}
		if ( body.querySelectorAll( 'tr' ).length > 1 ) {
			btn.closest( 'tr' ).remove();
		}
	} );
} )();
