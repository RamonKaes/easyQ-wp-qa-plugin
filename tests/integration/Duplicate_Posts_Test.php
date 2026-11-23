<?php
/**
 * Tests for the refusal of content posted twice.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\Schema;
use EasyQ\Users\Permits;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Visitor_Cookie;
use EasyQ\Util\Cookies;

/**
 * @covers \EasyQ\Posts\Duplicates
 */
final class Duplicate_Posts_Test extends REST_Test_Case {

	/**
	 * Address of the request before the test.
	 *
	 * @var mixed
	 */
	private mixed $remote_addr;

	public function set_up(): void {
		parent::set_up();
		$this->remote_addr      = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
	}

	public function tear_down(): void {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}
		parent::tear_down();
	}

	public function test_the_same_question_is_refused_like_question2answer(): void {
		$this->login();
		$params = array(
			'title'   => 'How do I bake bread at home?',
			'content' => 'I have flour and water.',
		);

		$this->assertSame( 201, $this->request( 'POST', '/questions', $params )->get_status() );
		$second = $this->request( 'POST', '/questions', $params );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'easyq_duplicate', $second->as_error()->get_error_code() );

		// The words count, not the markup or the case; another author is refused as well (Question2Answer).
		$this->login();
		$this->assertSame(
			409,
			$this->request(
				'POST',
				'/questions',
				array(
					'title'   => 'how do I bake BREAD at home',
					'content' => '<p>I have flour and water!</p>',
					'format'  => 'html',
				)
			)->get_status()
		);
		$this->assertSame(
			201,
			$this->request(
				'POST',
				'/questions',
				array(
					'title'   => 'How do I bake bread at home?',
					'content' => 'I have rye flour.',
				)
			)->get_status()
		);
	}

	public function test_a_hidden_question_or_one_older_than_the_five_newest_is_no_duplicate(): void {
		$this->login();
		$params = array(
			'title'   => 'Which oven temperature for bread?',
			'content' => 'Mine goes to 250 degrees.',
		);
		$first  = $this->data( $this->request( 'POST', '/questions', $params ) )['id'];

		Services::post_service()->set_question_status( Actor::system(), $this->post( $first ), Post::STATUS_HIDDEN );
		$this->assertSame( 201, $this->request( 'POST', '/questions', $params )->get_status() );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->ask( $this->user() );
		}
		$this->assertSame( 201, $this->request( 'POST', '/questions', $params )->get_status() );
	}

	public function test_a_waiting_question_of_the_same_visitor_is_a_duplicate(): void {
		global $wpdb;

		Options::update( 'permissions', array( 'permit_post_q' => Permits::ALL ) );
		Options::update( 'spam', array( 'moderate_anon_post' => true ) );
		$params = array(
			'title'   => 'A visitor asks about sourdough',
			'content' => 'Starter or yeast?',
		);

		$first = $this->data( $this->request( 'POST', '/questions', $params + array( 'remember' => true ) ) );
		$this->assertTrue( $first['queued'] );

		// The browser sends back the visitor cookie it was given.
		$cookie = $wpdb->get_var( $wpdb->prepare( 'SELECT cookie_id FROM %i WHERE id = %d', Schema::table( 'posts' ), $first['id'] ) );
		$_COOKIE[ Cookies::name( Visitor_Cookie::NAME ) ] = (string) $cookie;
		$this->assertSame( 409, $this->request( 'POST', '/questions', $params )->get_status() );

		// Another visitor's waiting question is no duplicate: only visible questions of others count.
		unset( $_COOKIE[ Cookies::name( Visitor_Cookie::NAME ) ] );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.7';
		$this->assertSame( 201, $this->request( 'POST', '/questions', $params )->get_status() );
	}

	public function test_a_visitor_who_declined_the_cookie_is_known_by_the_address(): void {
		Options::update( 'permissions', array( 'permit_post_a' => Permits::ALL ) );
		update_option( 'show_comments_cookies_opt_in', '1' );
		$question = $this->ask( $this->user() );
		$route    = '/questions/' . $question->id . '/answers';

		$this->assertSame( 201, $this->request( 'POST', $route, array( 'content' => 'Knead it for ten minutes.' ) )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', $route, array( 'content' => 'Knead it for ten minutes.' ) )->get_status() );

		$_SERVER['REMOTE_ADDR'] = '192.0.2.8';
		$this->assertSame( 201, $this->request( 'POST', $route, array( 'content' => 'Knead it for ten minutes.' ) )->get_status() );
	}

	public function test_the_same_answer_of_the_same_author_is_refused_like_core_comments(): void {
		$question = $this->ask( $this->user() );
		$route    = '/questions/' . $question->id . '/answers';
		$this->login();

		$this->assertSame( 201, $this->request( 'POST', $route, array( 'content' => 'Knead it for ten minutes.' ) )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', $route, array( 'content' => 'Knead it for ten minutes.' ) )->get_status() );
		$this->assertSame( 201, $this->request( 'POST', $route, array( 'content' => 'Then let it rest for an hour.' ) )->get_status() );

		// Another author may say the same, as core allows it for comments.
		$this->login();
		$this->assertSame( 201, $this->request( 'POST', $route, array( 'content' => 'Knead it for ten minutes.' ) )->get_status() );
		$this->assertSame( 2, count( array_filter( Services::posts()->children( $question->id ), fn ( Post $post ): bool => 'Knead it for ten minutes.' === $post->content ) ) );
	}

	public function test_the_same_comment_of_the_same_author_on_the_same_post_is_refused(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$this->login();

		$this->assertSame( 201, $this->request( 'POST', '/posts/' . $answer->id . '/comments', array( 'content' => 'A helpful comment.' ) )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', '/posts/' . $answer->id . '/comments', array( 'content' => 'A helpful comment.' ) )->get_status() );
		$this->assertSame( 201, $this->request( 'POST', '/posts/' . $this->answer( $this->user(), $question, 'Another answer here.' )->id . '/comments', array( 'content' => 'A helpful comment.' ) )->get_status() );
	}

	public function test_a_question_the_author_may_not_see_is_no_duplicate(): void {
		// Visitors may ask but not read: the 409 must not tell them what members asked.
		Options::update(
			'permissions',
			array(
				'permit_post_q'      => Permits::ALL,
				'permit_view_q_page' => Permits::USERS,
			)
		);
		$this->ask( $this->user(), 'A question only members can read', 'Members only.' );

		$this->assertSame(
			201,
			$this->request(
				'POST',
				'/questions',
				array(
					'title'   => 'A question only members can read',
					'content' => 'Members only.',
				)
			)->get_status()
		);
	}

	public function test_a_waiting_question_of_a_visitor_who_declined_the_cookie_is_a_duplicate(): void {
		Options::update( 'permissions', array( 'permit_post_q' => Permits::ALL ) );
		Options::update( 'spam', array( 'moderate_anon_post' => true ) );
		update_option( 'show_comments_cookies_opt_in', '1' );
		$params = array(
			'title'   => 'A visitor asks about rye bread',
			'content' => 'Without a cookie.',
		);

		$this->assertTrue( $this->data( $this->request( 'POST', '/questions', $params ) )['queued'] );
		$this->assertSame( 409, $this->request( 'POST', '/questions', $params )->get_status() );
	}

	/**
	 * Check and insert are one step: while one request checks and creates, a second
	 * request, on its own connection to the database, cannot take the lock and waits, so it
	 * sees the post of the first and is refused (F-277, promised by F-144).
	 */
	public function test_check_and_insert_hold_a_lock_other_requests_wait_for(): void {
		global $wpdb;
		$lock  = $wpdb->prefix . 'easyq_post';
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$take  = static fn (): string => (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		$held  = null;

		$id    = \EasyQ\Posts\Duplicates::create(
			$this->user(),
			Post::QUESTION,
			null,
			'A question posted twice at once',
			'Content.',
			'',
			static function () use ( $take, &$held ): int {
				$held = $take();

				return 4711;
			}
		);
		$after = $take();
		$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		$other->close();

		$this->assertSame( 4711, $id );
		$this->assertSame( '0', $held, 'A second request cannot take the lock while the first checks and creates.' );
		$this->assertSame( '1', $after, 'The lock is free once the post is created.' );
	}
}
