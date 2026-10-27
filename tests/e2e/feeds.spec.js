/**
 * Feeds and sitemaps over HTTP, as feed readers and search engines see them.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );

test.describe( 'Feeds and sitemaps', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	test( 'serves the recent questions as RSS and links to it', async ( {
		page,
		request,
		requestUtils,
	} ) => {
		const title = `Feed question ${ Date.now() }`;
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: { title, content: 'Shown in the feed.' },
		} );

		const feed = await request.get(
			`${ requestUtils.baseURL }/?feed=easyq&easyq_route=feed&easyq_arg=questions`
		);
		expect( feed.ok() ).toBe( true );
		expect( feed.headers()[ 'content-type' ] ).toContain(
			'application/rss+xml'
		);
		const xml = await feed.text();
		expect( xml ).toContain( '<rss version="2.0"' );
		expect( xml ).toContain( `<title>${ title }</title>` );

		await page.goto( '/?easyq_route=home' );
		await expect(
			page.locator(
				'link[rel="alternate"][type="application/rss+xml"][title="Recent questions"]'
			)
		).toHaveCount( 1 );
	} );

	test( 'answers disabled feeds with not found', async ( {
		request,
		requestUtils,
	} ) => {
		const feed = await request.get(
			`${ requestUtils.baseURL }/?feed=easyq&easyq_route=feed&easyq_arg=hot`
		);

		expect( feed.status() ).toBe( 404 );
	} );

	test( 'adds questions to the sitemaps', async ( {
		request,
		requestUtils,
	} ) => {
		const question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Sitemap question ${ Date.now() }`,
				content: 'Listed in the sitemap.',
			},
		} );

		// The addresses depend on the permalink setting, so they are taken from the index.
		const index = await request.get(
			`${ requestUtils.baseURL }/?sitemap=index`
		);
		const locations = [
			...( await index.text() ).matchAll( /<loc>([^<]+)<\/loc>/g ),
		]
			.map( ( match ) => match[ 1 ].replaceAll( '&amp;', '&' ) )
			.filter(
				( url ) =>
					/sitemap[-=]easyq/.test( url ) &&
					url.includes( 'questions' )
			);
		expect( locations.length ).toBeGreaterThan( 0 );

		const questions = await request.get( locations[ 0 ] );
		expect( questions.ok() ).toBe( true );
		// The new question, in the address form of the current permalink setting.
		expect( await questions.text() ).toContain(
			locations[ 0 ].includes( '?' )
				? `easyq_route=question&amp;easyq_arg=${ question.id }</loc>`
				: `/questions/${ question.id }/`
		);
	} );
} );
