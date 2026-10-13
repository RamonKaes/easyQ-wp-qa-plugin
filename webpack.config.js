/**
 * Build configuration: one editor script for all blocks and one interactivity module.
 *
 * Run with `wp-scripts build --experimental-modules`.
 */
const [
	scriptConfig,
	moduleConfig,
] = require( '@wordpress/scripts/config/webpack.config' );

module.exports = [
	{
		...scriptConfig,
		entry: { editor: './src/editor/index.js' },
	},
	{
		...moduleConfig,
		entry: { view: './src/view/index.js' },
	},
];
