<?php
/**
 * Tests for form submissions without JavaScript.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Frontend\Flash;
use EasyQ\Frontend\Form_Handler;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\URLs;
use EasyQ\Mail\Notifications;
use EasyQ\Mail\Unsubscribe;
use EasyQ\Posts\Post;
use EasyQ\Services;

/**
 * @covers \EasyQ\Frontend\Form_Handler
 * @covers \EasyQ\Frontend\Flash
 */
final class Form_Handler_Test extends REST_Test_Case {

	/**
	 * Text a user may type: markup to talk about, comparisons, a percent-encoded address and
	 * backslashes, which `wp_magic_quotes()` slashes in the request.
	 *
	 * @var string
	 */
	private const TYPED = "Is x<y or y>x? Use <div class=\"a\"> and <?php echo 1; ?> at https://example.org/K%C3%B6ln\nC:\\Users\\me 'quoted' 100%";

	public function tear_down(): void {
		$_POST = array();
		unset( $_SERVER['HTTP_REFERER'], $_GET[ Flash::QUERY_VAR ], $_REQUEST['_wp_http_referer'] );
		parent::tear_down();
	}

	/**
	 * Submits a form.
	 *
	 * @param string               $form   Form name.
	 * @param array<string, mixed> $fields Fields.
	 * @param bool                 $nonce  Whether to send a valid nonce.
	 * @param string               $from   Address of the page the form is on; default the ask page.
	 * @return string Redirect address after a success, or an empty string after a failure, when
	 *                the page of the form is shown in the same request.
	 */
	private function submit( string $form, array $fields, bool $nonce = true, string $from = '' ): string {
		$_POST                   = $fields;
		$_POST['easyq_form']     = $form;
		$_POST['_easyq_nonce']   = $nonce ? wp_create_nonce( Form_Handler::nonce_action( $form, (int) ( $fields['easyq_post'] ?? 0 ), (string) ( $fields['easyq_action'] ?? '' ) ) ) : 'invalid';
		$_SERVER['HTTP_REFERER'] = '' === $from ? home_url( '/questions/ask/' ) : $from;
		// Each submission is a request of its own, which starts without a kept message.
		( new \ReflectionProperty( Flash::class, 'now' ) )->setValue( null, null );

		$url = ( new Form_Handler() )->process();
		if ( null === $url ) {
			$this->assertSame( 'error', Flash::current()['type'] ?? '', 'A failure keeps its message for the page.' );
			return '';
		}

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET[ Flash::QUERY_VAR ] = $query[ Flash::QUERY_VAR ] ?? '';

		return $url;
	}

	public function test_ignores_requests_without_form(): void {
		$this->assertNull( ( new Form_Handler() )->process() );
	}

	public function test_a_request_over_the_limit_of_php_says_so_on_a_q_and_a_page(): void {
		// PHP empties $_POST when a request is larger than post_max_size, as Question2Answer checks (`qa_post_limit_exceeded()`).
		$limit                     = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_LENGTH'] = (string) ( $limit + 1 );

		\EasyQ\Blocks\Block_Context::router()->set_current( null );
		$this->assertNull( ( new Form_Handler() )->process() );
		$this->assertNull( Flash::current(), 'Pages of others are left alone.' );

		\EasyQ\Blocks\Block_Context::router()->set_current( new \EasyQ\Frontend\Route( 'account' ) );
		$this->assertNull( ( new Form_Handler() )->process() );
		$this->assertSame( 'error', Flash::current()['type'] ?? '' );
		$this->assertStringContainsString( size_format( wp_max_upload_size() ), Flash::current()['message'] ?? '' );

		// Within the limit, an empty request is no form.
		( new \ReflectionProperty( Flash::class, 'now' ) )->setValue( null, null );
		$_SERVER['CONTENT_LENGTH'] = '10';
		$this->assertNull( ( new Form_Handler() )->process() );
		$this->assertNull( Flash::current() );
		unset( $_SERVER['CONTENT_LENGTH'] );
		\EasyQ\Blocks\Block_Context::router()->set_current( null );
	}

	public function test_refuses_expired_forms(): void {
		$this->login();

		$this->submit( 'ask', array( 'title' => 'A question from an old form' ), false );

		$this->assertSame( 'error', Flash::current()['type'] ?? '' );
	}

