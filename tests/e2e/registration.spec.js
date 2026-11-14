/**
 * The Q&A parts of the WordPress registration form.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

/**
 * Turns "Anyone can register" of Settings → General on or off.
 *
 * @param {Object}  admin   Admin fixture.
 * @param {Object}  page    Page of the administrator.
 * @param {boolean} enabled Whether anyone can register.
 */
async function setRegistration( admin, page, enabled ) {
	await admin.visitAdminPage( 'options-general.php' );
	await page.getByLabel( 'Anyone can register' ).setChecked( enabled );
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
}

test.describe( 'Registration form', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await activatePlugin( requestUtils );
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				easyq_settings_users: {
					show_custom_register: true,
					custom_register: 'Please read the house rules first.',
					show_register_terms: true,
				},
				easyq_settings_newsletter: { mailing_enabled: true },
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				easyq_settings_users: {
					show_custom_register: false,
					custom_register: '',
					show_register_terms: false,
				},
				easyq_settings_newsletter: { mailing_enabled: false },
			},
		} );
	} );

	test( 'shows the text, the terms and the newsletter and checks them', async ( {
		admin,
		page,
		browser,
	} ) => {
		await setRegistration( admin, page, true );
		const visitor = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );

		try {
			const form = await visitor.newPage();
			await form.goto( '/wp-login.php?action=register' );

			await expect(
				form.getByText( 'Please read the house rules first.' )
			).toBeVisible();
			await expect(
				form.getByLabel( 'Send me the newsletter' )
			).not.toBeChecked();
			await expect(
				form.getByLabel( 'I accept the terms' )
			).not.toBeChecked();

			const results = await new AxeBuilder( { page: form } )
				.include( '#registerform' )
				.include( '.notice.message' )
				.analyze();
			expect( results.violations ).toEqual( [] );

			await form.getByLabel( 'Username' ).fill( 'e2e-registrant' );
			await form
				.getByRole( 'textbox', { name: 'Email' } )
				.fill( 'e2e-registrant@example.org' );
			await form.getByRole( 'button', { name: 'Register' } ).click();
			await expect(
				form.getByText( 'Please accept the terms.' )
			).toBeVisible();

			await form.getByLabel( 'Username' ).fill( 'anonymous' );
			await form.getByLabel( 'I accept the terms' ).check();
			await form.getByRole( 'button', { name: 'Register' } ).click();
			await expect(
				form.getByText( 'Sorry, that username is not allowed.' )
			).toBeVisible();
			await expect(
				form.getByText( 'Please accept the terms.' )
			).toBeHidden();
		} finally {
			await visitor.close();
			await setRegistration( admin, page, false );
		}
	} );
} );
