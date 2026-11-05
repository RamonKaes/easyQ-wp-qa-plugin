<?php
/**
 * Tests for notices.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Notices\Notices;
use EasyQ\Privacy\Privacy;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Notices\Notices
 * @covers \EasyQ\REST\Notices_Controller
 */
final class Notices_Test extends REST_Test_Case {

	public function tear_down(): void {
		unset( $_COOKIE[ Notices::COOKIE ] );
		parent::tear_down();
	}

	public function test_visitors_see_their_notice_until_dismissed(): void {
		wp_set_current_user( 0 );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/notices' ) ), 'Off by default.' );

		Options::update(
			'users',
			array(
				'show_notice_visitor' => true,
				'notice_visitor'      => 'Hello <strong>visitor</strong><script>bad()</script>',
			)
		);
		$notices = $this->data( $this->request( 'GET', '/notices' ) );
		$this->assertSame( 'visitor', $notices[0]['id'] );
		$this->assertStringContainsString( '<strong>visitor</strong>', $notices[0]['content'] );
		$this->assertStringNotContainsString( '<script>', $notices[0]['content'] );
		$this->assertStringContainsString( 'Hello', do_blocks( '<!-- wp:easyq/notices /-->' ) );

		$this->assertSame( 200, $this->request( 'DELETE', '/notices/visitor' )->get_status() );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/notices' ) ) );
		$this->assertStringContainsString( ' hidden></div>', do_blocks( '<!-- wp:easyq/notices /-->' ) );
	}

	public function test_new_users_see_the_welcome_notice_until_dismissed(): void {
		Options::update(
			'users',
			array(
				'show_notice_welcome' => true,
				'notice_welcome'      => 'Welcome here',
			)
		);
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$this->assertSame( 'welcome', $this->data( $this->request( 'GET', '/notices' ) )[0]['id'] );
		$this->assertSame( 200, $this->request( 'DELETE', '/notices/welcome' )->get_status() );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/notices' ) ) );
		$this->assertSame( '', Site_Meta::get( $user_id, Notices::WELCOME_META ) );
		$this->assertSame( 404, $this->request( 'DELETE', '/notices/welcome' )->get_status() );
	}

	public function test_only_owners_see_and_dismiss_user_notices(): void {
		$owner = $this->user();
		$id    = Services::notices()->add( (int) $owner->user_id, "Plain <b>text</b>\nsecond line" );

		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/notices' ) ) );
		$this->assertSame( 404, $this->request( 'DELETE', '/notices/' . $id )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 404, $this->request( 'DELETE', '/notices/' . $id )->get_status() );

		wp_set_current_user( (int) $owner->user_id );
		$notice = $this->data( $this->request( 'GET', '/notices' ) )[0];
		$this->assertSame( (string) $id, $notice['id'] );
		$this->assertStringContainsString( '&lt;b&gt;', $notice['content'] );
		$this->assertStringContainsString( '<br', $notice['content'] );
		$this->assertStringContainsString( 'Dismiss this notice', do_blocks( '<!-- wp:easyq/notices /-->' ) );

		$this->assertSame( 200, $this->request( 'DELETE', '/notices/' . $id )->get_status() );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/notices' ) ) );
	}

	public function test_html_notices_are_filtered(): void {
		$owner = $this->user();
		Services::notices()->add( (int) $owner->user_id, '<em>Fine</em><script>bad()</script>', 'html' );

		$content = Services::notices()->for_actor( new Actor( (int) $owner->user_id, null ) )[0]['content'];
		$this->assertStringContainsString( '<em>Fine</em>', $content );
		$this->assertStringNotContainsString( '<script>', $content );
	}

	public function test_settings_keep_the_html_of_posts_only(): void {
		wp_set_current_user( $this->administrator() );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_param( 'easyq_settings_users', array( 'notice_visitor' => '<a href="#x">Link</a><script>bad()</script>' ) );
		rest_get_server()->dispatch( $request );

		$this->assertSame( '<a href="#x">Link</a>bad()', Options::get_string( 'users', 'notice_visitor' ) );
	}

	public function test_privacy_tools_export_and_erase(): void {
		$owner = $this->user();
		Services::notices()->add( (int) $owner->user_id, 'A personal notice' );
		$other = $this->user();
		Services::notices()->add( (int) $other->user_id, 'Notice of someone else' );
		$email = get_userdata( (int) $owner->user_id )->user_email;

		$export = Privacy::export_notices( $email );
		$this->assertStringContainsString( 'A personal notice', (string) wp_json_encode( $export['data'] ) );
		$this->assertTrue( Privacy::erase_notices( $email )['items_removed'] );
		$this->assertSame( array(), Privacy::export_notices( $email )['data'] );
		$this->assertCount( 1, Services::notices()->for_actor( new Actor( (int) $other->user_id, null ) ) );
	}
}