	public function test_every_field_of_every_form_reaches_the_rest_api_as_typed(): void {
		$values  = new \ReflectionMethod( Form_Handler::class, 'values' );
		$checked = 0;

		foreach ( (array) ( new \ReflectionClassConstant( Form_Handler::class, 'FIELDS' ) )->getValue() as $form => $fields ) {
			foreach ( (array) $fields as $field ) {
				$_POST = wp_slash( array( $field => self::TYPED ) );
				$this->assertSame( array( $field => self::TYPED ), $values->invoke( new Form_Handler(), $form ), "$form: $field" );
				++$checked;
			}
		}

		$this->assertGreaterThan( 30, $checked );
	}

	public function test_profile_fields_reach_the_rest_api_as_typed(): void {
		$id = Services::user_fields()->create( 'about', 'About', \EasyQ\Users\User_Field::FLAG_MULTI_LINE, null );
		$this->assertIsInt( $id );
		$_POST   = wp_slash( array( Form_Handler::PROFILE_FIELD_PREFIX . 'about' => self::TYPED ) );
		$request = new \WP_REST_Request( 'POST', '/easyq/v1/users/me' );

		( new \ReflectionMethod( Form_Handler::class, 'add_account_input' ) )->invoke( new Form_Handler(), $request );

		$this->assertSame( array( 'about' => self::TYPED ), $request->get_param( 'fields' ) );
	}

	public function test_a_question_from_the_form_is_stored_as_one_from_the_rest_api(): void {
		$this->login();
		$fields = array(
			'title'   => 'A question about x<y and 100% here',
			'content' => self::TYPED,
			'tags'    => 'forms php',
		);
		$this->submit( 'ask', wp_slash( $fields ) );
		$from_form = $this->post( (int) Services::question_query()->find( array() )['posts'][0]->id );
		// Hidden, so that the same question from the REST API is no duplicate (F-144).
		Services::post_service()->set_question_status( \EasyQ\Actor::system(), $from_form, \EasyQ\Posts\Post::STATUS_HIDDEN );
		$from_rest = $this->post( (int) $this->data( $this->request( 'POST', '/questions', $fields ) )['id'] );

		$this->assertSame( self::TYPED, $from_form->content );
		$this->assertSame( $from_rest->content, $from_form->content );
		$this->assertSame( $from_rest->format, $from_form->format );
		$this->assertSame( $from_rest->tag_list(), $from_form->tag_list() );
	}

	public function test_asks_a_question_and_redirects_to_it(): void {
		$this->login();

		$url = $this->submit(
			'ask',
			array(
				'title'   => 'A question from the form',
				'content' => "Line one\nLine two",
				'tags'    => 'forms php',
			)
		);

		$ids = Services::question_query()->find( array() )['posts'];
		$this->assertStringContainsString( (string) $ids[0]->id, $url );
		$this->assertStringContainsString( Flash::QUERY_VAR . '=', $url );
		$this->assertSame( 'success', Flash::current()['type'] ?? '' );
		$this->assertSame( "Line one\nLine two", $ids[0]->content );
		$this->assertSame( array( 'forms', 'php' ), $ids[0]->tag_list() );
	}

	public function test_keeps_values_and_field_errors_after_failures(): void {
		$this->login();

		$this->submit(
			'ask',
			array(
				'title'   => 'Short',
				'content' => 'Some text',
			)
		);

		$this->assertSame( 'Short', Flash::value( 'ask', 'title' ) );
		$this->assertNotSame( '', Flash::error( 'ask', 'title' ) );
		$this->assertSame( '', Flash::error( 'answer', 'title' ) );
	}

	public function test_a_failure_stores_nothing_and_keeps_what_was_typed_in_every_form(): void {
		global $wpdb;
		$count   = static fn (): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts the rows, in a test.
		$before  = $count();
		$checked = 0;

		foreach ( array( 0, (int) $this->user()->user_id ) as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( (array) ( new \ReflectionClassConstant( Form_Handler::class, 'FIELDS' ) )->getValue() as $form => $fields ) {
				$typed = array_fill_keys( (array) $fields, "Typed into $form" );
				// An expired form: refused before anything else.
				$this->assertSame( '', $this->submit( (string) $form, wp_slash( $typed ), false ), "$form" );
				foreach ( $typed as $field => $value ) {
					$this->assertSame( $value, Flash::kept( (string) $form, (string) $field ), "$form: $field" );
				}
				++$checked;
			}
		}

		// Refused by the REST API.
		$this->assertSame( '', $this->submit( 'ask', array( 'title' => 'Short' ) ) );
		$this->assertSame( 'Short', Flash::kept( 'ask', 'title' ) );

		$this->assertGreaterThan( 30, $checked );
		$this->assertSame( $before, $count(), 'A refused request wrote to the options table.' );
	}

