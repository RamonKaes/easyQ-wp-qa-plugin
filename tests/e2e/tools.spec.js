/**
 * The easyQ dashboard, the dashboard widget and the tools screen.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { activatePlugin } = require( './plugin' );

test.describe( 'Dashboard and tools', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
	} );

	test( 'shows the figures and the widget', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=easyq' );
		await expect(
			page.getByRole( 'heading', { name: 'Q&A Dashboard' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'rowheader', { name: 'Questions', exact: true } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Open Site Health' } )
		).toBeVisible();

		await admin.visitAdminPage( 'index.php' );
		await expect(
			page.getByRole( 'heading', { name: 'Questions and Answers' } )
		).toBeVisible();
	} );

	test( 'runs a tool to the end with a progress bar', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=easyq-tools' );
		const form = page.locator( 'form[data-easyq-tool="recount_posts"]' );

		await form.getByRole( 'button', { name: 'Run' } ).click();

		await expect( form.getByRole( 'status' ) ).toHaveText( 'Done.' );
		await expect( form.locator( 'progress' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Run' } ).first()
		).toBeEnabled();
	} );

	test( 'asks to confirm before deleting hidden posts', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=easyq-tools' );
		const form = page.locator( 'form[data-easyq-tool="delete_hidden"]' );

		await form.getByRole( 'button', { name: 'Run' } ).click();
		await expect( form.getByRole( 'status' ) ).toHaveText( '' );

		await form
			.getByLabel( 'I understand that the posts are deleted for good.' )
			.check();
		await form.getByRole( 'button', { name: 'Run' } ).click();
		await expect( form.getByRole( 'status' ) ).toHaveText( 'Done.' );
	} );

	test( 'has no accessibility violations', async ( { admin, page } ) => {
		for ( const query of [ 'page=easyq', 'page=easyq-tools' ] ) {
			await admin.visitAdminPage( 'admin.php', query );
			const results = await new AxeBuilder( { page } )
				.include( '#wpbody-content .wrap' )
				.analyze();
			expect( results.violations, query ).toEqual( [] );
		}
	} );
} );
