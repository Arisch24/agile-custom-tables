const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// One admin app per entry; output lands in build/<entry>.js + .asset.php.
module.exports = {
	...defaultConfig,
	entry: {
		settings: './src/settings/index.js',
	},
};