	public function test_a_success_stores_nothing_either(): void {
		global $wpdb;
		$count    = static fn (): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts the rows, in a test.
		$question = $this->ask( $this->user() );
		$this->login();
		$before = $count();

		// Toggled favorites: each success used to store its message as a transient (two rows).
		for ( $round = 0; $round < 20; $round++ ) {
			$url = $this->submit(
				'favorite',
				array(
					'type'     => 'question',
					'id'       => (string) $question->id,
					'favorite' => 0 === $round % 2 ? '1' : '0',
				)
			);
			$this->assertNotSame( '', $url, "Round $round" );
			$this->assertSame( array( 'success', 'Your favorites have been saved.' ), array( Flash::current()['type'] ?? null, Flash::current()['message'] ?? null ), "Round $round" );
		}

		$this->assertSame( $before, $count(), 'A successful request wrote to the options table.' );
	}

	public function test_the_nonce_of_a_form_holds_for_its_own_post_and_action_only(): void {
		$this->login( 'editor' );
		$first  = $this->ask( $this->user(), 'A first question to close' );
		$second = $this->ask( $this->user(), 'A second question to close' );
		$fields = array(
			'easyq_post'   => (string) $second->id,
			'easyq_action' => 'close',
			'note'         => 'Closed with the nonce of another question.',
		);

		foreach ( array( Form_Handler::nonce_action( 'action', $first->id, 'close' ), Form_Handler::nonce_action( 'action', $second->id, 'hide' ) ) as $other ) {
			$_POST                 = $fields;
			$_POST['easyq_form']   = 'action';
			$_POST['_easyq_nonce'] = wp_create_nonce( $other );
			( new \ReflectionProperty( Flash::class, 'now' ) )->setValue( null, null );
			$this->assertNull( ( new Form_Handler() )->process(), $other );
			$this->assertFalse( $this->post( $second->id )->is_closed(), $other );
		}

		$this->submit( 'action', $fields );
		$this->assertTrue( $this->post( $second->id )->is_closed() );
	}

	public function test_a_successful_submission_does_not_return_to_other_sites(): void {
		$this->login();
		$question                     = $this->ask( $this->user() );
		$_REQUEST['_wp_http_referer'] = 'https://attacker.example/phish';

		$url = $this->submit(
			'favorite',
			array(
				'type'     => 'question',
				'id'       => (string) $question->id,
				'favorite' => '1',
			),
			true,
			'https://attacker.example/phish'
		);

		$this->assertStringStartsWith( Routes::url( 'home' ), $url );
	}

	public function test_answers_and_votes(): void {
		$question = $this->ask( $this->user() );
		$this->login();

		$url = $this->submit(
			'answer',
			array(
				'easyq_post' => (string) $question->id,
				'content'    => 'An answer from the form.',
			)
		);
		$this->assertStringContainsString( '#easyq-post-', $url );
		$this->assertSame( 1, $this->post( $question->id )->answer_count );

		$this->submit(
			'vote',
			array(
				'easyq_post' => (string) $question->id,
				'vote'       => '1',
			)
		);
		$this->assertSame( 1, $this->post( $question->id )->net_votes );
	}

	public function test_performs_post_actions(): void {
		$question = $this->ask( $this->user() );
		$this->login( 'editor' );
		$form_page = add_query_arg( 'easyq_close', $question->id, URLs::question( $question ) );

		// A question that does not exist: back to the close form with the error.
		$url = $this->submit(
			'action',
			array(
				'easyq_post'   => (string) $question->id,
				'easyq_action' => 'close',
				'duplicate_of' => '999999',
			),
			true,
			$form_page
		);
		$this->assertFalse( $this->post( $question->id )->is_closed() );
		$this->assertSame( '', $url, 'The close form is shown again with the error.' );
		$this->assertSame( '999999', Flash::kept( 'action', 'duplicate_of', $question->id ) );

		$url = $this->submit(
			'action',
			array(
				'easyq_post'   => (string) $question->id,
				'easyq_action' => 'close',
				'note'         => 'Answered elsewhere.',
			),
			true,
			$form_page
		);

		$this->assertTrue( $this->post( $question->id )->is_closed() );
		// Then the question page without the close form.
		$this->assertStringStartsWith( URLs::question( $question ), $url );
		$this->assertStringNotContainsString( 'easyq_close', $url );
	}

