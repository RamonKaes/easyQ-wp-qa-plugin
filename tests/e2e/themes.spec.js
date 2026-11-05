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

// Every theme the Q&A pages are measured in: what ships with WordPress and popular themes.
// Not Twenty Ten: its layout has a fixed width of 980 pixels on every page.
const ALL_THEMES = [
	...THEMES,
	'twentynineteen',
	'twentyseventeen',
	'twentysixteen',
	'twentyfifteen',
	'twentyfourteen',
	'twentythirteen',
	'twentytwelve',
	'twentyeleven',
	'astra',
	'frost',
	'generatepress',
	'hello-elementor',
	'kadence',
	'oceanwp',
	'ollie',
];

// Their own page title keeps long words on a line, as on every page of those themes.
const THEMES_WITH_UNBROKEN_TITLES = [
	'hello-elementor',
	'twentyeleven',
	'twentyfifteen',
	'twentyfourteen',
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
	let corePage;

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

		corePage = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/pages',
			data: {
				title: 'Core button',
				status: 'publish',
				content:
					'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#core">Core</a></div><!-- /wp:button --><!-- wp:button {"tagName":"button","className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><button type="button" class="wp-block-button__link wp-element-button">Core</button></div><!-- /wp:button --></div><!-- /wp:buttons -->',
			},
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
			path: `/wp/v2/pages/${ corePage.id }`,
			params: { force: true },
		} );
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
	}

	for ( const theme of ALL_THEMES ) {
		// The buttons of the plugin look like the core button block in the same theme.
		test( `shows buttons like the core block in ${ theme }`, async ( {
			page,
			requestUtils,
		} ) => {
			await requestUtils.activateTheme( theme );

			const looks = ( selector ) =>
				page
					.locator( selector )
					.first()
					.evaluate( ( element ) => {
						const style = window.getComputedStyle( element );

						return [
							style.color,
							style.backgroundColor,
							style.borderTopWidth,
							style.borderTopColor,
						];
					} );

			for ( const tag of [ 'a', 'button' ] ) {
				await page.goto( `/?page_id=${ corePage.id }` );
				const core = await looks( `${ tag }.wp-block-button__link` );

				await page.goto( route( 'question', question.id ) );
				const ours = await looks(
					`.wp-block-easyq-post-actions ${ tag }.wp-block-button__link`
				);
				// OceanWP gives buttons inside forms a thinner border, and actions are forms.
				if ( 'oceanwp' === theme && 'button' === tag ) {
					ours[ 2 ] = core[ 2 ];
				}
				expect( ours, tag ).toEqual( core );
			}
		} );

		// Details are smaller than what they belong to, by the sizes of the theme.
		test( `shows details smaller than the text in ${ theme }`, async ( {
			page,
			requestUtils,
		} ) => {
			await requestUtils.activateTheme( theme );

			const size = async ( selector ) =>
				parseFloat(
					await page
						.locator( selector )
						.first()
						.evaluate(
							( element ) =>
								window.getComputedStyle( element ).fontSize
						)
				);

			await page.goto( route( 'questions' ) );
			expect(
				await size( '.wp-block-easyq-post-activity' )
			).toBeLessThan( await size( '.wp-block-easyq-post-title' ) );

			await page.goto( route( 'question', question.id ) );
			const text = await size(
				'.wp-block-easyq-question > .wp-block-columns .wp-block-easyq-post-content'
			);
			expect( await size( '.wp-block-easyq-post-author' ) ).toBeLessThan(
				text
			);
			expect(
				await size(
					'.wp-block-easyq-comment-template .wp-block-easyq-post-content'
				)
			).toBeLessThan( text );
		} );

		// WCAG 1.4.10 Reflow: 320 CSS pixels wide without scrolling sideways.
		test( `reflows the Q&A pages at 320 pixels in ${ theme }`, async ( {
			page,
			requestUtils,
		} ) => {
			await requestUtils.activateTheme( theme );
			await page.setViewportSize( { width: 320, height: 800 } );

			const question320 = route( 'question', longQuestion.id );
			const longTitles = [
				question320,
				`${ question320 }&easyq_edit=${ longQuestion.id }`,
				`${ question320 }&easyq_close=${ longQuestion.id }`,
				`${ route( 'search' ) }&q=${ LONG_WORD }`,
				route( 'user', longUser.slug ),
			];
			const urls = [
				route( 'home' ),
				route( 'questions' ),
				route( 'ask' ),
				route( 'categories' ),
				route( 'tags' ),
				route( 'activity' ),
				route( 'users' ),
				route( 'account' ),
				route( 'favorites' ),
				...longTitles,
			];

			for ( const url of urls ) {
				const response = await page.goto( url );
				expect( response?.status(), url ).toBe( 200 );

				// WP_DEBUG is on: PHP messages are printed into the page.
				await expect(
					page.locator( 'body' ),
					`${ theme } ${ url }`
				).not.toContainText(
					/(Notice|Warning|Deprecated|Fatal error): .+ on line \d+/
				);

				// What sticks out of the page, from the blocks of the plugin and at all.
				const overflow = await page.evaluate( () => {
					const width = document.documentElement.clientWidth;
					const outside = ( element ) =>
						! element.children.length &&
						element.getBoundingClientRect().right > width + 1;

					return {
						scrollWidth: document.documentElement.scrollWidth,
						blocks: [
							...document.querySelectorAll(
								'[class*="wp-block-easyq-"] *'
							),
						]
							.filter( outside )
							.slice( 0, 8 )
							.map(
								( element ) =>
									`${ element.parentElement.className } > ${ element.tagName }`
							),
					};
				} );
				expect( overflow.blocks, `${ theme } ${ url }` ).toEqual( [] );
				if (
					! THEMES_WITH_UNBROKEN_TITLES.includes( theme ) ||
					! longTitles.includes( url )
				) {
					expect(
						overflow.scrollWidth,
						`${ theme } ${ url }`
					).toBeLessThanOrEqual( 320 );
				}

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
