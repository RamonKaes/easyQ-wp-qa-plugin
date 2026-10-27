/**
 * The easyQ blocks in the block editor: every block renders without errors,
 * and the lists preview real questions and users.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Block editor', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await requestUtils.activatePlugin( 'easyq' );
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Question for the editor preview ${ Date.now() }`,
				content: 'Shown in the block editor.',
			},
		} );
	} );

	test( 'renders every easyQ block with a preview and without errors', async ( {
		admin,
		editor,
		page,
	} ) => {
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				errors.push( message.text() );
			}
		} );

		await admin.createNewPost();
		const names = await page.evaluate( () => {
			const types = window.wp.blocks
				.getBlockTypes()
				.filter(
					( type ) =>
						type.name.startsWith( 'easyq/' ) &&
						! type.parent &&
						! type.ancestor
				);
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlocks(
					types.map( ( type ) =>
						window.wp.blocks.createBlock( type.name )
					)
				);
			return types.map( ( type ) => type.name );
		} );
		expect( names.length ).toBeGreaterThan( 30 );

		// The lists load real content from the REST API.
		await expect(
			editor.canvas
				.locator( '[data-type="easyq/question-template"]' )
				.getByText( /Question for the editor preview/ )
		).toBeVisible();
		await expect(
			editor.canvas
				.locator( '[data-type="easyq/user-template"]' )
				.getByText( 'admin', { exact: true } )
		).toBeVisible();

		// No block shows the "This block has encountered an error" message.
		await expect(
			editor.canvas.locator(
				'.block-editor-block-list__block-crash-warning, .block-editor-warning'
			)
		).toHaveCount( 0 );
		expect( errors ).toEqual( [] );
	} );
} );
