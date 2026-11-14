/**
 * The tests and the Info section of easyQ in Tools → Site Health.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { activatePlugin } = require( './plugin' );

/**
 * Turns the read-only mode of easyQ on or off.
 *
 * @param {Object}  requestUtils Request utilities of the test.
 * @param {boolean} enabled      Whether the Q&A area is read-only.
 */
const setReadOnly = ( requestUtils, enabled ) =>
	requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/settings',
		data: { easyq_settings_general: { read_only: enabled } },
	} );

test.describe( 'Site Health', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
		await setReadOnly( requestUtils, true );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setReadOnly( requestUtils, false );
	} );

	test( 'shows the tests of easyQ', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'site-health.php' );

		const recommended = page.locator(
			'#health-check-site-status-recommended'
		);
		await expect(
			recommended.getByRole( 'button', {
				name: 'The Q&A area is in read-only mode',
			} )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Passed tests' } ).click();
		const passed = page.locator( '#health-check-issues-good' );
		await expect(
			passed.getByRole( 'button', {
				name: 'The Q&A database tables are up to date',
			} )
		).toBeVisible();

		await recommended
			.getByRole( 'button', {
				name: 'The Q&A area is in read-only mode',
			} )
			.click();
		await expect(
			recommended.getByRole( 'link', {
				name: 'Review the read-only mode',
			} )
		).toHaveAttribute(
			'href',
			/page=easyq-settings&tab=general#easyq-general-read-only$/
		);
	} );

	test( 'describes the Q&A area on the Info tab', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'site-health.php', 'tab=debug' );

		await page
			.getByRole( 'button', { name: 'easyQ Questions and Answers' } )
			.click();
		const section = page.locator( '#health-check-accordion-block-easyq' );
		await expect(
			section.getByRole( 'row', { name: /Read-only mode\s+Yes/ } )
		).toBeVisible();
		await expect(
			section.getByRole( 'row', { name: /Database version/ } )
		).toBeVisible();

		const results = await new AxeBuilder( { page } )
			.include( '#health-check-accordion-block-easyq' )
			.analyze();
		expect( results.violations ).toEqual( [] );
	} );
} );
