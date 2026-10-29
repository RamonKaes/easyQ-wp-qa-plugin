/**
 * Build configuration: one editor script for all blocks, the script of the tools
 * screen, and one interactivity module.
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
		entry: {
			editor: './src/editor/index.js',
			tools: './src/tools/index.js',
		},
	},
	{
		...moduleConfig,
		entry: { view: './src/view/index.js' },
	},
];
