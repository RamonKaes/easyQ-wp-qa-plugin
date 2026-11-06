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
		const reply = page.getByRole( 'link', { name: 'Reply', exact: true } );
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
		await plain.getByRole( 'link', { name: 'Reply', exact: true } ).click();
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
