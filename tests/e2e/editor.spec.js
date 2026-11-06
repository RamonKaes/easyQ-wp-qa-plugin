/**
 * The easyQ blocks in the block editor: every block renders without errors,
 * and the lists preview real questions and users.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );

test.describe( 'Block editor', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
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

	test( 'gives every easyQ block an icon of its own', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost();

		const withoutIcon = await page.evaluate( () =>
			window.wp.blocks
				.getBlockTypes()
				.filter( ( type ) => type.name.startsWith( 'easyq/' ) )
				.filter(
					( type ) =>
						! type.icon?.src || type.icon.src === 'block-default'
				)
				.map( ( type ) => type.name )
		);

		expect( withoutIcon ).toEqual( [] );
	} );

	test( 'changes block settings in the sidebar', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.insertBlock( { name: 'easyq/answer-list' } );
		await editor.openDocumentSettingsSidebar();

		const order = page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'combobox', { name: 'Order' } );
		await expect( order ).toHaveValue( '' );
		await order.selectOption( { label: 'Most votes first' } );

		await expect
			.poll( () =>
				page.evaluate(
					() =>
						window.wp.data
							.select( 'core/block-editor' )
							.getSelectedBlock()?.attributes.order
				)
			)
			.toBe( 'votes' );
	} );

	test( 'offers the options of the core counterparts', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.openDocumentSettingsSidebar();
		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		const selected = ( name ) =>
			page.evaluate(
				( attribute ) =>
					window.wp.data
						.select( 'core/block-editor' )
						.getSelectedBlock()?.attributes[ attribute ],
				name
			);

		// The date format picker of the core Date block.
		await editor.insertBlock( { name: 'easyq/post-date' } );
		await sidebar
			.getByRole( 'checkbox', { name: 'Default format' } )
			.uncheck();
		await expect.poll( () => selected( 'format' ) ).not.toBe( '' );
		await sidebar
			.getByRole( 'checkbox', { name: 'Default format' } )
			.check();
		await expect.poll( () => selected( 'format' ) ).toBe( '' );

		// Link options appear once the title is a link.
		await editor.insertBlock( { name: 'easyq/post-title' } );
		await expect(
			sidebar.getByRole( 'checkbox', { name: 'Open in new tab' } )
		).toHaveCount( 0 );
		await sidebar
			.getByRole( 'checkbox', { name: 'Link to the question' } )
			.check();
		await sidebar
			.getByRole( 'checkbox', { name: 'Open in new tab' } )
			.check();
		await expect.poll( () => selected( 'linkTarget' ) ).toBe( '_blank' );
		await sidebar
			.getByRole( 'textbox', { name: 'Link relation' } )
			.fill( 'nofollow' );
		await expect.poll( () => selected( 'rel' ) ).toBe( 'nofollow' );

		// Fixed headings can be hidden and take another level from the toolbar.
		await editor.insertBlock( { name: 'easyq/related-questions' } );
		await sidebar
			.getByRole( 'checkbox', { name: 'Show heading' } )
			.uncheck();
		await expect.poll( () => selected( 'showTitle' ) ).toBe( false );
		await editor.clickBlockToolbarButton( 'Change level' );
		await page.getByRole( 'menuitemradio', { name: 'Heading 3' } ).click();
		await expect.poll( () => selected( 'level' ) ).toBe( 3 );

		// The search field takes the options of the core Search block, and the preview follows.
		await editor.insertBlock( { name: 'easyq/search' } );
		const search = editor.canvas.locator( '[data-type="easyq/search"]' );
		await expect(
			search.getByRole( 'button', { name: 'Search' } )
		).toBeVisible();
		await sidebar
			.getByRole( 'combobox', { name: 'Button position' } )
			.selectOption( { label: 'No button' } );
		await sidebar.getByRole( 'textbox', { name: 'Label' } ).fill( 'Find' );
		await sidebar.getByRole( 'checkbox', { name: 'Show label' } ).uncheck();
		await expect( search.locator( 'form' ) ).toHaveClass(
			/wp-block-search__no-button/
		);
		await expect( search.getByRole( 'button' ) ).toHaveCount( 0 );
		await expect( search.locator( 'label' ) ).toHaveClass(
			/screen-reader-text/
		);
		await expect( search.locator( 'label' ) ).toHaveText( 'Find' );

		// Rows are chosen with toggles.
		await editor.insertBlock( { name: 'easyq/activity-count' } );
		await sidebar.getByRole( 'checkbox', { name: 'Users' } ).uncheck();
		await expect
			.poll( () => selected( 'rows' ) )
			.toEqual( [ 'questions', 'answers', 'comments' ] );
	} );

	test( 'previews the date in the chosen format', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await page.evaluate( () =>
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks(
				window.wp.blocks.createBlock( 'easyq/question', {}, [
					window.wp.blocks.createBlock( 'easyq/post-date', {
						format: '\\Y\\e\\a\\r Y',
					} ),
				] )
			)
		);

		await expect(
			editor.canvas.locator( '[data-type="easyq/post-date"] time' )
		).toHaveText( /^Year \d{4}$/ );
	} );
} );
