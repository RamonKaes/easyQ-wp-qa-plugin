/**
 * ESLint configuration: the default of `@wordpress/scripts`, which `wp-scripts lint-js`
 * uses without a project file, plus the folders the tests create.
 *
 * ESLint 9 reads neither `.eslintignore` nor `.gitignore`, so the traces of Playwright under
 * `artifacts/` would be linted after an end-to-end run.
 *
 * @since 0.1.0
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		ignores: [ 'artifacts/**' ],
	},
];
