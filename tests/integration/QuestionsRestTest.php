<?php
/**
 * Tests for the question endpoints.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;

/**
 * @covers \EasyQ\Rest\Questions_Controller
 * @covers \EasyQ\Rest\Controller
 * @covers \EasyQ\Rest\Post_Formatter
 * @covers \EasyQ\Rest\Post_Input
 * @covers \EasyQ\Rest\Rest_Errors
 */
final class QuestionsRestTest extends Rest_TestCase {

	public function test_registers_the_routes(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( array( '/easyq/v1/questions', '/easyq/v1/questions/(?P<id>\d+)', '/easyq/v1/questions/similar', '/easyq/v1/posts/(?P<id>\d+)/vote' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}
	}

	public function test_lists_visible_questions_with_pagination(): void {
		$author = $this->user();
		$first  = $this->ask( $author, 'First listed question' );
		$this->ask( $author, 'Second listed question' );
		$hidden = $this->ask( $author, 'Hidden listed question' );
		Services::post_service()->set_question_status( Actor::system(), $hidden, Post::STATUS_HIDDEN );

		$response = $this->request( 'GET', '/questions', array( 'per_page' => 1 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertCount( 1, $this->data( $response ) );

		$ids = array_column( $this->data( $this->request( 'GET', '/questions' ) ), 'id' );
		$this->assertContains( $first->id, $ids );
		$this->assertNotContains( $hidden->id, $ids );
	}

	public function test_filters_by_source_tag_and_category(): void {
		$author   = $this->user();
		$category = Services::categories()->create( null, 'Filtered' );
		$this->assertIsInt( $category );
		$tagged   = $this->ask( $author, 'Question with a tag here', 'x', array( 'special' ) );
		$answered = $this->ask( $author, 'Question with an answer here', 'x', array(), $category );
		$this->answer( $this->user(), $answered );

		$this->assertSame( array( $tagged->id ), array_column( $this->data( $this->request( 'GET', '/questions', array( 'tag' => 'special' ) ) ), 'id' ) );
		$this->assertSame( array( $answered->id ), array_column( $this->data( $this->request( 'GET', '/questions', array( 'category' => $category ) ) ), 'id' ) );
		$this->assertSame( array( $tagged->id ), array_column( $this->data( $this->request( 'GET', '/questions', array( 'source' => 'unanswered' ) ) ), 'id' ) );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/questions', array( 'tag' => 'unknown' ) ) ) );
		$this->assertSame( 400, $this->request( 'GET', '/questions', array( 'source' => 'bogus' ) )->get_status() );
	}

	public function test_returns_a_question_with_permissions_for_the_viewer(): void {
		$author   = $this->user();
		$question = $this->ask( $author, 'Readable question title', 'Visit https://example.com today.' );

		$data = $this->data( $this->request( 'GET', '/questions/' . $question->id ) );

		$this->assertSame( 'question', $data['type'] );
		$this->assertSame( 'Readable question title', $data['title']['rendered'] );
		$this->assertStringContainsString( '<a href="https://example.com"', $data['content']['rendered'] );
		$this->assertArrayNotHasKey( 'raw', $data['content'] );
		$this->assertFalse( $data['can']['edit'] );

		wp_set_current_user( (int) $author->user_id );
		$data = $this->data( $this->request( 'GET', '/questions/' . $question->id, array( 'context' => 'edit' ) ) );

		$this->assertTrue( $data['can']['edit'] );
		$this->assertSame( 'Visit https://example.com today.', $data['content']['raw'] );
	}

	public function test_hidden_questions_are_only_visible_to_permitted_users(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );

		$this->assertSame( 404, $this->request( 'GET', '/questions/' . $question->id )->get_status() );

		wp_set_current_user( (int) $author->user_id );
		$this->assertSame( 200, $this->request( 'GET', '/questions/' . $question->id )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/questions/999999' )->get_status() );
	}

	public function test_visitors_need_an_account_to_ask(): void {
		$response = $this->request(
			'POST',
			'/questions',
			array(
				'title'   => 'A question from a visitor',
				'content' => 'Text.',
			)
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'easyq_login', $response->as_error()->get_error_code() );
	}

	public function test_asks_a_question(): void {
		$user_id = $this->login();

		$response = $this->request(
			'POST',
			'/questions',
			array(
				'title'   => 'How do block themes work?',
				'content' => 'Please explain.',
				'tags'    => 'themes blocks',
			)
		);
		$data     = $this->data( $response );

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( $data['queued'] );
		$this->assertSame( array( 'themes', 'blocks' ), $data['tags'] );
		$this->assertSame( $user_id, $data['author']['id'] );
		$this->assertStringEndsWith( '/easyq/v1/questions/' . $data['id'], $response->get_headers()['Location'] );
	}

	public function test_filters_html_content(): void {
		$this->login();

		$data = $this->data(
			$this->request(
				'POST',
				'/questions',
				array(
					'title'   => 'Question with formatted text',
					'content' => '<p>Hello <script>alert(1)</script><strong>world</strong></p>',
					'format'  => 'html',
				)
			)
		);

		$this->assertStringNotContainsString( '<script>', $data['content']['raw'] );
		$this->assertStringContainsString( '<strong>world</strong>', $data['content']['rendered'] );
	}

	public function test_reports_validation_errors_by_field(): void {
		$this->login();

		$response = $this->request(
			'POST',
			'/questions',
			array(
				'title'    => 'Short',
				'content'  => '',
				'category' => 999,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'category', $response->as_error()->get_error_data()['fields'] );

		$response = $this->request( 'POST', '/questions', array( 'title' => 'Short' ) );
		$this->assertArrayHasKey( 'title', $response->as_error()->get_error_data()['fields'] );
	}

	public function test_moderated_users_ask_queued_questions(): void {
		Options::update(
			'spam',
			array(
				'moderate_by_points'    => true,
				'moderate_points_limit' => 1000,
			)
		);
		$this->login();

		$data = $this->data(
			$this->request(
				'POST',
				'/questions',
				array(
					'title'   => 'A question that waits for approval',
					'content' => 'x',
				)
			)
		);

		$this->assertTrue( $data['queued'] );
		$this->assertSame( 'queued', $data['status'] );
	}

	public function test_rate_limits_and_read_only_mode(): void {
		Options::update( 'spam', array( 'max_rate_user_qs' => 1 ) );
		$this->login();
		$params = array(
			'title'   => 'A question within the limit',
			'content' => 'x',
		);

		$this->assertSame( 201, $this->request( 'POST', '/questions', $params )->get_status() );
		$this->assertSame( 429, $this->request( 'POST', '/questions', $params )->get_status() );

		Options::update( 'general', array( 'read_only' => true ) );
		$this->login();
		$this->assertSame( 503, $this->request( 'POST', '/questions', $params )->get_status() );

		$this->login( 'administrator' );
		$this->assertSame( 201, $this->request( 'POST', '/questions', $params )->get_status() );
	}

	public function test_anonymous_questions_when_allowed(): void {
		global $wpdb;

		Options::update( 'permissions', array( 'permit_post_q' => Permits::ALL ) );

		$response = $this->request(
			'POST',
			'/questions',
			array(
				'title'   => 'A question from a visitor',
				'content' => 'x',
				'name'    => 'Grace',
			)
		);
		$data     = $this->data( $response );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Grace', $data['author']['name'] );
		$this->assertNull( $data['author']['id'] );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'easyq_cookies' ) ) );
		$this->assertTrue( $data['can']['edit'] );
	}

	public function test_edits_own_questions_only(): void {
		$author   = $this->user();
		$question = $this->ask( $author );

		$this->login();
		$this->assertSame( 403, $this->request( 'PUT', '/questions/' . $question->id, array( 'title' => 'Changed by someone else' ) )->get_status() );

		wp_set_current_user( (int) $author->user_id );
		$response = $this->request(
			'PUT',
			'/questions/' . $question->id,
			array(
				'title' => 'Changed by the author',
				'tags'  => array( 'edited' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Changed by the author', $this->data( $response )['title']['raw'] );
		$this->assertSame( array( 'edited' ), $this->post( $question->id )->tag_list() );
		$this->assertSame(
			403,
			$this->request(
				'PUT',
				'/questions/' . $question->id,
				array(
					'silent' => true,
					'title'  => 'Silent edit by author',
				)
			)->get_status()
		);
	}

	public function test_editors_move_questions(): void {
		$question = $this->ask( $this->user() );
		$category = Services::categories()->create( null, 'Destination' );
		$this->assertIsInt( $category );
		$this->login( 'editor' );

		$response = $this->request( 'PUT', '/questions/' . $question->id, array( 'category' => $category ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $category, $this->post( $question->id )->category_id );
	}

	public function test_finds_similar_questions_and_suggests_tags(): void {
		Options::update(
			'posting',
			array(
				'do_ask_check_qs'    => true,
				'match_ask_check_qs' => 1,
				'match_example_tags' => 1,
			)
		);
		$question = $this->ask( $this->user(), 'Configure the hedgehog plugin', 'x', array( 'hedgehog' ) );

		$data = $this->data( $this->request( 'GET', '/questions/similar', array( 'title' => 'hedgehog plugin settings' ) ) );

		$this->assertSame( array( $question->id ), array_column( $data['questions'], 'id' ) );
		$this->assertSame( array( 'hedgehog' ), $data['tags'] );
	}

	public function test_viewing_can_require_an_account(): void {
		Options::update( 'permissions', array( 'permit_view_q_page' => Permits::USERS ) );
		$question = $this->ask( $this->user() );

		$this->assertSame( 401, $this->request( 'GET', '/questions' )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', '/questions/' . $question->id )->get_status() );
	}

	public function test_shows_ip_addresses_of_anonymous_posts_to_editors(): void {
		$question = $this->post( Services::post_service()->create_question( new Actor( null, 5, '203.0.113.9' ), 'Anonymous question title', 'x', '', array() ) );

		$this->assertArrayNotHasKey( 'ip', $this->data( $this->request( 'GET', '/questions/' . $question->id ) ) );

		$this->login( 'editor' );
		$this->assertSame( '203.0.113.9', $this->data( $this->request( 'GET', '/questions/' . $question->id ) )['ip'] );
	}
}
