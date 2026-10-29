/**
 * The easyQ settings screen.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { activatePlugin } = require( './plugin' );

test.describe( 'Settings screen', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				easyq_settings_posting: {
					comment_on_qs: false,
					min_len_a_content: 12,
				},
			},
		} );
	} );

	test( 'saves a tab and keeps the other tabs', async ( { admin, page } ) => {
		const tabs = page.getByRole( 'navigation', {
			name: 'Settings sections',
		} );

		await admin.visitAdminPage( 'admin.php', 'page=easyq-settings' );
		await expect(
			tabs.getByRole( 'link', { name: 'General' } )
		).toHaveAttribute( 'aria-current', 'page' );

		await tabs.getByRole( 'link', { name: 'Posting' } ).click();
		await page.getByLabel( 'Allow comments on questions' ).check();
		await page
			.getByLabel( 'Minimum length of answers, in characters' )
			.fill( '20' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect(
			tabs.getByRole( 'link', { name: 'Posting' } )
		).toHaveAttribute( 'aria-current', 'page' );
		await expect(
			page.getByLabel( 'Allow comments on questions' )
		).toBeChecked();
		await expect(
			page.getByLabel( 'Minimum length of answers, in characters' )
		).toHaveValue( '20' );

		// Unchecking is saved too.
		await page.getByLabel( 'Allow comments on questions' ).uncheck();
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect(
			page.getByLabel( 'Allow comments on questions' )
		).not.toBeChecked();

		// Other tabs keep their values.
		await tabs.getByRole( 'link', { name: 'Viewing' } ).click();
		await expect(
			page.getByLabel( 'Allow voting on questions' )
		).toBeChecked();
	} );

	test( 'disables the points minimum while no points are needed', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=easyq-settings&tab=permissions'
		);
		const points = page.locator(
			'#easyq-permissions-permit-post-q-points'
		);

		// Like the page selection under Settings → Reading: in place, but disabled.
		await expect( points ).toBeVisible();
		await expect( points ).toBeDisabled();
		await page
			.getByLabel( 'Asking questions' )
			.selectOption( { label: 'Registered users with enough points' } );
		await expect( points ).toBeEnabled();
		await page
			.getByLabel( 'Asking questions' )
			.selectOption( { label: 'Registered users' } );
		await expect( points ).toBeDisabled();
		await expect( points ).toBeVisible();
	} );

	test( 'has no accessibility violations on any tab', async ( {
		admin,
		page,
	} ) => {
		for ( const tab of [
			'general',
			'permissions',
			'users',
			'posting',
			'viewing',
			'lists',
			'feeds',
			'spam',
		] ) {
			await admin.visitAdminPage(
				'admin.php',
				`page=easyq-settings&tab=${ tab }`
			);
			const results = await new AxeBuilder( { page } )
				.include( '#wpbody-content .wrap' )
				.analyze();
			expect( results.violations, tab ).toEqual( [] );
		}
	} );
} );
