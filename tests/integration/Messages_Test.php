<?php
/**
 * Tests for user walls and private messages.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Templates;
use EasyQ\Messages\Messages;
use EasyQ\Privacy\Privacy;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Messages\Messages
 * @covers \EasyQ\REST\Messages_Controller
 * @covers \EasyQ\Blocks\Render\Messages_Render
 */
final class Messages_Test extends REST_Test_Case {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		parent::tear_down();
	}

	/**
	 * Gives test mails a sender that PHPMailer accepts on localhost.
	 *
	 * @return string
	 */
	public function mail_from(): string {
		return 'wordpress@example.org';
	}

	/**
	 * Renders a page.
	 *
	 * @param string $slug  Template.
	 * @param Route  $route Route.
	 * @return string
	 */
	private function page( string $slug, Route $route ): string {
		Block_Context::router()->set_current( $route );

		return do_blocks( ( new Templates( Block_Context::router() ) )->definitions()[ $slug ]['content'] );
	}

	/**
	 * Returns the subjects of the sent mails.
	 *
	 * @return string[]
	 */
	private function subjects(): array {
		return array_column( tests_retrieve_phpmailer_instance()->mock_sent, 'subject' );
	}

	public function test_posts_lists_and_deletes_on_a_wall(): void {
		$owner  = get_userdata( $this->take_part( (int) $this->user()->user_id ) );
		$author = (int) $this->user()->user_id;
		wp_set_current_user( $author );

		$created = $this->request( 'POST', '/users/' . $owner->ID . '/wall', array( 'content' => "Hello <b>wall</b>\nsecond line" ) );
		$this->assertSame( 201, $created->get_status() );
		$post = $this->data( $created );
		$this->assertSame( 'wall', $post['type'] );
		$this->assertStringContainsString( '&lt;b&gt;', $post['content']['rendered'] );
		$this->assertTrue( $post['can']['delete'], 'Authors delete their post until someone else posts.' );

		// Nobody else may delete it, although nobody posted after it yet.
		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( 403, $this->request( 'DELETE', '/users/' . $owner->ID . '/wall/' . $post['id'] )->get_status() );
		$this->assertFalse( $this->data( $this->request( 'GET', '/users/' . $owner->ID . '/wall' ) )[0]['can']['delete'] );
		wp_set_current_user( $author );
		$this->assertContains( 'u_wall_post', $this->event_names() );
		$this->assertStringContainsString( 'wall', implode( ' ', $this->subjects() ) );

		$listed = $this->data( $this->request( 'GET', '/users/' . $owner->ID . '/wall' ) );
		$this->assertSame( array( $post['id'] ), array_column( $listed, 'id' ) );
		$this->assertStringContainsString( 'Hello', $this->page( 'easyq-user-wall', new Route( 'user', $owner, 'wall' ) ) );

		// After a reply by the owner, the author can no longer delete it.
		wp_set_current_user( $owner->ID );
		$this->request( 'POST', '/users/' . $owner->ID . '/wall', array( 'content' => 'A reply of the owner' ) );
		wp_set_current_user( $author );
		$this->assertSame( 403, $this->request( 'DELETE', '/users/' . $owner->ID . '/wall/' . $post['id'] )->get_status() );

		wp_set_current_user( $owner->ID );
		$this->assertSame( 501, $this->request( 'DELETE', '/users/' . $owner->ID . '/wall/' . $post['id'] )->get_status() );
		$this->assertNotNull( Services::messages()->find( $post['id'] ) );
		$deleted = $this->data( $this->request( 'DELETE', '/users/' . $owner->ID . '/wall/' . $post['id'], array( 'force' => true ) ) );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertNull( Services::messages()->find( $post['id'] ) );
		$this->assertContains( 'u_wall_delete', $this->event_names() );
	}

	public function test_a_wall_route_reaches_only_the_posts_of_its_wall(): void {
		$owner = $this->take_part( (int) $this->user()->user_id );
		$other = $this->take_part( (int) $this->user()->user_id );
		wp_set_current_user( $owner );
		$wall = $this->data( $this->request( 'POST', "/users/$owner/wall", array( 'content' => 'On my own wall' ) ) )['id'];
		$sent = $this->data(
			$this->request(
				'POST',
				'/messages',
				array(
					'to'      => $other,
					'content' => 'A private message',
				)
			)
		)['id'];

		// The address names the wall: a post of another wall, or a private message, is not found there.
		$this->assertSame( 404, $this->request( 'DELETE', "/users/$other/wall/$wall", array( 'force' => true ) )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', "/users/$owner/wall/$sent", array( 'force' => true ) )->get_status() );
		$this->assertNotNull( Services::messages()->find( $wall ) );
		$this->assertNotNull( Services::messages()->find( $sent ) );

		// The same post through the address of its own wall.
		$this->assertSame( 200, $this->request( 'DELETE', "/users/$owner/wall/$wall", array( 'force' => true ) )->get_status() );
		$this->assertNull( Services::messages()->find( $wall ) );
	}

	public function test_walls_follow_settings_permissions_and_wishes(): void {
		$owner = get_userdata( $this->take_part( (int) $this->user()->user_id ) );
		$route = '/users/' . $owner->ID . '/wall';

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', $route, array( 'content' => 'From a visitor' ) )->get_status() );

		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( 400, $this->request( 'POST', $route, array( 'content' => '   ' ) )->get_status() );

		Messages::set_preferences( $owner->ID, null, false );
		$this->assertSame( 403, $this->request( 'POST', $route, array( 'content' => 'Not wanted' ) )->get_status() );
		wp_set_current_user( $owner->ID );
		$this->assertSame( 201, $this->request( 'POST', $route, array( 'content' => 'Owners may still post' ) )->get_status() );

		Options::update( 'users', array( 'allow_user_walls' => false ) );
		$this->assertSame( 404, $this->request( 'GET', $route )->get_status() );
		$this->assertSame( '', do_blocks( '<!-- wp:easyq/user-wall {"userId":' . $owner->ID . '} /-->' ) );
	}

	public function test_sends_and_lists_private_messages(): void {
		$sender    = get_userdata( (int) $this->user()->user_id );
		$recipient = get_userdata( $this->take_part( (int) $this->user()->user_id ) );
		wp_set_current_user( $sender->ID );

		$sent = $this->request(
			'POST',
			'/messages',
			array(
				'to'      => $recipient->ID,
				'content' => 'A private hello',
			)
		);
		$this->assertSame( 201, $sent->get_status() );
		$id = $this->data( $sent )['id'];
		$this->assertContains( 'u_message', $this->event_names() );
		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertNotFalse( $mail );
		$this->assertSame( $recipient->user_email, $mail->to[0][0] );
		$this->assertStringContainsString( 'A private hello', $mail->body );

		$this->assertSame( array( $id ), array_column( $this->data( $this->request( 'GET', '/messages', array( 'box' => 'sent' ) ) ), 'id' ) );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/messages' ) ) );
		$this->assertStringContainsString( 'A private hello', $this->page( 'easyq-message', new Route( 'message', $recipient ) ) );

		wp_set_current_user( $recipient->ID );
		$this->assertSame( array( $id ), array_column( $this->data( $this->request( 'GET', '/messages' ) ), 'id' ) );
		$this->assertSame( array( $id ), array_column( $this->data( $this->request( 'GET', '/messages', array( 'with' => $sender->ID ) ) ), 'id' ) );
		$this->assertStringContainsString( 'A private hello', $this->page( 'easyq-messages', new Route( 'messages' ) ) );

		// Each side deletes it from its own box; it is gone once both did.
		$this->assertSame( 200, $this->request( 'DELETE', '/messages/' . $id )->get_status() );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/messages' ) ) );
		$this->assertNotNull( Services::messages()->find( $id ) );
		wp_set_current_user( $sender->ID );
		$this->request( 'DELETE', '/messages/' . $id );
		$this->assertNull( Services::messages()->find( $id ) );
	}

	public function test_messages_go_only_to_users_whose_profile_is_shown(): void {
		$sender = (int) $this->user()->user_id;
		$hidden = (int) $this->user()->user_id;
		$shown  = $this->take_part( (int) $this->user()->user_id );
		wp_set_current_user( $sender );
		$message = static fn ( int $to ): array => array(
			'to'      => $to,
			'content' => 'Hello there',
		);

		// As the message page of the user answers (`Router`, `message`): the same as for no user, without name or address.
		$refused = $this->request( 'POST', '/messages', $message( $hidden ) );
		$this->assertSame( 404, $refused->get_status() );
		$this->assertSame( $this->data( $this->request( 'POST', '/messages', $message( PHP_INT_MAX ) ) ), $this->data( $refused ) );
		$this->assertStringNotContainsString( get_userdata( $hidden )->display_name, (string) wp_json_encode( $this->data( $refused ) ) );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 0 ) );

		$this->assertSame( 201, $this->request( 'POST', '/messages', $message( $shown ) )->get_status() );

		// Who may list users sees every profile, so they may write to every user.
		wp_set_current_user( $this->administrator() );
		$this->assertSame( 201, $this->request( 'POST', '/messages', $message( $hidden ) )->get_status() );
	}

	public function test_private_messages_are_private(): void {
		$sender    = (int) $this->user()->user_id;
		$recipient = $this->take_part( (int) $this->user()->user_id );
		wp_set_current_user( $sender );
		$id = $this->data(
			$this->request(
				'POST',
				'/messages',
				array(
					'to'      => $recipient,
					'content' => 'Only for the two of us',
				)
			)
		)['id'];

		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( 404, $this->request( 'DELETE', '/messages/' . $id )->get_status() );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/messages', array( 'with' => $sender ) ) ) );
		$this->assertNotNull( Services::messages()->find( $id ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/messages' )->get_status() );
	}

	public function test_messages_follow_settings_and_wishes(): void {
		$sender    = (int) $this->user()->user_id;
		$recipient = $this->take_part( (int) $this->user()->user_id );
		$message   = array(
			'to'      => $recipient,
			'content' => 'Hello there',
		);
		wp_set_current_user( $sender );

		$this->assertSame( 403, $this->request( 'POST', '/messages', array( 'to' => $sender ) + $message )->get_status() );

		Messages::set_preferences( $recipient, false, null );
		$this->assertSame( 403, $this->request( 'POST', '/messages', $message )->get_status() );
		Messages::set_preferences( $recipient, true, null );

		// The longest message is accepted, one character more is not, and nothing is sent then.
		$this->assertSame( 400, $this->request( 'POST', '/messages', array( 'content' => str_repeat( 'ä', Messages::MAX_LENGTH + 1 ) ) + $message )->get_status() );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertSame( 201, $this->request( 'POST', '/messages', array( 'content' => str_repeat( 'ä', Messages::MAX_LENGTH ) ) + $message )->get_status() );
		reset_phpmailer_instance();

		// Without the history, messages are only emailed.
		Options::update( 'users', array( 'show_message_history' => false ) );
		$sent = $this->data( $this->request( 'POST', '/messages', $message ) );
		$this->assertFalse( $sent['stored'] );
		$this->assertSame( 404, $this->request( 'GET', '/messages' )->get_status() );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );

		Options::update( 'users', array( 'allow_private_messages' => false ) );
		$this->assertSame( 403, $this->request( 'POST', '/messages', $message )->get_status() );
	}

	public function test_account_sets_the_wishes(): void {
		$user = (int) $this->user()->user_id;
		wp_set_current_user( $user );

		$this->request(
			'POST',
			'/users/me',
			array(
				'messages' => false,
				'wall'     => false,
			)
		);
		$this->assertFalse( Messages::accepts_messages( $user ) );
		$this->assertFalse( Messages::accepts_wall_posts( $user ) );
		$me = $this->data( $this->request( 'GET', '/users/me' ) );
		$this->assertFalse( $me['messages'] );
		$this->assertFalse( $me['wall'] );
	}

	public function test_privacy_tools_and_deleted_users(): void {
		$user  = get_userdata( $this->take_part( (int) $this->user()->user_id ) );
		$other = get_userdata( (int) $this->user()->user_id );
		wp_set_current_user( $other->ID );
		$this->request( 'POST', '/users/' . $user->ID . '/wall', array( 'content' => 'On the wall of the user' ) );
		$this->request(
			'POST',
			'/messages',
			array(
				'to'      => $user->ID,
				'content' => 'A message to export',
			)
		);
		$unrelated = $this->data( $this->request( 'POST', '/users/' . $other->ID . '/wall', array( 'content' => 'On another wall' ) ) )['id'];

		$export = (string) wp_json_encode( Privacy::export_messages( $user->user_email )['data'] );
		$this->assertStringContainsString( 'On the wall of the user', $export );
		$this->assertStringContainsString( 'A message to export', $export );
		$this->assertStringNotContainsString( 'On another wall', $export );

		$this->assertTrue( Privacy::erase_messages( $user->user_email )['items_removed'] );
		$this->assertSame( array(), Services::messages()->all_of( $user->ID ) );
		$this->assertNotNull( Services::messages()->find( $unrelated ) );

		wp_set_current_user( $user->ID );
		$this->request( 'POST', '/users/' . $other->ID . '/wall', array( 'content' => 'Written before leaving' ) );
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $user->ID );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user->ID );
		}
		$this->assertSame( array(), Services::messages()->all_of( $user->ID ) );
	}

	public function test_moderators_delete_the_wall_posts_of_others(): void {
		$owner  = get_userdata( $this->take_part( (int) $this->user()->user_id ) );
		$author = (int) $this->user()->user_id;
		wp_set_current_user( $author );
		$post = $this->data( $this->request( 'POST', '/users/' . $owner->ID . '/wall', array( 'content' => 'A post a moderator removes' ) ) );

		// Who may hide posts and delete hidden ones deletes any wall post, as in Question2Answer.
		wp_set_current_user( $this->administrator() );
		$this->assertSame( 200, $this->request( 'DELETE', '/users/' . $owner->ID . '/wall/' . $post['id'], array( 'force' => true ) )->get_status() );
		$this->assertNull( Services::messages()->find( $post['id'] ) );
	}

	public function test_the_wall_pages_by_its_query_variable(): void {
		remove_all_filters( 'sanitize_option_' . Options::option_name( 'users' ) );
		Options::update( 'users', array( 'page_size_wall' => 1 ) );
		$owner = get_userdata( $this->take_part( (int) $this->user()->user_id ) );
		$this->assertInstanceOf( \WP_User::class, $owner );
		Services::messages()->post_on_wall( $this->user(), $owner, 'The older post on the wall.' );
		Services::messages()->post_on_wall( $this->user(), $owner, 'The newer post on the wall.' );
		$render = fn (): string => $this->page( 'easyq-user-wall', new Route( 'user', $owner, 'wall' ) );

		$this->assertStringContainsString( 'The newer post', $render() );

		$_GET[ \EasyQ\Blocks\Render\Messages_Render::PAGE_VAR ] = '2';
		$second = $render();
		unset( $_GET[ \EasyQ\Blocks\Render\Messages_Render::PAGE_VAR ] );
		$this->assertStringContainsString( 'The older post', $second );
		$this->assertStringNotContainsString( 'The newer post', $second );
	}

	public function test_an_empty_or_long_message_is_refused_as_core_refuses_invalid_parameters(): void {
		$owner = get_userdata( $this->take_part( (int) $this->user()->user_id ) );
		$this->assertInstanceOf( \WP_User::class, $owner );
		wp_set_current_user( (int) $this->user()->user_id );

		foreach ( array(
			array( '/users/' . $owner->ID . '/wall', array( 'content' => '   ' ) ),
			array(
				'/messages',
				array(
					'to'      => $owner->ID,
					'content' => str_repeat( 'x', 20000 ),
				),
			),
		) as [ $route, $params ] ) {
			$error = $this->request( 'POST', $route, $params )->as_error();
			$this->assertInstanceOf( \WP_Error::class, $error, $route );
			$this->assertSame( 'rest_invalid_param', $error->get_error_code(), $route );
			$this->assertSame( 400, $error->get_error_data()['status'] ?? null, $route );
			$this->assertArrayHasKey( 'content', $error->get_error_data()['params'] ?? array(), $route );
		}
	}
}
