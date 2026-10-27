/**
 * Block patterns in the editor.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );

test.describe( 'Block patterns', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	test( 'every easyQ pattern consists of valid blocks in the editor', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost();

		const results = await page.evaluate( async () => {
			const patterns = await window.wp.apiFetch( {
				path: '/wp/v2/block-patterns/patterns',
			} );
			const invalid = ( blocks ) =>
				blocks.flatMap( ( block ) => [
					...( block.isValid ? [] : [ block.name ] ),
					...invalid( block.innerBlocks ),
				] );

			return patterns
				.filter( ( pattern ) => pattern.name.startsWith( 'easyq/' ) )
				.map( ( pattern ) => ( {
					name: pattern.name,
					invalid: invalid(
						window.wp.blocks.parse( pattern.content )
					),
				} ) );
		} );

		expect( results.map( ( result ) => result.name ).sort() ).toEqual( [
			'easyq/home',
			'easyq/profile',
			'easyq/question-page',
			'easyq/recent-questions',
			'easyq/top-users',
		] );
		for ( const result of results ) {
			expect( result.invalid, result.name ).toEqual( [] );
		}
	} );
} );
