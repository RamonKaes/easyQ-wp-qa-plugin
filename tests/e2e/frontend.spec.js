/**
 * Q&A pages in the browser: asking, voting and accessibility.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin, evalPhp } = require( './plugin' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const route = ( name, arg ) =>
	`/?easyq_route=${ name }${ arg ? `&easyq_arg=${ arg }` : '' }`;

test.describe( 'Q&A frontend', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	test( 'asks a question with the form', async ( { page } ) => {
		const title = `Asked in the browser ${ Date.now() }`;

		await page.goto( route( 'ask' ) );
		await page.getByLabel( 'Your question in one sentence' ).fill( title );
		await page
			.getByLabel( 'More information for the question' )
			.fill( 'This question was asked by an end-to-end test.' );
		await page.getByRole( 'button', { name: 'Ask the question' } ).click();

		await expect(
			page.getByRole( 'heading', { level: 1, name: title } )
		).toBeVisible();
		await expect(
			page.getByText( 'This question was asked by an end-to-end test.' )
		).toBeVisible();
	} );

	test( 'votes without reloading the page', async ( {
		browser,
		requestUtils,
	} ) => {
		const question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Question to vote on ${ Date.now() }`,
				content: 'Please vote.',
			},
		} );

		// 1,233 votes before, so that the count after the vote shows the number format of the site.
		evalPhp(
			`global $wpdb; for ( $i = 1; $i <= 1233; $i++ ) { $wpdb->insert( EasyQ\\Database\\Schema::table( 'uservotes' ), array( 'post_id' => ${ question.id }, 'user_id' => 900000 + $i, 'vote' => 1 ) ); } EasyQ\\Services::posts()->recount_votes( ${ question.id } );`
		);

		const username = `voter${ Date.now() }`;
		await requestUtils.createUser( {
			username,
			email: `${ username }@example.com`,
			password: 'password',
			roles: [ 'subscriber' ],
		} );

		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( `${ requestUtils.baseURL }/wp-login.php` );
		// Core focuses and selects the user name field after 200 ms; filling the fields before that can
		// put the password into the user name field.
		await expect( page.locator( '#user_login' ) ).toBeFocused();
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/ );

		await page.goto(
			`${ requestUtils.baseURL }${ route( 'question', question.id ) }`
		);
		const votes = page.locator( '.wp-block-easyq-post-votes' ).first();
		const up = votes.getByRole( 'button', { name: 'Vote up' } );

		await expect( up ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect(
			votes.locator( '[data-wp-text="context.netText"]' )
		).toHaveText( '1,233' );
		await up.click();
		await expect(
			votes.locator( '[data-wp-text="context.netText"]' )
		).toHaveText( '1,234' );
		await expect( up ).toHaveAttribute( 'aria-pressed', 'true' );
		// Screen readers hear the new count, through the live region of core (WCAG 4.1.3).
		await expect( page.locator( '#a11y-speak-polite' ) ).toHaveText(
			/Votes: 1,234\./
		);

		await page.reload();
		await expect(
			page
				.locator( '.wp-block-easyq-post-votes' )
				.first()
				.locator( '[data-wp-text="context.netText"]' )
		).toHaveText( '1,234' );

		await context.close();
	} );

	test( 'takes a vote back as meant when the request fails', async ( {
		browser,
		requestUtils,
	} ) => {
		const question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Question to vote on and back ${ Date.now() }`,
				content: 'Please vote.',
			},
		} );
		const username = `backvoter${ Date.now() }`;
		await requestUtils.createUser( {
			username,
			email: `${ username }@example.com`,
			password: 'password',
			roles: [ 'subscriber' ],
		} );
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( `${ requestUtils.baseURL }/wp-login.php` );
		await expect( page.locator( '#user_login' ) ).toBeFocused();
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/ );
		await page.goto(
			`${ requestUtils.baseURL }${ route( 'question', question.id ) }`
		);
		const up = page
			.locator( '.wp-block-easyq-post-votes' )
			.first()
			.getByRole( 'button', { name: 'Vote up' } );
		await up.click();
		await expect( up ).toHaveAttribute( 'aria-pressed', 'true' );

		// The request of the store fails: the form takes over and must send 0, not the old vote (F-226).
		await page.route( /\/vote/, ( request ) =>
			request.fulfill( { status: 500, body: 'Server error' } )
		);
		// The form is sent once the request has failed: wait for that page, not for the one
		// still shown, which would let the next address cut the form off.
		const sent = page.waitForEvent( 'framenavigated' );
		await up.click();
		await sent;
		await page.waitForLoadState();
		await page.unroute( /\/vote/ );
		await page.goto(
			`${ requestUtils.baseURL }${ route( 'question', question.id ) }`
		);
		await expect(
			page
				.locator( '.wp-block-easyq-post-votes' )
				.first()
				.locator( '[data-wp-text="context.netText"]' )
		).toHaveText( '0' );

		await context.close();
	} );

	test( 'question pages have no accessibility violations', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Accessible question ${ Date.now() }`,
				content: 'Checked with axe.',
				tags: [ 'a11y' ],
			},
		} );

		await page.goto( route( 'question', question.id ) );

		const results = await new AxeBuilder( { page } )
			.include( '.wp-block-easyq-question' )
			.analyze();

		expect( results.violations ).toEqual( [] );
	} );

	test( 'lists questions for visitors', async ( {
		browser,
		requestUtils,
	} ) => {
		const title = `Listed question ${ Date.now() }`;
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: { title, content: 'Visible to everybody.' },
		} );

		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( `${ requestUtils.baseURL }${ route( 'home' ) }` );

		await expect(
			page.getByRole( 'heading', {
				name: 'Recent questions and answers',
			} )
		).toBeVisible();
		await expect( page.getByRole( 'link', { name: title } ) ).toBeVisible();

		await page.goto( `${ requestUtils.baseURL }${ route( 'questions' ) }` );
		await expect(
			page.getByRole( 'heading', {
				name: 'Recent questions',
				exact: true,
			} )
		).toBeVisible();
		await expect( page.getByRole( 'link', { name: title } ) ).toBeVisible();
		await page.getByRole( 'link', { name: 'Most votes' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Highest voted questions' } )
		).toBeVisible();

		await context.close();
	} );
} );
