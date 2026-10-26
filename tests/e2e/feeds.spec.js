/**
 * Feeds and sitemaps over HTTP, as feed readers and search engines see them.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Feeds and sitemaps', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await requestUtils.activatePlugin( 'easyq' );
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
		const index = await request.get(
			`${ requestUtils.baseURL }/?sitemap=index`
		);
		expect( await index.text() ).toContain( 'sitemap=easyq' );

		const questions = await request.get(
			`${ requestUtils.baseURL }/?sitemap=easyq&sitemap-subtype=questions&paged=1`
		);
		expect( questions.ok() ).toBe( true );
		expect( await questions.text() ).toContain( 'easyq_route=question' );
	} );
} );
