/**
 * The Q&A pages in the block themes and classic themes that ship with WordPress:
 * content, accessibility of the easyQ blocks and a screenshot per page for review.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
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

test.describe( 'Themes', () => {
	let question;

	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await requestUtils.activatePlugin( 'easyq' );

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
	} );

	test.afterAll( async ( { requestUtils } ) => {
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
} );
