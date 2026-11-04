/**
 * The screen to edit the email templates.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { activatePlugin } = require( './plugin' );

test.describe( 'Email templates', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
	} );

	test( 'edits a text through the settings form and resets it', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=easyq-email-templates' );
		await page
			.getByRole( 'link', { name: 'Flagged post' } )
			.first()
			.click();
		await expect(
			page.getByRole( 'heading', { name: 'Flagged post' } )
		).toBeVisible();

		const subject = page.getByRole( 'textbox', {
			name: 'Subject',
		} );
		await subject.first().fill( 'A flag on ###SITENAME###' );
		await page
			.getByRole( 'textbox', { name: 'Text' } )
			.first()
			.fill( 'A post by ###AUTHOR### and ###TYPO###' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect(
			page.getByText( 'The text was saved, but this template' )
		).toBeVisible();
		await expect( subject.first() ).toHaveValue(
			'A flag on ###SITENAME###'
		);
		await expect( page.locator( '#easyq-preview-body' ) ).toHaveValue(
			'A post by Alex Example and ###TYPO###'
		);

		await page
			.getByRole( 'textbox', { name: 'Text' } )
			.first()
			.fill( 'A post by ###AUTHOR###' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect( page.locator( '#easyq-preview-body' ) ).toHaveValue(
			'A post by Alex Example'
		);

		await page
			.getByRole( 'button', { name: 'Reset to the default text' } )
			.click();
		await expect(
			page.getByText( 'The default text is back.' )
		).toBeVisible();
		await expect( subject.first() ).not.toHaveValue(
			'A flag on ###SITENAME###'
		);
		await expect(
			page.getByRole( 'button', { name: 'Reset to the default text' } )
		).toHaveCount( 0 );
	} );

	test( 'reports the result of a test email', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=easyq-email-templates&template=flagged'
		);
		await page
			.getByRole( 'button', { name: /^Send a test email to/ } )
			.click();

		// Whether the container can send mail depends on the machine; the tests of the PHP code check the email itself.
		await expect(
			page.getByText(
				/The test email (has been sent|could not be sent)\./
			)
		).toBeVisible();
	} );

	test( 'has no accessibility violations', async ( { admin, page } ) => {
		for ( const query of [
			'page=easyq-email-templates',
			'page=easyq-email-templates&template=welcome',
			'page=easyq-settings&tab=emails',
		] ) {
			await admin.visitAdminPage( 'admin.php', query );
			const results = await new AxeBuilder( { page } )
				.include( '#wpbody-content .wrap' )
				.analyze();
			expect( results.violations, query ).toEqual( [] );
		}
	} );
} );
