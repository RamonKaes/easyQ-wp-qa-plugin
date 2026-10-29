/**
 * The easyQ moderation screen.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { activatePlugin } = require( './plugin' );

const IP = '192.0.2.44';

test.describe( 'Moderation screen', () => {
	let question;

	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
		question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: 'A question for the moderation screen',
				content: 'It gets hidden and shown again.',
			},
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/posts/${ question.id }/actions/hide`,
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/posts/${ question.id }/actions/hide`,
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/posts/${ question.id }/actions/delete`,
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { easyq_settings_spam: { block_ips_write: '' } },
		} );
	} );

	test( 'shows a hidden post again with a bulk action', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=easyq-posts&view=hidden'
		);
		await expect(
			page.getByRole( 'link', { name: /^Hidden/ } )
		).toHaveAttribute( 'aria-current', 'page' );

		await page.getByLabel( `Select post ${ question.id }` ).check();
		await page
			.getByLabel( 'Select bulk action' )
			.first()
			.selectOption( 'reshow' );
		await page.getByRole( 'button', { name: 'Apply' } ).first().click();

		await expect( page.getByText( '1 post shown again.' ) ).toBeVisible();
		await expect( page.getByText( 'No posts are hidden.' ) ).toBeVisible();
	} );

	test( 'blocks and unblocks an IP address', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'admin.php',
			`page=easyq-posts&ip=${ IP }`
		);
		await expect(
			page.getByRole( 'heading', { name: `IP address ${ IP }` } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Block this IP address' } )
			.click();
		await expect(
			page.getByText( 'The IP address is blocked.' )
		).toBeVisible();
		await expect(
			page.getByText( `This address is blocked by: ${ IP }` )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Unblock this IP address' } )
			.click();
		await expect(
			page.getByText( 'The IP address is no longer blocked.' )
		).toBeVisible();
	} );

	test( 'has no accessibility violations', async ( { admin, page } ) => {
		for ( const query of [
			'',
			'&view=pending',
			'&view=flagged',
			'&view=hidden',
			'&view=closed',
			`&ip=${ IP }`,
		] ) {
			await admin.visitAdminPage(
				'admin.php',
				`page=easyq-posts${ query }`
			);
			const results = await new AxeBuilder( { page } )
				.include( '#wpbody-content .wrap' )
				.analyze();
			expect( results.violations, query || 'all' ).toEqual( [] );
		}
	} );
} );
