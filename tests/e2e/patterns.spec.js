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

	test( 'gives every inserted list an ID of its own', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost();

		// Both sidebar patterns twice, at once: four lists that must page on their own.
		await page.evaluate( async () => {
			const patterns = await window.wp.apiFetch( {
				path: '/wp/v2/block-patterns/patterns',
			} );
			const content = [ 'easyq/recent-questions', 'easyq/top-users' ]
				.map(
					( name ) =>
						patterns.find( ( pattern ) => pattern.name === name )
							.content
				)
				.join( '\n' );
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlocks( [
					...window.wp.blocks.parse( content ),
					...window.wp.blocks.parse( content ),
				] );
		} );

		const ids = () =>
			page.evaluate( () => {
				const { getClientIdsWithDescendants, getBlock } =
					window.wp.data.select( 'core/block-editor' );
				return getClientIdsWithDescendants()
					.map( getBlock )
					.filter( ( block ) =>
						[ 'easyq/question-query', 'easyq/user-query' ].includes(
							block.name
						)
					)
					.map( ( block ) => block.attributes.queryId );
			} );

		await expect
			.poll( async () => ( await ids() ).every( Number.isFinite ) )
			.toBe( true );
		const assigned = await ids();
		expect( assigned ).toHaveLength( 4 );
		expect( new Set( assigned ).size ).toBe( 4 );
	} );
} );
