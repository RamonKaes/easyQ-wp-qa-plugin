/**
 * Build configuration: one editor script for all blocks, the scripts of the
 * settings and tools screens, and one interactivity module.
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
			settings: './src/settings/index.js',
			tools: './src/tools/index.js',
		},
	},
	{
		...moduleConfig,
		entry: { view: './src/view/index.js' },
	},
];
