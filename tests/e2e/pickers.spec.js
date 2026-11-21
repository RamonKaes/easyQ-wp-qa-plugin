/**
 * The pickers of the block editor on a larger site: a question, a user and a category
 * beyond the first hundred can be chosen, as in core's pickers.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );

const MORE_THAN_A_PAGE = 105;

test.describe( 'Pickers of the editor on a larger site', () => {
	const stamp = Date.now();
	const created = [];
	let category;

	test.beforeAll( async ( { requestUtils } ) => {
		test.setTimeout( 600_000 );
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );

		for ( let index = 0; index < MORE_THAN_A_PAGE; index++ ) {
			created.push(
				await requestUtils.rest( {
					method: 'POST',
					path: '/easyq/v1/categories',
					data: { title: `Category ${ index } ${ stamp }` },
				} )
			);
		}
		category = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/categories',
			data: { title: `Latest category ${ stamp }` },
		} );
		created.push( category );

		// The first question and user are created first, so they are the last of every list.
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Earliest haystack question ${ stamp }`,
				content: 'Created before a hundred others.',
				category: category.id,
			},
		} );
		await requestUtils.createUser( {
			username: `aaearliest${ stamp }`,
			email: `aaearliest${ stamp }@example.org`,
			first_name: 'Earliest',
			last_name: `Picker${ stamp }`,
			password: `pw-${ stamp }-x`,
			roles: [ 'subscriber' ],
		} );
		for ( let index = 0; index < MORE_THAN_A_PAGE; index++ ) {
			await requestUtils.rest( {
				method: 'POST',
				path: '/easyq/v1/questions',
				data: {
					title: `Haystack question ${ index } ${ stamp }`,
					content: 'One of many.',
					category: category.id,
				},
			} );
		}
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const item of created.reverse() ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/easyq/v1/categories/${ item.id }`,
				params: { force: true },
			} );
		}
	} );

	test( 'finds a question behind a hundred others', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.insertBlock( { name: 'easyq/question' } );
		await editor.openDocumentSettingsSidebar();
		const picker = page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'combobox', { name: 'Question' } );
		await picker.fill( `Earliest haystack question ${ stamp }` );
		await page
			.getByRole( 'option', {
				name: `Earliest haystack question ${ stamp }`,
			} )
			.click();
	} );

	test( 'finds a user behind a hundred others', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.insertBlock( { name: 'easyq/user' } );
		await editor.openDocumentSettingsSidebar();
		const picker = page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'combobox', { name: 'User' } );
		await picker.fill( `aaearliest${ stamp }` );
		await page
			.getByRole( 'option', {
				name: new RegExp( `aaearliest${ stamp }|Picker${ stamp }` ),
			} )
			.click();
	} );

	test( 'shows names as they are written', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// Core stores display names with entities (`pre_user_display_name`).
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/users',
			data: {
				username: `ampersand${ stamp }`,
				email: `ampersand${ stamp }@example.org`,
				name: `Tom & Jerry ${ stamp }`,
				password: `pw-${ stamp }-y`,
				roles: [ 'subscriber' ],
			},
		} );

		await admin.createNewPost();
		await editor.insertBlock( { name: 'easyq/user' } );
		await editor.openDocumentSettingsSidebar();
		await page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'combobox', { name: 'User' } )
			.fill( `Jerry ${ stamp }` );
		await expect(
			page.getByRole( 'option', { name: `Tom & Jerry ${ stamp }` } )
		).toBeVisible();
	} );

	test( 'offers a category behind a hundred others', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.insertBlock( {
			name: 'easyq/question-query',
			attributes: { query: { inherit: false } },
		} );
		await editor.openDocumentSettingsSidebar();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		const select = settings.getByRole( 'combobox', { name: 'Category' } );
		const offered = () =>
			select.evaluate( ( element ) =>
				[ ...element.options ].map( ( option ) => option.textContent )
			);
		// The first categories are offered, so the list has loaded.
		await expect
			.poll( offered, { timeout: 15_000 } )
			.toContain( `Category 0 ${ stamp }` );
		expect( await offered() ).toContain( `Latest category ${ stamp }` );
		expect( category.id ).toBeGreaterThan( 0 );
	} );
} );
