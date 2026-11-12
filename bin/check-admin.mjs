#!/usr/bin/env node
/**
 * Drive the admin screens in a real browser: type builder, entries, the
 * settings app, and what an Editor can and cannot reach.
 *
 * Uses the reserved accounts test-agile-ct-check-admin and
 * test-agile-ct-check-editor and the content type zz_test_ui, and removes
 * all three afterwards.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = (
	process.env.WP_URL || 'https://plugin-dev-wp.ddev.site'
).replace( /\/$/, '' );
const root = fileURLToPath( new URL( '..', import.meta.url ) );
const slug = 'zz_test_ui';
const accounts = {
	admin: {
		login: 'test-agile-ct-check-admin',
		role: 'administrator',
		name: 'Check Admin',
	},
	editor: {
		login: 'test-agile-ct-check-editor',
		role: 'editor',
		name: 'Check Editor',
	},
};

const wp = ( ...args ) =>
	execFileSync( 'ddev', [ 'wp', ...args ], {
		cwd: root,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();

// The settings check flips a real site option; put it back however the run ends.
let savedSettings;
const rememberSettings = () => {
	try {
		savedSettings = wp(
			'option',
			'get',
			'agile_ct_settings',
			'--format=json'
		);
	} catch {
		savedSettings = null; // Never saved: defaults apply.
	}
};
const restoreSettings = () => {
	if ( savedSettings === undefined ) {
		return;
	}
	if ( savedSettings === null ) {
		try {
			wp( 'option', 'delete', 'agile_ct_settings' );
		} catch {
			// Already gone.
		}
	} else {
		wp(
			'option',
			'update',
			'agile_ct_settings',
			savedSettings,
			'--format=json'
		);
	}
};

const removeFixtures = () => {
	wp(
		'eval',
		`AgileCustomTables\\Registry::delete_type( '${ slug }', true );
		global $wpdb; $wpdb->query( 'DROP TABLE IF EXISTS \`' . AgileCustomTables\\Registry::table( '${ slug }' ) . '\`' );`
	);
	for ( const { login } of Object.values( accounts ) ) {
		try {
			wp( 'user', 'delete', login, '--yes' );
		} catch {
			// Not there.
		}
	}
};

const problems = [];
// Screens an Editor must be refused (Chrome logs each 403 as a console error).
const forbidden = [
	'admin.php?page=agile-ct-types',
	'admin.php?page=agile-ct-type-new',
	'admin.php?page=agile-ct-settings',
];
const steps = [];
const step = ( label ) => {
	steps.push( label );
	console.log( `✓ ${ label }` );
};

async function newSession( browser, account ) {
	const context = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	page.on( 'pageerror', ( error ) =>
		problems.push( `JS error: ${ error.message }` )
	);
	page.on( 'console', ( message ) => {
		const refusedOnPurpose =
			/status of 403/.test( message.text() ) &&
			forbidden.some( ( path ) =>
				message.location().url.endsWith( path )
			);
		if ( message.type() === 'error' && ! refusedOnPurpose ) {
			problems.push( `console error: ${ message.text() }` );
		}
	} );
	page.on( 'load', async () => {
		const html = await page.content().catch( () => '' );
		const noise = html.match(
			/(Warning|Notice|Deprecated|Fatal error)<\/b>:[^<]{0,200}|wpdberror/
		);
		if ( noise ) {
			problems.push( `PHP output on ${ page.url() }: ${ noise[ 0 ] }` );
		}
	} );
	await page.goto( `${ base }/wp-login.php` );
	await page.fill( '#user_login', account.login );
	await page.fill( '#user_pass', account.password );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	return { context, page };
}

const notice = async ( page ) =>
	( await page.locator( '.notice p' ).first().textContent() ).trim();
const go = async ( page, path ) => {
	await page.goto( `${ base }/wp-admin/${ path }` );
};
const submit = async ( page, selector = '#submit' ) => {
	await Promise.all( [ page.waitForNavigation(), page.click( selector ) ] );
};

let browser;
try {
	rememberSettings();
	removeFixtures();
	for ( const account of Object.values( accounts ) ) {
		account.password = randomBytes( 18 ).toString( 'hex' );
		wp(
			'user',
			'create',
			account.login,
			`${ account.login }@example.invalid`,
			`--role=${ account.role }`,
			`--display_name=${ account.name }`,
			`--user_pass=${ account.password }`
		);
	}

	browser = await chromium.launch();

	// --- Administrator ---------------------------------------------------
	const { page } = await newSession( browser, accounts.admin );
	const rows = page.locator( '#agile-ct-fields-body tr' );

	await go( page, 'admin.php?page=agile-ct-type-new' );
	await page.fill( '#agile-ct-singular', 'Event' );
	await page.fill( '#agile-ct-plural', 'Events' );
	await page.fill( '#agile-ct-slug', slug );
	await rows.nth( 0 ).locator( '.agile-ct-label' ).fill( 'Event Title' );
	assert.equal(
		await rows.nth( 0 ).locator( '.agile-ct-key' ).inputValue(),
		'event_title'
	);
	await rows.nth( 0 ).locator( 'input[type=checkbox]' ).check();
	const addField = async ( label, type, options ) => {
		await page.click( '#agile-ct-add-field' );
		const row = rows.last();
		await row.locator( '.agile-ct-label' ).fill( label );
		await row.locator( '.agile-ct-type' ).selectOption( type );
		if ( options ) {
			await row.locator( '.agile-ct-options' ).fill( options );
		}
	};
	await addField( 'Tier', 'select', 'Gold\nSilver' );
	assert.equal(
		await rows
			.last()
			.locator( '.agile-ct-options' )
			.evaluate(
				( el ) =>
					el.ownerDocument.defaultView.getComputedStyle( el )
						.visibility
			),
		'visible'
	);
	await addField( 'Starts', 'date' );
	await addField( 'Featured', 'checkbox' );
	await addField( 'Price', 'number' );
	await addField( 'Junk', 'text' );
	await rows.last().locator( '.agile-ct-remove-field' ).click();
	assert.equal( await rows.count(), 5 );
	await submit( page );
	assert.equal( await notice( page ), 'Content type saved.' );
	step( 'admin builds a content type' );

	await go( page, `admin.php?page=agile-ct-entries-${ slug }&action=add` );
	await page.fill( '#agile-ct-f-price', '42.75' );
	await page.evaluate( () =>
		document.querySelector( '.wrap form' ).setAttribute( 'novalidate', '' )
	);
	await submit( page );
	assert.equal( await notice( page ), '"Event Title" is required.' );
	assert.equal( await page.inputValue( '#agile-ct-f-price' ), '42.75' );
	step( 'validation error keeps typed values' );

	await page.fill( '#agile-ct-f-event_title', 'Launch' );
	await page.selectOption( '#agile-ct-f-tier', 'Gold' );
	await page.fill( '#agile-ct-f-starts', '2026-12-01' );
	await page.check( '#agile-ct-f-featured' );
	await submit( page );
	assert.equal( await notice( page ), 'Saved.' );
	const firstRow = page.locator( '.wp-list-table tbody tr' ).first();
	assert.match(
		( await firstRow.innerText() ).replace( /\s+/g, ' ' ),
		/Launch .*Gold 2026-12-01 Yes 42\.75 Check Admin/
	);
	step( 'admin adds an entry; Author column shows them' );

	await firstRow.getByRole( 'link', { name: 'Edit', exact: true } ).click();
	await page.waitForLoadState( 'load' );
	assert.match(
		await page.locator( '.agile-ct-entry-meta' ).innerText(),
		/^Created by Check Admin on /
	);
	await page.fill( '#agile-ct-f-event_title', 'Launch (edited)' );
	await page.uncheck( '#agile-ct-f-featured' );
	await submit( page );
	assert.match(
		( await firstRow.innerText() ).replace( /\s+/g, ' ' ),
		/Launch \(edited\) .* No /
	);
	step( 'admin edits an entry' );

	await go( page, `admin.php?page=agile-ct-types&type=${ slug }` );
	assert.notEqual(
		await page.locator( '#agile-ct-slug' ).getAttribute( 'readonly' ),
		null
	);
	await rows.nth( 0 ).locator( '.agile-ct-type' ).selectOption( 'number' );
	await submit( page );
	assert.match( await notice( page ), /could wipe existing values/ );
	step( 'lossy field type change is blocked' );

	await go( page, 'admin.php?page=agile-ct-settings' );
	await page.locator( '.agile-ct-header__title' ).waitFor();
	const toggle = page.getByLabel(
		'Delete all data when the plugin is deleted'
	);
	const save = page.getByRole( 'button', { name: 'Save settings' } );
	const initial = await toggle.isChecked();
	assert.equal( await save.isDisabled(), true );
	await toggle.setChecked( ! initial );
	assert.equal(
		await page.locator( '.agile-ct-settings__warning' ).isVisible(),
		! initial
	);
	await save.click();
	await page
		.locator( '#agile-ct-settings-root' )
		.getByText( 'Settings saved.' )
		.waitFor();
	await page.reload();
	assert.equal( await toggle.isChecked(), ! initial );
	await toggle.setChecked( initial );
	await save.click();
	await page
		.locator( '#agile-ct-settings-root' )
		.getByText( 'Settings saved.' )
		.waitFor();
	step( 'settings app saves and reloads the uninstall option' );

	await go( page, `admin.php?page=agile-ct-entries-${ slug }` );
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await Promise.all( [
		page.waitForNavigation(),
		firstRow.getByRole( 'link', { name: 'Delete', exact: true } ).click(),
	] );
	assert.equal( await notice( page ), 'Deleted.' );
	step( 'admin deletes an entry after confirming' );

	// --- Editor ----------------------------------------------------------
	const { page: editor } = await newSession( browser, accounts.editor );
	const menuLinks = await editor
		.locator(
			'#toplevel_page_agile-ct-types a, #adminmenu a[href*="agile-ct-"]'
		)
		.evaluateAll( ( links ) =>
			links.map( ( a ) => a.getAttribute( 'href' ) )
		);
	assert.ok(
		menuLinks.some( ( href ) =>
			href.includes( `page=agile-ct-entries-${ slug }` )
		),
		`menu links: ${ menuLinks }`
	);
	assert.ok(
		! menuLinks.some( ( href ) =>
			/page=agile-ct-(types|type-new|settings)\b/.test( href )
		),
		`menu links: ${ menuLinks }`
	);
	step( 'editor menu lists entries, not types or settings' );

	await go( editor, `admin.php?page=agile-ct-entries-${ slug }&action=add` );
	await editor.fill( '#agile-ct-f-event_title', 'Editor entry' );
	await submit( editor );
	assert.equal( await notice( editor ), 'Saved.' );
	assert.match(
		await editor.locator( '.wp-list-table tbody tr' ).first().innerText(),
		/Check Editor/
	);
	step( 'editor adds an entry as its author' );

	for ( const path of forbidden ) {
		const response = await editor.goto( `${ base }/wp-admin/${ path }` );
		assert.equal( response.status(), 403, path );
	}
	step( 'editor is refused the type and settings screens' );

	assert.deepEqual( problems, [] );
	console.log( `\nAll ${ steps.length } admin checks passed.` );
} catch ( error ) {
	if ( problems.length ) {
		console.error( problems.join( '\n' ) );
	}
	console.error( error );
	process.exitCode = 1;
} finally {
	if ( browser ) {
		await browser.close();
	}
	restoreSettings();
	removeFixtures();
}
