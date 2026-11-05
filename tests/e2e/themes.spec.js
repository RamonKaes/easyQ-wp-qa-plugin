/**
 * The Q&A pages in the block themes and classic themes that ship with WordPress:
 * content, accessibility of the easyQ blocks and a screenshot per page for review.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const THEMES = [
	'twentytwentyfive',
	'twentytwentyfour',
	'twentytwentythree',
	'twentytwentytwo',
	// Classic themes: the block templates are printed between their header and footer.
	'twentytwentyone',
	'twentytwenty',
];

const route = ( name, arg ) =>
	`/?easyq_route=${ name }${ arg ? `&easyq_arg=${ arg }` : '' }`;

// Words that no line can hold: the pages have to break them, not scroll sideways.
const LONG_WORD =
	'Donaudampfschifffahrtsgesellschaftskapitaensmuetzenabzeichen';

test.describe( 'Themes', () => {
	let question;
	let longQuestion;
	let longUser;

	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );

		question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `How do themes show questions? ${ Date.now() }`,
				content: 'A question to check every theme.',
				tags: [ 'themes' ],
			},
		} );
		const answer = await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/questions/${ question.id }/answers`,
			data: { content: 'Every theme shows it with its own styles.' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/posts/${ answer.id }/comments`,
			data: { content: 'And without any plugin CSS at all.' },
		} );

		longQuestion = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `${ LONG_WORD } ${ Date.now() }`,
				content: `https://example.org/${ LONG_WORD }/${ LONG_WORD } ${ LONG_WORD }`,
				// Longer tags reach past the edge in Twenty Twenty-Five: its theme.json keeps
				// `.wp-block-post-terms a` on one line, for core's own terms block as well.
				tags: [ LONG_WORD.slice( 0, 40 ).toLowerCase() ],
			},
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/questions/${ longQuestion.id }/answers`,
			data: {
				content: `https://example.org/${ LONG_WORD }/${ LONG_WORD }`,
			},
		} );
		longUser = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/users',
			data: {
				username: `long${ Date.now() }`,
				name: LONG_WORD,
				email: `long${ Date.now() }@example.org`,
				password: 'a-test-password',
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/users/${ longUser.id }`,
			params: { force: true, reassign: 1 },
		} );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	for ( const theme of THEMES ) {
		test( `shows the Q&A pages in ${ theme }`, async ( {
			page,
			requestUtils,
		} ) => {
			await requestUtils.activateTheme( theme );

			const pages = [
				{
					url: route( 'home' ),
					check: () =>
						expect(
							page.getByRole( 'link', {
								name: question.title.rendered,
							} )
						).toBeVisible(),
				},
				{
					url: route( 'questions' ),
					check: () =>
						expect(
							page.getByRole( 'link', {
								name: question.title.rendered,
							} )
						).toBeVisible(),
				},
				{
					url: route( 'question', question.id ),
					check: async () => {
						await expect(
							page.getByRole( 'heading', {
								level: 1,
								name: question.title.rendered,
							} )
						).toBeVisible();
						await expect(
							page.getByText(
								'And without any plugin CSS at all.'
							)
						).toBeVisible();
						await expect(
							page.getByLabel( 'Your answer' )
						).toBeVisible();
					},
				},
				{
					url: route( 'ask' ),
					check: () =>
						expect(
							page.getByRole( 'button', {
								name: 'Ask the question',
							} )
						).toBeVisible(),
				},
				{
					url: route( 'users' ),
					check: () =>
						expect(
							page.locator( '.wp-block-easyq-user-template' )
						).toBeVisible(),
				},
				{
					url: route( 'user', 'admin' ),
					check: () =>
						expect(
							page.getByRole( 'navigation', {
								name: /^Profile of/,
							} )
						).toBeVisible(),
				},
				{
					url: route( 'account' ),
					check: () =>
						expect(
							page.getByRole( 'button', { name: 'Save profile' } )
						).toBeVisible(),
				},
			];

			for ( const { url, check } of pages ) {
				const response = await page.goto( url );
				expect( response?.status(), url ).toBe( 200 );
				await check();

				// The theme's header and footer are there, in block and classic themes alike.
				await expect( page.locator( 'header' ).first() ).toBeVisible();
				await expect( page.locator( 'footer' ).first() ).toBeVisible();

				const results = await new AxeBuilder( { page } )
					.include( '[class*="wp-block-easyq-"]' )
					.analyze();
				expect( results.violations, `${ theme } ${ url }` ).toEqual(
					[]
				);

				await page.screenshot( {
					path: test
						.info()
						.outputPath(
							`${ theme }-${ url.replace( /[^a-z0-9]+/gi, '-' ) }.png`
						),
					fullPage: true,
				} );
			}
		} );

		// WCAG 1.4.10 Reflow: 320 CSS pixels wide without scrolling sideways.
		test( `reflows the Q&A pages at 320 pixels in ${ theme }`, async ( {
			page,
			requestUtils,
		} ) => {
			await requestUtils.activateTheme( theme );
			await page.setViewportSize( { width: 320, height: 800 } );

			const urls = [
				route( 'home' ),
				route( 'questions' ),
				route( 'question', longQuestion.id ),
				`${ route( 'question', longQuestion.id ) }&easyq_edit=${ longQuestion.id }`,
				`${ route( 'question', longQuestion.id ) }&easyq_close=${ longQuestion.id }`,
				route( 'search' ) + `&q=${ LONG_WORD }`,
				route( 'ask' ),
				route( 'categories' ),
				route( 'tags' ),
				route( 'activity' ),
				route( 'users' ),
				route( 'user', longUser.slug ),
				route( 'account' ),
			];

			for ( const url of urls ) {
				const response = await page.goto( url );
				expect( response?.status(), url ).toBe( 200 );

				// What sticks out, for the message; the measurement is the scroll width.
				const overflow = await page.evaluate( () => ( {
					scrollWidth: document.documentElement.scrollWidth,
					elements: [ ...document.querySelectorAll( 'body *' ) ]
						.filter(
							( element ) =>
								! element.children.length &&
								! element.closest( '#wpadminbar' ) &&
								element.getBoundingClientRect().right >
									document.documentElement.clientWidth + 1
						)
						.slice( 0, 12 )
						.map(
							( element ) =>
								`${ element.parentElement.closest( '[class*="wp-block-"]' )?.className.split( ' ' )[ 0 ] } > ${ element.tagName }`
						),
				} ) );
				expect(
					overflow.scrollWidth,
					`${ theme } ${ url } ${ overflow.elements.join( ' | ' ) }`
				).toBeLessThanOrEqual( 320 );

				await page.screenshot( {
					path: test
						.info()
						.outputPath(
							`320-${ theme }-${ url.replace( /[^a-z0-9]+/gi, '-' ) }.png`
						),
					fullPage: true,
				} );
			}
		} );
	}
} );
