/**
 * The easyQ points, user titles and user fields screens.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { activatePlugin } = require( './plugin' );

test.describe( 'Points, user titles and user fields screens', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				easyq_settings_points: { points_post_q: 2, user_titles: {} },
			},
		} );
		const fields = await requestUtils.rest( {
			path: '/easyq/v1/user-fields',
		} );
		for ( const field of fields ) {
			if ( 'location' !== field.name ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/easyq/v1/user-fields/${ field.id }`,
					params: { force: true },
				} );
			}
		}
	} );

	test( 'saves points and recalculates them', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=easyq-points' );
		await page.getByLabel( 'Posting a question' ).fill( '3' );
		await page
			.getByRole( 'button', { name: 'Save and Recalculate' } )
			.click();

		await expect(
			page.getByText(
				'Settings saved. The points of all users were recalculated.'
			)
		).toBeVisible();
		await expect( page.getByLabel( 'Posting a question' ) ).toHaveValue(
			'3'
		);

		await page.getByRole( 'link', { name: 'Show Default Values' } ).click();
		await expect( page.getByLabel( 'Posting a question' ) ).toHaveValue(
			'2'
		);
	} );

	test( 'adds and removes a user title', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=easyq-user-titles' );
		await page.getByLabel( 'Points needed for title 1' ).fill( '500' );
		await page.getByLabel( 'Title 1', { exact: true } ).fill( 'Regular' );
		await page.getByRole( 'button', { name: 'Save Titles' } ).click();

		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect(
			page.getByLabel( 'Title 1', { exact: true } )
		).toHaveValue( 'Regular' );

		await page.getByLabel( 'Title 1', { exact: true } ).fill( '' );
		await page.getByRole( 'button', { name: 'Save Titles' } ).click();
		await expect(
			page.getByLabel( 'Points needed for title 1' )
		).toHaveValue( '' );
	} );

	test( 'adds, edits and deletes a user field', async ( { admin, page } ) => {
		const list = page.getByRole( 'table' );

		await admin.visitAdminPage( 'admin.php', 'page=easyq-user-fields' );
		await page
			.getByRole( 'textbox', { name: 'Label' } )
			.fill( 'Favourite Book' );
		await page
			.getByRole( 'combobox', { name: 'Type' } )
			.selectOption( 'multi_line' );
		await page.getByLabel( 'Show on the registration form' ).check();
		await page.getByRole( 'button', { name: 'Add User Field' } ).click();

		await expect( page.getByText( 'User field added.' ) ).toBeVisible();
		await expect( list.getByText( 'favourite-book' ) ).toBeVisible();

		await list
			.getByRole( 'link', { name: 'Favourite Book', exact: true } )
			.click();
		await page.getByRole( 'textbox', { name: 'Label' } ).fill( 'Book' );
		await page
			.getByRole( 'combobox', { name: 'Position' } )
			.selectOption( { label: 'First' } );
		await page.getByRole( 'button', { name: 'Update' } ).click();
		await expect( page.getByText( 'User field saved.' ) ).toBeVisible();
		await expect(
			page.getByRole( 'textbox', { name: 'Label' } )
		).toHaveValue( 'Book' );

		await page
			.getByLabel(
				'I understand that the field and its values are deleted for good.'
			)
			.check();
		await page.getByRole( 'button', { name: 'Delete User Field' } ).click();
		await expect( page.getByText( 'User field deleted.' ) ).toBeVisible();
		await expect( list.getByText( 'favourite-book' ) ).toHaveCount( 0 );
	} );

	test( 'blocks and unblocks users through the bulk actions of the users list', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const login = `blocked${ Date.now() }`;
		const user = await requestUtils.createUser( {
			username: login,
			email: `${ login }@example.org`,
			password: 'password',
			roles: [ 'subscriber' ],
		} );

		const bulk = async ( action ) => {
			await admin.visitAdminPage( 'users.php', `s=${ login }` );
			await page.locator( `#user_${ user.id }` ).check();
			await page
				.locator( '#bulk-action-selector-top' )
				.selectOption( action );
			await page.locator( '#doaction' ).click();
		};

		await bulk( 'easyq_block' );
		await expect( page.getByText( '1 user blocked.' ) ).toBeVisible();
		await admin.visitAdminPage( 'users.php', 'easyq_status=blocked' );
		await expect( page.locator( `#user_${ user.id }` ) ).toBeVisible();

		await bulk( 'easyq_unblock' );
		await expect( page.getByText( '1 user unblocked.' ) ).toBeVisible();
		await admin.visitAdminPage( 'users.php', 'easyq_status=blocked' );
		await expect( page.locator( `#user_${ user.id }` ) ).toBeHidden();

		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/users/${ user.id }`,
			params: { force: true, reassign: 1 },
		} );
	} );

	test( 'has no accessibility violations', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const field = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/user-fields',
			data: { label: 'Accessible field' },
		} );

		for ( const query of [
			'page=easyq-points',
			'page=easyq-user-titles',
			'page=easyq-user-fields',
			`page=easyq-user-fields&action=edit&field=${ field.id }`,
		] ) {
			await admin.visitAdminPage( 'admin.php', query );
			const results = await new AxeBuilder( { page } )
				.include( '#wpbody-content .wrap' )
				.analyze();
			expect( results.violations, query ).toEqual( [] );
		}
	} );
} );
