<?php
/**
 * Tests for form submissions without JavaScript.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Frontend\Flash;
use EasyQ\Frontend\Form_Handler;
use EasyQ\Services;

/**
 * @covers \EasyQ\Frontend\Form_Handler
 * @covers \EasyQ\Frontend\Flash
 */
final class FormHandlerTest extends Rest_TestCase {

	public function tear_down(): void {
		$_POST = array();
		unset( $_SERVER['HTTP_REFERER'], $_GET[ Flash::QUERY_VAR ] );
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
