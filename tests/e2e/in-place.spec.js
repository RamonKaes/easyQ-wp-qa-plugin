/**
 * Answering and loading comments without reloading the question page.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );

const questionUrl = ( id ) => `/?easyq_route=question&easyq_arg=${ id }`;

test.describe( 'Question page in place', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	// The admin asks the questions, and the author of a question gets an Answer button, as in Q2A.
	const openAnswerForm = ( page ) =>
		page.getByRole( 'link', { name: 'Answer', exact: true } ).click();

	const ask = ( requestUtils, title ) =>
		requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: { title: `${ title } ${ Date.now() }`, content: 'Body.' },
		} );

	test( 'publishes an answer without reloading the page', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Answered in place' );
		await page.goto( questionUrl( question.id ) );
		await page.evaluate( () => ( window.easyqNoReload = true ) );

		const text = 'This answer was sent in the background.';
		await openAnswerForm( page );
		await page.getByLabel( 'Your answer' ).fill( text );
		await page.getByRole( 'button', { name: 'Answer' } ).click();

		await expect(
			page.locator( '[data-wp-router-region="easyq/notices"]' )
		).toHaveText( 'Your answer has been published.' );
		// Announced to screen readers, too.
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText(
			'Your answer has been published.'
		);
		await expect(
			page.locator( 'article' ).getByText( text )
		).toBeVisible();
		await expect( page.getByLabel( 'Your answer' ) ).toHaveValue( '' );
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);

		// The buttons of the new answer have the style of the theme, too.
		const background = ( locator ) =>
			locator.evaluate(
				( element ) =>
					window.getComputedStyle( element ).backgroundColor
			);
		const actions = page.locator(
			'.wp-block-easyq-post-actions .wp-block-button__link'
		);
		expect( await background( actions.last() ) ).toBe(
			await background( actions.first() )
		);
	} );

	test( 'the store actions raise no warning of core about synchronous events', async ( {
		page,
		requestUtils,
	} ) => {
		const warnings = [];
		page.on( 'console', ( message ) => {
			if ( message.text().includes( 'withSyncEvent' ) ) {
				warnings.push( message.text() );
			}
		} );
		const question = await ask(
			requestUtils,
			'Clicked through the actions'
		);
		await page.goto( questionUrl( question.id ) );

		// Actions that call preventDefault() are wrapped in withSyncEvent(), as core asks (F-230).
		await openAnswerForm( page );
		await page.getByRole( 'button', { name: 'Add to favorites' } ).click();
		await expect(
			page.getByRole( 'button', { name: 'Remove from favorites' } )
		).toBeVisible();
		expect( warnings ).toEqual( [] );
		await requestUtils.rest( {
			method: 'DELETE',
			path: '/easyq/v1/favorites',
			data: { type: 'question', id: question.id },
		} );
	} );

	test( 'adds a favorite once with a double click and says so', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await ask(
			requestUtils,
			'Favorite with a double click'
		);
		await page.goto( questionUrl( question.id ) );
		let sent = 0;
		await page.route( /favorites/, async ( route ) => {
			sent++;
			await new Promise( ( resolve ) => setTimeout( resolve, 800 ) );
			await route.continue();
		} );

		const button = page.getByRole( 'button', { name: 'Add to favorites' } );
		await button.click();
		await button.click( { force: true } );

		// One request, and the button says what the server holds (F-226).
		await expect(
			page.getByRole( 'button', { name: 'Remove from favorites' } )
		).toBeVisible();
		await page.unroute( /favorites/ );
		expect( sent ).toBe( 1 );
		const held = (
			await requestUtils.rest( { path: '/easyq/v1/favorites' } )
		).some(
			( item ) => item.type === 'question' && item.id === question.id
		);
		expect( held ).toBe( true );
		await requestUtils.rest( {
			method: 'DELETE',
			path: '/easyq/v1/favorites',
			data: { type: 'question', id: question.id },
		} );
	} );

	test( 'does not send an answer again when the answer of the server breaks', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await ask(
			requestUtils,
			'Answered while the server broke'
		);
		await page.goto( questionUrl( question.id ) );
		let posts = 0;
		// The server stores the answer, then the answer to the browser breaks.
		await page.route( '**/*', async ( route ) => {
			const request = route.request();
			if ( request.method() !== 'POST' ) {
				return route.continue();
			}
			posts++;
			if ( request.headers()[ 'x-easyq-in-place' ] === '1' ) {
				await route.fetch();
				return route.fulfill( { status: 502, body: 'Bad gateway' } );
			}
			return route.continue();
		} );

		await openAnswerForm( page );
		await page
			.getByLabel( 'Your answer' )
			.fill( 'This answer is stored once although the server broke.' );
		await page.getByRole( 'button', { name: 'Answer' } ).click();
		await expect
			.poll(
				async () =>
					(
						await requestUtils.rest( {
							path: `/easyq/v1/questions/${ question.id }/answers`,
						} )
					).length
			)
			.toBe( 1 );
		await page.waitForTimeout( 1500 );
		await page.unroute( '**/*' );

		// The store showed the page as the server holds it, and sent nothing again (F-226).
		expect( posts ).toBe( 1 );
		const answers = await requestUtils.rest( {
			path: `/easyq/v1/questions/${ question.id }/answers`,
		} );
		expect( answers ).toHaveLength( 1 );
	} );

	test( 'sends a form once however often its button is pressed', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await ask(
			requestUtils,
			'Answered with a double click'
		);
		await page.goto( questionUrl( question.id ) );

		// Slow the background request down, so that the second click comes while it is on its way.
		let sent = 0;
		await page.route( '**/*', async ( route ) => {
			const request = route.request();
			if (
				request.method() === 'POST' &&
				request.headers()[ 'x-easyq-in-place' ] === '1'
			) {
				sent++;
				await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
			}
			await route.continue();
		} );

		await openAnswerForm( page );
		await page
			.getByLabel( 'Your answer' )
			.fill( 'This answer is sent only once.' );
		const button = page.getByRole( 'button', { name: 'Answer' } );
		await button.click();
		await button.click( { force: true } );

		await expect(
			page.locator( '[data-wp-router-region="easyq/notices"]' )
		).toHaveText( 'Your answer has been published.' );
		await page.unroute( '**/*' );
		expect( sent ).toBe( 1 );
		const answers = await requestUtils.rest( {
			path: `/easyq/v1/questions/${ question.id }/answers`,
		} );
		expect( answers ).toHaveLength( 1 );
	} );

	test( 'shows errors next to the field without reloading the page', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Too short answer' );
		await page.goto( questionUrl( question.id ) );
		await page.evaluate( () => ( window.easyqNoReload = true ) );

		await openAnswerForm( page );
		await page.getByLabel( 'Your answer' ).fill( 'x' );
		await page.getByRole( 'button', { name: 'Answer' } ).click();

		const field = page.getByLabel( 'Your answer' );
		await expect( field ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( field ).toBeFocused();
		await expect( field ).toHaveValue( 'x' );
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);
	} );

	test( 'keeps a refused comment in its form without JavaScript, without storing anything', async ( {
		browser,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Refused comment' );
		const answer = await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/questions/${ question.id }/answers`,
			data: { content: 'An answer to comment on.' },
		} );
		const context = await browser.newContext( {
			javaScriptEnabled: false,
			storageState: requestUtils.storageStatePath,
		} );
		const plain = await context.newPage();
		const address = new URL(
			`${ questionUrl( question.id ) }&easyq_comment=${ answer.id }`,
			requestUtils.baseURL
		).href;
		await plain.goto( address );

		const form = plain.locator( `#easyq-comment-form-${ answer.id }` );
		await form.getByLabel( 'Your comment' ).fill( 'x' );
		await form.getByRole( 'button', { name: 'Add comment' } ).click();

		// The page answers the form itself: the address stays, without a stored notice.
		await expect( plain ).toHaveURL( address );
		const field = plain
			.locator( `#easyq-comment-form-${ answer.id }` )
			.getByLabel( 'Your comment' );
		await expect( field ).toHaveValue( 'x' );
		await expect( field ).toHaveAttribute( 'aria-invalid', 'true' );
		await context.close();
	} );

	test( 'opens the answer form with the Answer button, with and without JavaScript', async ( {
		browser,
		page,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Answer button' );
		await page.goto( questionUrl( question.id ) );
		await page.evaluate( () => ( window.easyqNoReload = true ) );

		const button = page.getByRole( 'link', {
			name: 'Answer',
			exact: true,
		} );
		await expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( page.getByLabel( 'Your answer' ) ).toBeHidden();
		await button.click();

		await expect( page.getByLabel( 'Your answer' ) ).toBeFocused();
		await expect( button ).toBeHidden();
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);

		const context = await browser.newContext( {
			javaScriptEnabled: false,
			storageState: requestUtils.storageStatePath,
		} );
		const plain = await context.newPage();
		await plain.goto(
			`${ requestUtils.baseURL }${ questionUrl( question.id ) }`
		);
		await plain
			.getByRole( 'link', { name: 'Answer', exact: true } )
			.click();
		await expect( plain.getByLabel( 'Your answer' ) ).toBeVisible();
		await context.close();
	} );

	test( 'shows the latest comments and loads the earlier ones, with and without JavaScript', async ( {
		browser,
		page,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Long discussion' );
		// Q2A allows comments on answers, not on questions, by default.
		const answer = await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/questions/${ question.id }/answers`,
			data: { content: 'An answer that gets many comments.' },
		} );
		for ( let i = 1; i <= 11; i++ ) {
			await requestUtils.rest( {
				method: 'POST',
				path: `/easyq/v1/posts/${ answer.id }/comments`,
				data: { content: `Comment number ${ i } of the discussion.` },
			} );
		}

		await page.goto( questionUrl( question.id ) );
		const comments = page.locator(
			`#easyq-comments-${ answer.id } li[id^="easyq-post-"]`
		);
		await expect( comments ).toHaveCount( 5 );
		await expect(
			page.getByText( 'Comment number 11 of the discussion.' )
		).toBeVisible();
		await expect(
			page.getByText( 'Comment number 1 of the discussion.' )
		).toHaveCount( 0 );

		await page.evaluate( () => ( window.easyqNoReload = true ) );
		await page
			.getByRole( 'link', { name: 'Show 6 previous comments' } )
			.click();

		await expect( comments ).toHaveCount( 11 );
		await expect(
			page.getByText( 'Comment number 1 of the discussion.' )
		).toBeVisible();
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);

		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const plain = await context.newPage();
		await plain.goto(
			`${ requestUtils.baseURL }${ questionUrl( question.id ) }`
		);
		await plain
			.getByRole( 'link', { name: 'Show 6 previous comments' } )
			.click();
		await expect(
			plain.locator(
				`#easyq-comments-${ answer.id } li[id^="easyq-post-"]`
			)
		).toHaveCount( 11 );
		await context.close();
	} );

	test( 'opens the comment form with the Reply button of a comment, with and without JavaScript', async ( {
		browser,
		page,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Replied to' );
		const answer = await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/questions/${ question.id }/answers`,
			data: { content: 'An answer with a comment to reply to.' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/posts/${ answer.id }/comments`,
			data: { content: 'A comment that gets a reply.' },
		} );

		await page.goto( questionUrl( question.id ) );
		await page.evaluate( () => ( window.easyqNoReload = true ) );
		const form = page.locator( `#easyq-comment-form-${ answer.id }` );
		const reply = page.getByRole( 'link', { name: /^Reply to / } );
		await expect( form ).not.toHaveAttribute( 'open' );
		await expect( reply ).toHaveAttribute( 'aria-expanded', 'false' );

		await reply.click();
		await expect( form ).toHaveAttribute( 'open' );
		await expect( form.getByLabel( 'Your comment' ) ).toBeFocused();
		await expect( reply ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);

		// Closing the form with its summary keeps the button in step.
		await form.locator( 'summary' ).click();
		await expect( reply ).toHaveAttribute( 'aria-expanded', 'false' );

		const context = await browser.newContext( {
			javaScriptEnabled: false,
			storageState: requestUtils.storageStatePath,
		} );
		const plain = await context.newPage();
		await plain.goto(
			`${ requestUtils.baseURL }${ questionUrl( question.id ) }`
		);
		await plain.getByRole( 'link', { name: /^Reply to / } ).click();
		await expect( plain ).toHaveURL(
			new RegExp( `easyq_comment=${ answer.id }` )
		);
		await expect(
			plain
				.locator( `#easyq-comment-form-${ answer.id }` )
				.getByLabel( 'Your comment' )
		).toBeVisible();
		await context.close();
	} );

	test( 'suggests existing tags while typing', async ( {
		page,
		requestUtils,
	} ) => {
		const tag = `walrus${ Date.now() }`;
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `A question with a rare tag ${ Date.now() }`,
				content: 'Body.',
				tags: [ tag ],
			},
		} );

		await page.goto( '/?easyq_route=ask' );
		const field = page.getByLabel( 'Tags', { exact: true } );
		await expect( field ).toHaveAttribute( 'list', 'easyq-ask-tags-list' );
		await field.pressSequentially( `cooking ${ tag.slice( 0, -2 ) }` );

		// The suggestion completes the word being typed and keeps the tags before it.
		await expect(
			page.locator( '#easyq-ask-tags-list option' )
		).toHaveAttribute( 'value', `cooking ${ tag }` );

		await field.pressSequentially( ' ' );
		await expect(
			page.locator( '#easyq-ask-tags-list option' )
		).toHaveCount( 0 );
	} );
} );
