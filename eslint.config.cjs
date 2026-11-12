module.exports = [
	...require( '@wordpress/scripts/config/eslint.config.cjs' ),
	// Command-line scripts report through the console.
	{ files: [ 'bin/**' ], rules: { 'no-console': 'off' } },
];