	public function test_deleting_a_question_goes_to_the_start_page(): void {
		$question = $this->ask( $this->user() );
		$this->login( 'editor' );
		Services::post_service()->set_question_status( Actor::current(), $question, Post::STATUS_HIDDEN );

		$url = $this->submit(
			'action',
			array(
				'easyq_post'   => (string) $question->id,
				'easyq_action' => 'delete',
			)
		);

		$this->assertNull( Services::posts()->find( $question->id ) );
		$this->assertStringStartsWith( Routes::url( 'home' ), $url );
		$this->assertSame( 'success', Flash::current()['type'] ?? null );
	}

	public function test_deleting_an_answer_goes_to_its_question(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$this->login( 'editor' );
		Services::post_service()->set_answer_status( Actor::current(), $answer, $question, Post::STATUS_HIDDEN );

		$url = $this->submit(
			'action',
			array(
				'easyq_post'   => (string) $answer->id,
				'easyq_action' => 'delete',
			)
		);

		$this->assertNull( Services::posts()->find( $answer->id ) );
		$this->assertStringStartsWith( URLs::question( $question ), $url );
		$this->assertSame( 'success', Flash::current()['type'] ?? null );
	}

	public function test_stops_the_emails_about_a_post(): void {
		$question = $this->post( Services::post_service()->create_question( $this->user(), 'A question with emails', 'Text.', '', array(), null, '@' ) );

		$this->submit(
			'unsubscribe',
			array(
				'post'  => (string) $question->id,
				'token' => 'wrong',
			)
		);
		$this->assertSame( 'error', Flash::current()['type'] ?? null );
		$this->assertSame( '@', $this->post( $question->id )->notify );

		$url = $this->submit(
			'unsubscribe',
			array(
				'post'  => (string) $question->id,
				'token' => Unsubscribe::token( $question->id ),
			)
		);
		$this->assertNull( $this->post( $question->id )->notify );
		$this->assertStringStartsWith( Routes::url( 'home' ), $url );
		$this->assertSame( 'You will no longer get emails about this post.', Flash::current()['message'] ?? null );
	}

	public function test_the_account_form_turns_emails_off_and_on(): void {
		$user = $this->login();

		$this->submit( 'account', array( 'notifications' => '0' ) );
		$this->assertFalse( Notifications::enabled( $user ) );

		$this->submit( 'account', array( 'notifications' => '1' ) );
		$this->assertTrue( Notifications::enabled( $user ) );

		$html = do_blocks( '<!-- wp:easyq/account /-->' );
		$this->assertMatchesRegularExpression( '/name="notifications" value="1"\s+checked/', $html );
		Notifications::set_enabled( $user, false );
		$this->assertDoesNotMatchRegularExpression( '/name="notifications" value="1"\s+checked/', do_blocks( '<!-- wp:easyq/account /-->' ) );
	}

	public function test_saves_the_account(): void {
		$user = $this->login();

		$url = $this->submit(
			'account',
			array(
				'field_location' => 'Lisbon',
				'field_unknown'  => 'ignored',
			)
		);

		$this->assertStringStartsWith( Routes::url( 'account' ), $url );
		$this->assertSame( 'success', Flash::current()['type'] ?? '' );
		$this->assertSame( 'Lisbon', Services::user_fields()->value( $user, Services::user_fields()->all()[0] ) );
	}

	public function test_account_errors_keep_the_values(): void {
		// Uploads are off by default; the site owner allows them.
		\EasyQ\Settings\Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$this->login();
		$file = wp_tempnam( 'avatar' );
		file_put_contents( $file, 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$_FILES['avatar'] = array(
			'name'     => 'avatar.png',
			'type'     => 'image/png',
			'tmp_name' => $file,
			'size'     => 12,
			'error'    => UPLOAD_ERR_OK,
		);

		$this->submit( 'account', array( 'field_location' => 'Oslo' ) );
		unset( $_FILES['avatar'] );

		$this->assertSame( 'error', Flash::current()['type'] ?? '' );
		$this->assertSame( 'Oslo', Flash::value( 'account', 'field_location' ) );
		$this->assertNotSame( '', Flash::error( 'account', 'avatar' ) );
	}

	public function test_reports_permission_errors(): void {
		$question = $this->ask( $this->user() );

		$this->submit(
			'vote',
			array(
				'easyq_post' => (string) $question->id,
				'vote'       => '1',
			)
		);

		$this->assertSame( 'error', Flash::current()['type'] ?? '' );
		$this->assertStringContainsString( 'log in', strtolower( Flash::current()['message'] ?? '' ) );
	}
}
