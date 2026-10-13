/**
 * Q&A section of the profile screen and the users list.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'User profile', () => {
	test( 'saves the Q&A profile fields of the current user', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'profile.php' );

		await expect(
			page.getByRole( 'heading', { name: 'Questions and answers' } )
		).toBeVisible();

		const location = page.getByLabel( 'location', { exact: true } );
		await location.fill( 'Hamburg' );
		await page.getByRole( 'button', { name: 'Update Profile' } ).click();

		await expect( page.locator( '#message' ) ).toContainText(
			'Profile updated.'
		);
		await expect(
			page.getByLabel( 'location', { exact: true } )
		).toHaveValue( 'Hamburg' );
	} );

	test( 'shows Q&A level and points in the users list', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'users.php' );

		await expect(
			page.getByRole( 'columnheader', { name: 'Q&A level' } ).first()
		).toBeVisible();
		await expect(
			page.getByRole( 'columnheader', { name: 'Points' } ).first()
		).toBeVisible();
		await expect(
			page.getByRole( 'cell', { name: 'Administrator' } ).first()
		).toBeVisible();
	} );
} );
