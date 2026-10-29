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
use EasyQ\Posts\Post;
use EasyQ\Services;

/**
 * @covers \EasyQ\Frontend\Form_Handler
 * @covers \EasyQ\Frontend\Flash
 */
final class Form_Handler_Test extends REST_Test_Case {

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
	 * @return string Redirect address.
	 */
	private function submit( string $form, array $fields, bool $nonce = true ): string {
		$_POST                   = $fields;
		$_POST['easyq_form']     = $form;
		$_POST['_easyq_nonce']   = $nonce ? wp_create_nonce( 'easyq_' . $form ) : 'invalid';
		$_SERVER['HTTP_REFERER'] = home_url( '/questions/ask/' );

		$url = ( new Form_Handler() )->process();
		$this->assertIsString( $url );

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET[ Flash::QUERY_VAR ] = $query[ Flash::QUERY_VAR ] ?? '';

		return $url;
	}

	public function test_ignores_requests_without_form(): void {
		$this->assertNull( ( new Form_Handler() )->process() );
	}

	public function test_refuses_expired_forms(): void {
		$this->login();

		$this->submit( 'ask', array( 'title' => 'A question from an old form' ), false );

		$this->assertSame( 'error', Flash::current()['type'] ?? '' );
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

	public function test_returns_to_the_form_page_after_failures(): void {
		$this->login();
		// Forms are sent to the page they are on, so the referer equals the current address.
		$_SERVER['REQUEST_URI'] = '/questions/ask/';

		$url = $this->submit(
			'ask',
			array(
				'title'   => 'Short',
				'content' => 'Some text',
			)
		);

		$this->assertStringStartsWith( home_url( '/questions/ask/?easyq_notice=' ), $url );
	}

	public function test_does_not_return_to_other_sites(): void {
		$this->login();
		$_REQUEST['_wp_http_referer'] = 'https://attacker.example/phish';

		$url = $this->submit(
			'ask',
			array(
				'title'   => 'Short',
				'content' => 'Some text',
			)
		);

		$this->assertStringNotContainsString( 'attacker.example', $url );
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

		$this->submit(
			'action',
			array(
				'easyq_post'   => (string) $question->id,
				'easyq_action' => 'close',
				'note'         => 'Answered elsewhere.',
			)
		);

		$this->assertTrue( $this->post( $question->id )->is_closed() );
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
