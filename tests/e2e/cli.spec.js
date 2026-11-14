/**
 * The WP-CLI command `wp easyq recalc`, run with the real WP-CLI of the test environment.
 */
const { spawnSync } = require( 'child_process' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );

/**
 * Runs WP-CLI in the test environment.
 *
 * @param {string[]} args  Arguments after `wp`.
 * @param {string}   input Answer to a question of the command.
 * @return {{status: number, stdout: string, stderr: string}} Exit code and output.
 */
function wp( args, input = '' ) {
	const result = spawnSync(
		'npx',
		[ 'wp-env', 'run', '--config=.wp-env.test.json', 'cli', 'wp', ...args ],
		{ encoding: 'utf8', input }
	);

	return {
		status: result.status,
		stdout: result.stdout,
		stderr: result.stderr,
	};
}

/**
 * Returns a column of a post as WP-CLI reads it, or `gone` for a deleted post.
 *
 * @param {number} id   Post ID.
 * @param {string} name Column.
 * @return {string} Value.
 */
function postColumn( id, name ) {
	return wp( [
		'eval',
		`$post = EasyQ\\Services::posts()->find( ${ id } ); echo null === $post ? 'gone' : $post->${ name };`,
	] ).stdout.trim();
}

test.describe( 'WP-CLI', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
	} );

	test( 'runs the tools named, in their order', async ( {
		requestUtils,
	} ) => {
		const question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: 'A question with a wrong answer count',
				content: 'WP-CLI counts the answers again.',
			},
		} );
		wp( [
			'eval',
			`EasyQ\\Services::posts()->update( ${ question.id }, array( 'answer_count' => 5 ) );`,
		] );
		expect( postColumn( question.id, 'answer_count' ) ).toBe( '5' );

		// In the order of the tools screen, whatever the order given.
		const result = wp( [
			'easyq',
			'recalc',
			'recalc-points',
			'recount-posts',
		] );

		expect( result.status ).toBe( 0 );
		expect( result.stdout ).toContain( 'Success: Ran 2 tools.' );
		expect( result.stdout.match( /^.+: done\.$/gm ) ).toEqual( [
			'Recount posts: done.',
			'Recalculate points: done.',
		] );
		expect( postColumn( question.id, 'answer_count' ) ).toBe( '0' );
	} );

	test( 'runs every tool but the deleting one with --all', () => {
		const result = wp( [ 'easyq', 'recalc', '--all' ] );

		expect( result.status ).toBe( 0 );
		expect( result.stdout.match( /: done\.$/gm ) ).toHaveLength( 5 );
		expect( result.stdout ).not.toContain( 'Delete hidden posts' );
		expect( result.stdout ).toContain( 'Success: Ran 5 tools.' );
	} );

	test( 'refuses unknown tools and a missing choice', () => {
		const unknown = wp( [ 'easyq', 'recalc', 'recount_posts' ] );
		expect( unknown.status ).toBe( 1 );
		expect( unknown.stderr ).toContain(
			'Invalid value specified for positional arg.'
		);

		for ( const args of [ [], [ 'reindex', '--all' ] ] ) {
			const result = wp( [ 'easyq', 'recalc', ...args ] );
			expect( result.status ).toBe( 1 );
			expect( result.stderr ).toContain(
				'Name the tools to run, or use --all.'
			);
		}
	} );

	test( 'asks before deleting hidden posts', async ( { requestUtils } ) => {
		const question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: 'A hidden question for WP-CLI',
				content: 'It is deleted once the command is confirmed.',
			},
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/posts/${ question.id }/actions/hide`,
		} );

		const declined = wp( [ 'easyq', 'recalc', 'delete-hidden' ] );
		expect( declined.stdout ).toContain( 'This cannot be undone. [y/n]' );
		expect( declined.stdout ).not.toContain( 'Success' );
		expect( postColumn( question.id, 'type' ) ).toBe( 'Q_HIDDEN' );

		const confirmed = wp( [ 'easyq', 'recalc', 'delete-hidden', '--yes' ] );
		expect( confirmed.status ).toBe( 0 );
		expect( confirmed.stdout ).toContain( 'Delete hidden posts: done.' );
		expect( postColumn( question.id, 'type' ) ).toBe( 'gone' );
	} );
} );
