<?php
/**
 * Tests for the user list and the account endpoints.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Settings\Options;
use EasyQ\Users\Avatars;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \EasyQ\REST\Users_Controller
 * @covers \EasyQ\Users\Avatars
 */
final class Users_REST_Test extends REST_Test_Case {

	/**
	 * Sends a profile update with an uploaded file.
	 *
	 * @param string               $source Image to upload.
	 * @param array<string, mixed> $params Further parameters.
	 * @param string               $type   Media type sent by the browser.
	 * @return WP_REST_Response
	 */
	private function upload( string $source, array $params = array(), string $type = '' ): WP_REST_Response {
		$tmp = wp_tempnam( 'avatar' );
		copy( $source, $tmp );

		$request = new WP_REST_Request( 'POST', '/easyq/v1/users/me' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_file_params(
			array(
				'avatar' => array(
					'name'     => basename( $source ),
					'type'     => '' === $type ? (string) wp_check_filetype( $source )['type'] : $type,
					'tmp_name' => $tmp,
					'size'     => (int) filesize( $tmp ),
					'error'    => UPLOAD_ERR_OK,
				),
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	public function test_lists_users_by_points(): void {
		$author = $this->user();
		$this->ask( $author );

		$response = $this->request( 'GET', '/users' );
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $author->user_id ), array_column( $data, 'id' ) );
		$this->assertSame( 120, $data[0]['points'] );
		$this->assertArrayHasKey( 'link', $data[0] );
		$this->assertArrayNotHasKey( 'fields', $data[0] );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	public function test_newest_users_need_permission(): void {
		$this->assertSame( 401, $this->request( 'GET', '/users', array( 'sort' => 'newest' ) )->get_status() );

		$this->login( 'author' );
		$this->assertSame( 403, $this->request( 'GET', '/users', array( 'sort' => 'newest' ) )->get_status() );

		$this->login( 'editor' );
		$this->assertSame( 200, $this->request( 'GET', '/users', array( 'sort' => 'newest' ) )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/users', array( 'sort' => 'special' ) )->get_status() );
	}

	public function test_returns_the_own_profile_with_all_fields(): void {
		$this->assertSame( 401, $this->request( 'GET', '/users/me' )->get_status() );

		$user = $this->login();
		$data = $this->data( $this->request( 'GET', '/users/me' ) );

		$this->assertSame( $user, $data['id'] );
		$this->assertSame( array( 'location' ), array_column( $data['fields'], 'name' ) );
	}

	public function test_updates_profile_fields(): void {
		$user = $this->login();

		$response = $this->request( 'POST', '/users/me', array( 'fields' => array( 'location' => ' <b>Rome</b> ' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Rome', $this->data( $response )['fields'][0]['value'] );
		$this->assertContains( 'u_save', $this->event_names() );
		$this->assertSame( $user, $this->event_params( 'u_save' )['user_id'] );
	}

	public function test_visitors_and_blocked_users_cannot_update_profiles(): void {
		$this->assertSame( 401, $this->request( 'POST', '/users/me', array( 'fields' => array( 'location' => 'Rome' ) ) )->get_status() );

		$user = $this->login();
		\EasyQ\Users\User_Status::block( Actor::system(), $user );

		$this->assertSame( 403, $this->request( 'POST', '/users/me', array( 'fields' => array( 'location' => 'Rome' ) ) )->get_status() );
	}

	public function test_uploads_replaces_and_removes_the_avatar(): void {
		$user = $this->login();

		$this->assertSame( 200, $this->upload( DIR_TESTDATA . '/images/canola.jpg' )->get_status() );
		$first = Avatars::attachment_id( $user );
		$this->assertNotNull( $first );
		$this->assertStringContainsString( 'canola', (string) get_avatar_url( $user ) );
		$this->assertSame( (string) $user, get_post_meta( $first, Avatars::ATTACHMENT_META, true ) );
		$size = wp_getimagesize( (string) get_attached_file( $first ) );
		$this->assertIsArray( $size );
		$this->assertSame( array( 400, 400 ), array( $size[0], $size[1] ) );

		$this->upload( DIR_TESTDATA . '/images/test-image.png' );
		$second = Avatars::attachment_id( $user );
		$this->assertNotSame( $first, $second );
		$this->assertNull( get_post( $first ) );

		$this->request( 'POST', '/users/me', array( 'remove_avatar' => true ) );
		$this->assertNull( Avatars::attachment_id( $user ) );
		$this->assertNull( get_post( (int) $second ) );
	}

	public function test_refuses_files_that_are_not_images(): void {
		$this->login();

		$response = $this->upload( DIR_TESTDATA . '/uploads/dashicons.woff', array(), 'image/png' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'avatar', $response->as_error()?->get_error_data()['fields'] ?? array() );
	}

	public function test_uploads_can_be_disabled_and_are_rate_limited(): void {
		$this->login();
		Options::update( 'users', array( 'avatar_allow_upload' => false ) );
		$this->assertSame( 403, $this->upload( DIR_TESTDATA . '/images/canola.jpg' )->get_status() );

		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		Options::update(
			'spam',
			array(
				'max_rate_user_uploads' => 1,
				'max_rate_ip_uploads'   => 1,
			)
		);
		$this->assertSame( 200, $this->upload( DIR_TESTDATA . '/images/canola.jpg' )->get_status() );
		$this->assertSame( 429, $this->upload( DIR_TESTDATA . '/images/canola.jpg' )->get_status() );
	}
}
