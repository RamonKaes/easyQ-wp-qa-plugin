/**
 * Playwright configuration, based on the defaults of `@wordpress/scripts`.
 */
const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
} );
