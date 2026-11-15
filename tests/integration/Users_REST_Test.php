<?php
/**
 * Tests for the user list and the account endpoints.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Services;
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

	public function test_profiles_are_shown_as_core_shows_users(): void {
		$quiet  = get_userdata( (int) $this->user()->user_id );
		$router = \EasyQ\Blocks\Block_Context::router();

		foreach ( array(
			'visitor'          => 0,
			'other subscriber' => (int) $this->user()->user_id,
		) as $who => $viewer ) {
			wp_set_current_user( $viewer );
			$this->assertSame( 404, $this->request( 'GET', '/users/' . $quiet->ID )->get_status(), $who );
			$this->assertSame( 404, $this->request( 'GET', '/users/' . $quiet->ID . '/wall' )->get_status(), $who );
			$this->assertTrue( 'not_found' === $router->resolve( 'user', $quiet->user_nicename, null )->name, $who );
		}

		foreach ( array(
			'the user'      => $quiet->ID,
			'administrator' => $this->administrator(),
		) as $who => $viewer ) {
			wp_set_current_user( $viewer );
			$this->assertSame( 200, $this->request( 'GET', '/users/' . $quiet->ID )->get_status(), $who );
			$this->assertFalse( 'not_found' === $router->resolve( 'user', $quiet->user_nicename, null )->name, $who );
		}

		$this->take_part( $quiet->ID );
		wp_set_current_user( 0 );
		$this->assertSame( 200, $this->request( 'GET', '/users/' . $quiet->ID )->get_status() );
		$this->assertFalse( 'not_found' === $router->resolve( 'user', $quiet->user_nicename, null )->name );
	}

	public function test_the_registration_date_is_in_the_edit_context_as_in_core(): void {
		$user = $this->take_part( (int) $this->user()->user_id );

		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertArrayNotHasKey( 'registered', $this->data( $this->request( 'GET', '/users/' . $user ) ) );
		$response = $this->request( 'GET', '/users/' . $user, array( 'context' => 'edit' ) );
		$this->assertSame( 'rest_forbidden_context', $response->as_error()?->get_error_code() );

		foreach ( array( $user, $this->administrator() ) as $viewer ) {
			wp_set_current_user( $viewer );
			$this->assertArrayHasKey( 'registered', $this->data( $this->request( 'GET', '/users/' . $user, array( 'context' => 'edit' ) ) ) );
		}
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

	public function test_lists_the_favorite_users_of_the_current_user(): void {
		$followed = $this->user();
		$this->take_part( (int) $followed->user_id );
		$this->user();
		$fan = $this->user();
		$this->assertTrue( Services::favorites()->set( $fan, 'user', (int) $followed->user_id, true ) );

		wp_set_current_user( (int) $fan->user_id );
		$response = $this->request( 'GET', '/users', array( 'sort' => 'favorites' ) );
		$this->assertSame( array( $followed->user_id ), array_column( $this->data( $response ), 'id' ) );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );

		wp_set_current_user( 0 );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/users', array( 'sort' => 'favorites' ) ) ), 'Visitors have no favorites.' );
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
		// Uploads are off by default; the site owner allows them.
		\EasyQ\Settings\Options::update( 'users', array( 'avatar_allow_upload' => true ) );
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
		// Uploads are off by default; the site owner allows them.
		\EasyQ\Settings\Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$this->login();

		$response = $this->upload( DIR_TESTDATA . '/uploads/dashicons.woff', array(), 'image/png' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'avatar', $response->as_error()?->get_error_data()['params'] ?? array() );
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

	public function test_the_avatar_is_the_file_the_image_editor_saved(): void {
		\EasyQ\Settings\Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$this->login();
		$format = static fn (): array => array( 'image/jpeg' => 'image/png' );
		add_filter( 'image_editor_output_format', $format );

		$response = $this->upload( DIR_TESTDATA . '/images/canola.jpg' );
		remove_filter( 'image_editor_output_format', $format );

		$this->assertSame( 200, $response->get_status() );
		$attachment = (int) \EasyQ\Users\Avatars::attachment_id( get_current_user_id() );
		$file       = (string) get_attached_file( $attachment );
		$this->assertSame( 'image/png', get_post_mime_type( $attachment ) );
		$this->assertStringEndsWith( '.png', $file );
		$this->assertFileExists( $file );
		$this->assertFileDoesNotExist( (string) preg_replace( '/\.png$/', '.jpg', $file ), 'The original stays.' );
	}

	public function test_avatars_are_turned_upright_by_the_editor_core_chooses_with_the_avatar_editor_first(): void {
		\EasyQ\Settings\Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$this->login();
		$seen    = array();
		$editors = static function ( array $editors ) use ( &$seen ): array {
			$seen[] = implode( ', ', $editors );

			return $editors;
		};
		$rotated = static function ( $orientation ) use ( &$seen ) {
			$seen[] = 'orientation ' . $orientation;

			return $orientation;
		};
		add_filter( 'wp_image_editors', $editors, 20 );
		add_filter( 'wp_image_maybe_exif_rotate', $rotated );

		$response = $this->upload( DIR_TESTDATA . '/images/test-image-rotated-90ccw.jpg' );
		remove_filter( 'wp_image_editors', $editors, 20 );
		remove_filter( 'wp_image_maybe_exif_rotate', $rotated );

		$this->assertSame( 200, $response->get_status() );
		// The upload chooses with the avatar editor first and turns the image upright; the metadata
		// of the stored avatar, made afterwards with core's editors, finds no EXIF orientation left.
		$this->assertSame(
			array(
				\EasyQ\Users\Avatar_Editor_Imagick::class . ', WP_Image_Editor_Imagick, WP_Image_Editor_GD',
				'orientation 6',
				'WP_Image_Editor_Imagick, WP_Image_Editor_GD',
				'orientation ',
			),
			$seen
		);
		$this->assertFalse( has_filter( 'wp_image_editors', array( \EasyQ\Users\Avatars::class, 'image_editors' ) ), 'Only for the avatar.' );
	}

	public function test_the_avatar_editor_keeps_only_the_colour_profiles(): void {
		$image = new \Imagick();
		$image->newImage( 4, 4, new \ImagickPixel( 'white' ) );
		foreach ( array(
			'exif' => "Exif\0\0camera",
			'xmp'  => '<x:xmpmeta/>',
			'iptc' => 'rights',
			'icm'  => 'colours',
		) as $profile => $data ) {
			$image->setImageProfile( $profile, $data );
		}
		$editor = new \EasyQ\Users\Avatar_Editor_Imagick( 'avatar.png' );
		( new \ReflectionProperty( \WP_Image_Editor_Imagick::class, 'image' ) )->setValue( $editor, $image );

		$this->assertTrue( $editor->strip_profiles() );
		$this->assertSame( array( 'icm' ), array_keys( $image->getImageProfiles( '*', true ) ) );
	}

	public function test_an_upload_that_did_not_arrive_is_refused_with_its_reason(): void {
		// Uploads are off by default; the site owner allows them.
		\EasyQ\Settings\Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$this->login();
		$request = new WP_REST_Request( 'POST', '/easyq/v1/users/me' );
		// As PHP fills the entry when the file is larger than `upload_max_filesize`.
		$request->set_file_params(
			array(
				'avatar' => array(
					'name'     => 'portrait.png',
					'type'     => '',
					'tmp_name' => '',
					'size'     => 0,
					'error'    => UPLOAD_ERR_INI_SIZE,
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'too large', (string) ( $response->as_error()?->get_error_data()['params']['avatar'] ?? '' ) );
	}
}
