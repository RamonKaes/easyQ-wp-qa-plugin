/**
 * REST API over HTTP, with cookie authentication and as a visitor.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'REST API', () => {
	test( 'creates a question as a logged-in user and lists it publicly', async ( {
		requestUtils,
		request,
	} ) => {
		const title = `End-to-end question ${ Date.now() }`;
		const created = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: { title, content: 'Created through the REST API.' },
		} );

		expect( created.title.rendered ).toBe( title );
		expect( created.can.edit ).toBe( true );

		const response = await request.get(
			`${ requestUtils.baseURL }/?rest_route=/easyq/v1/questions/${ created.id }`
		);
		expect( response.ok() ).toBe( true );

		const question = await response.json();
		expect( question.title.rendered ).toBe( title );
		expect( question.can.edit ).toBe( false );
	} );

	test( 'refuses questions from visitors', async ( {
		requestUtils,
		request,
	} ) => {
		const response = await request.post(
			`${ requestUtils.baseURL }/?rest_route=/easyq/v1/questions`,
			{ data: { title: 'A question from a visitor', content: 'x' } }
		);

		expect( response.status() ).toBe( 401 );
		expect( ( await response.json() ).code ).toBe( 'easyq_login' );
	} );
} );
