/**
 * HTML of visitors on the question page: directives of the Interactivity API in it never
 * act on the store of easyQ, and the actions of the store survive a failed request.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );

const questionUrl = ( id ) => `/?easyq_route=question&easyq_arg=${ id }`;

test.describe( 'HTML of visitors', () => {
	let question;

	test.beforeAll( async ( { requestUtils } ) => {
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { easyq_settings_permissions: { permit_post_a: 150 } },
		} );
		// Another test may leave categories behind, and then a question needs one.
		const categories = await requestUtils.rest( {
			path: '/easyq/v1/categories',
			params: { per_page: 1 },
		} );
		question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				...( categories.length
					? { category: categories[ 0 ].id }
					: {} ),
				title: `Question with an answer of a visitor ${ Date.now() }`,
				content: 'A question a visitor answers with HTML.',
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { easyq_settings_permissions: { permit_post_a: 120 } },
		} );
	} );

	test( 'directives in the answer of a visitor never act for the reader', async ( {
		browser,
		page,
		requestUtils,
	} ) => {
		const visitor = await browser.newContext( {
			baseURL: requestUtils.baseURL,
			storageState: { cookies: [], origins: [] },
		} );
		const answer = async ( content ) => {
			const response = await visitor.request.post(
				`/?rest_route=/easyq/v1/questions/${ question.id }/answers`,
				{
					data: {
						content,
						format: 'html',
						name: 'Visitor',
						email: `visitor${ Date.now() }@example.org`,
					},
				}
			);
			expect( response.status() ).toBe( 201 );
			return response.json();
		};

		const favorite = JSON.stringify( {
			favorite: false,
			favoriteType: 'question',
			favoriteId: question.id,
			labels: { add: 'Add', remove: 'Remove' },
		} );
		// Each form of a directive the browser sees: a click, a submit, one that runs by
		// itself, upper case, an asynchronous one, and its own interactive region.
		await answer(
			`<p data-wp-interactive="easyq" data-wp-context='${ favorite }' data-wp-init="actions.favorite">Injected init</p>` +
				`<form data-wp-interactive='{"namespace":"easyq"}' data-wp-context='${ favorite }' data-wp-on--submit="actions.favorite"><button type="submit">Injected submit</button></form>` +
				`<p><a href="#injected" DATA-WP-ON--CLICK="actions.favorite" data-wp-on-async--click="actions.favorite">Injected click</a></p>` +
				`<p data-wp-text="state.nonce" data-wp-bind--hidden="true">Injected text</p>`
		);
		await visitor.close();

		const requests = [];
		page.on( 'request', ( request ) => {
			if ( /favorites|\/vote/.test( request.url() ) ) {
				requests.push( request.url() );
			}
		} );
		await page.goto( questionUrl( question.id ) );
		const article = page.locator( 'article', {
			hasText: 'Injected init',
		} );
		await expect( article.getByText( 'Injected text' ) ).toBeVisible();

		// No attribute of the Interactivity API is left in what the visitor wrote.
		expect(
			await article
				.getByText( 'Injected init' )
				.evaluate( ( element ) =>
					[
						...element.parentElement.querySelectorAll( '*' ),
					].flatMap( ( child ) =>
						[ ...child.attributes ]
							.map( ( attribute ) => attribute.name )
							.filter( ( name ) => name.startsWith( 'data-wp-' ) )
					)
				)
		).toEqual( [] );

		await article
			.getByRole( 'button', { name: 'Injected submit' } )
			.click();
		await article.getByRole( 'link', { name: 'Injected click' } ).click();
		// The text the visitor wrote shows as written, not the nonce of the reader.
		await expect( article.getByText( 'Injected text' ) ).toBeVisible();
		await page.waitForTimeout( 500 );
		expect( requests ).toEqual( [] );

		const favorites = await requestUtils.rest( {
			path: '/easyq/v1/favorites',
		} );
		expect(
			favorites.filter(
				( item ) => item.type === 'question' && item.id === question.id
			)
		).toEqual( [] );
	} );

	test( 'directives in HTML of an author next to an easyQ block never act for the reader', async ( {
		page,
		requestUtils,
	} ) => {
		const favorite = JSON.stringify( {
			favorite: false,
			favoriteType: 'question',
			favoriteId: question.id,
			labels: { add: 'Add', remove: 'Remove' },
		} );
		// HTML core keeps for an author: directives with the namespace of easyQ, no form.
		const post = await requestUtils.createPost( {
			title: `Post with an easyQ block ${ Date.now() }`,
			status: 'publish',
			content:
				`<!-- wp:easyq/question {"questionId":${ question.id }} --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question -->` +
				`<!-- wp:html --><div data-wp-interactive="easyq" data-wp-context='${ favorite }' data-wp-init="actions.favorite"><button type="button" data-wp-on--click="actions.favorite" data-wp-on--mouseenter="actions.vote">Forged button</button></div><!-- /wp:html -->`,
		} );
		const requests = [];
		page.on( 'request', ( request ) => {
			if ( /favorites|\/vote/.test( request.url() ) ) {
				requests.push( request.url() );
			}
		} );
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await page.goto( post.link );
		const forged = page.getByRole( 'button', { name: 'Forged button' } );
		await forged.hover();
		await forged.click();
		await page.waitForTimeout( 500 );
		expect( requests ).toEqual( [] );
		expect( errors ).toEqual( [] );
		expect(
			(
				await requestUtils.rest( { path: '/easyq/v1/favorites' } )
			).filter(
				( item ) => item.type === 'question' && item.id === question.id
			)
		).toEqual( [] );
	} );

	for ( const failure of [ 'offline', 403, 429 ] ) {
		test( `a favorite that fails (${ failure }) shows what the server holds`, async ( {
			page,
			requestUtils,
		} ) => {
			await page.goto( questionUrl( question.id ) );
			await page.route( /favorites/, ( route ) =>
				'offline' === failure
					? route.abort( 'internetdisconnected' )
					: route.fulfill( {
							status: failure,
							contentType: 'application/json',
							body: JSON.stringify( {
								code: 'refused',
								message: 'Refused.',
								data: { status: failure },
							} ),
						} )
			);
			const errors = [];
			page.on( 'pageerror', ( error ) => errors.push( error.message ) );

			await page
				.getByRole( 'button', { name: 'Add to favorites' } )
				.click();
			await page.waitForLoadState();
			await page.waitForTimeout( 1000 );

			// The button says what the server holds: refused in place, the form asks the
			// server itself, as without JavaScript.
			const held = (
				await requestUtils.rest( { path: '/easyq/v1/favorites' } )
			).some(
				( item ) => item.type === 'question' && item.id === question.id
			);
			await expect(
				page.getByRole( 'button', { name: 'Remove from favorites' } )
			).toHaveCount( held ? 1 : 0 );
			expect( held ).toBe( true );
			expect( errors ).toEqual( [] );

			await requestUtils.rest( {
				method: 'DELETE',
				path: '/easyq/v1/favorites',
				data: { type: 'question', id: question.id },
			} );
		} );
	}
} );
