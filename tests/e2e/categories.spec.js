/**
 * The easyQ categories screen.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { activatePlugin } = require( './plugin' );

/**
 * Deletes all categories, children first.
 *
 * @param {Object} requestUtils Request utilities.
 */
async function deleteCategories( requestUtils ) {
	const categories = await requestUtils.rest( {
		path: '/easyq/v1/categories',
	} );
	for ( const category of categories.reverse() ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/easyq/v1/categories/${ category.id }`,
		} );
	}
}

test.describe( 'Categories screen', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
		await deleteCategories( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteCategories( requestUtils );
	} );

	test( 'adds, edits and deletes a category', async ( { admin, page } ) => {
		// The admin menu has links with the same names, e.g. Appearance › Themes.
		const list = page.getByRole( 'table' );

		await admin.visitAdminPage( 'admin.php', 'page=easyq-categories' );

		await page.getByLabel( 'Name' ).fill( 'Software' );
		await page.getByRole( 'button', { name: 'Add Category' } ).click();
		await expect( page.getByText( 'Category added.' ) ).toBeVisible();
		await expect(
			list.getByRole( 'link', { name: 'Software', exact: true } )
		).toBeVisible();

		// A subcategory, then the same name again.
		await page.getByLabel( 'Name' ).fill( 'Block Themes' );
		await page
			.getByLabel( 'Parent Category' )
			.selectOption( { label: 'Software' } );
		await page.getByRole( 'button', { name: 'Add Category' } ).click();
		await expect(
			list.getByRole( 'link', { name: 'Block Themes', exact: true } )
		).toBeVisible();

		await page.getByLabel( 'Name' ).fill( 'block themes' );
		await page.getByRole( 'button', { name: 'Add Category' } ).click();
		await expect( page.getByLabel( 'Name' ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		await expect(
			page.getByText(
				'Another category with this name already exists here.'
			)
		).toBeVisible();
		await expect( page.getByLabel( 'Name' ) ).toHaveValue( 'block themes' );

		// Edit: rename and move to the top level.
		await list
			.getByRole( 'link', { name: 'Block Themes', exact: true } )
			.click();
		await expect(
			page.getByRole( 'heading', { name: 'Edit Category' } )
		).toBeVisible();
		await page.getByLabel( 'Name' ).fill( 'Themes' );
		await page
			.getByLabel( 'Parent Category' )
			.selectOption( { label: 'None (top level)' } );
		await page.getByRole( 'button', { name: 'Update' } ).click();
		await expect( page.getByText( 'Category saved.' ) ).toBeVisible();
		await expect( page.getByLabel( 'Name' ) ).toHaveValue( 'Themes' );
		await expect( page.getByLabel( 'Position' ) ).toHaveValue( '2' );

		await page.getByRole( 'button', { name: 'Delete Category' } ).click();
		await expect( page.getByText( 'Category deleted.' ) ).toBeVisible();
		await expect(
			list.getByRole( 'link', { name: 'Themes', exact: true } )
		).toHaveCount( 0 );
	} );

	test( 'has no accessibility violations', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const parent = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/categories',
			data: { title: 'Hardware', description: 'Computers and parts' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/categories',
			data: { title: 'Laptops', parent: parent.id },
		} );

		for ( const query of [
			'',
			`&action=edit&category=${ parent.id }`,
			`&action=reassign&category=${ parent.id }`,
			'&action=reassign&category=0',
		] ) {
			await admin.visitAdminPage(
				'admin.php',
				`page=easyq-categories${ query }`
			);
			const results = await new AxeBuilder( { page } )
				.include( '#wpbody-content .wrap' )
				.analyze();
			expect( results.violations, query || 'list' ).toEqual( [] );
		}
	} );

	test( 'opens a category from the dropdown of the categories block without JavaScript', async ( {
		browser,
		page,
		requestUtils,
	} ) => {
		const parent = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/categories',
			data: { title: 'Gadgets' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/categories',
			data: { title: 'Phones', parent: parent.id },
		} );
		const post = await requestUtils.createPage( {
			title: 'Category dropdown',
			status: 'publish',
			content: '<!-- wp:easyq/categories {"displayAsDropdown":true} /-->',
		} );

		await page.goto( post.link );
		const results = await new AxeBuilder( { page } )
			.include( '.wp-block-easyq-categories' )
			.analyze();
		expect( results.violations ).toEqual( [] );

		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const plain = await context.newPage();
		await plain.goto( post.link );

		await plain
			.getByRole( 'combobox', { name: 'Categories' } )
			.selectOption( 'gadgets/phones' );
		await plain.getByRole( 'button', { name: 'Go' } ).click();

		await expect(
			plain.getByRole( 'heading', { level: 1, name: /Phones/ } )
		).toBeVisible();
		await context.close();
		await requestUtils.deleteAllPages();
	} );
} );
