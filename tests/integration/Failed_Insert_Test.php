<?php
/**
 * Tests for what happens when the database refuses to store a post.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\Schema;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Avatars;

/**
 * A failing insert can be made with the filter `query` of core, and a failing attachment with
 * `wp_insert_post_empty_content`; each path then stores nothing and says so (F-239).
 *
 * @covers \EasyQ\Posts\Post_Service
 * @covers \EasyQ\REST\Questions_Controller
 * @covers \EasyQ\REST\Answers_Controller
 * @covers \EasyQ\REST\Comments_Controller
 * @covers \EasyQ\Users\Avatars
 */
final class Failed_Insert_Test extends REST_Test_Case {

	/**
	 * Runs a step while every insert into the posts of easyQ fails.
	 *
	 * @param callable $step Step.
	 * @return mixed What the step returns.
	 */
	private static function refusing_posts( callable $step ): mixed {
		global $wpdb;
		$table  = Schema::table( 'posts' );
		$refuse = static fn ( string $query ): string => str_starts_with( ltrim( $query ), "INSERT INTO `$table`" ) ? 'INSERT INTO `' . $table . '_refused` (id) VALUES (1)' : $query;
		add_filter( 'query', $refuse );
		$errors = $wpdb->suppress_errors( true );
		try {
			return $step();
		} finally {
			$wpdb->suppress_errors( $errors );
			remove_filter( 'query', $refuse );
		}
	}

	/**
	 * Counts the posts of easyQ.
	 *
	 * @return int
	 */
	private static function posts(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'posts' ) ) );
	}

	public function test_the_services_store_nothing_and_say_so(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $this->user(), $question );
		$before   = self::posts();

		$this->assertSame( 0, self::refusing_posts( static fn (): int => Services::post_service()->create_question( $author, 'A question the database refuses', 'Text.' ) ) );
		$this->assertSame( 0, self::refusing_posts( static fn (): int => Services::post_service()->create_answer( $author, $question, 'An answer the database refuses.' ) ) );
		$this->assertSame( 0, self::refusing_posts( static fn (): int => Services::post_service()->create_comment( $author, $question, $answer, 'A comment the database refuses.' ) ) );
		$this->assertSame( $before, self::posts() );

		// A question whose note cannot be stored is not closed without its reason.
		self::refusing_posts( fn () => Services::post_service()->close_with_note( Actor::system(), $this->post( $question->id ), 'A note the database refuses.' ) );
		Services::posts()->forget( $question->id );
		$this->assertFalse( $this->post( $question->id )->is_closed() );
	}

	public function test_the_rest_api_answers_that_the_post_could_not_be_saved(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$this->login();
		Options::update( 'posting', array( 'comment_on_qs' => true ) );

		foreach ( array(
			array(
				'/questions',
				array(
					'title'   => 'A question the database refuses',
					'content' => 'Text of the question.',
				),
			),
			array( '/questions/' . $question->id . '/answers', array( 'content' => 'An answer the database refuses.' ) ),
			array( '/posts/' . $answer->id . '/comments', array( 'content' => 'A comment the database refuses.' ) ),
		) as [ $route, $params ] ) {
			$error = self::refusing_posts( fn () => $this->request( 'POST', $route, $params )->as_error() );
			$this->assertInstanceOf( \WP_Error::class, $error, $route );
			$this->assertSame( 'easyq_not_created', $error->get_error_code(), $route );
			$this->assertSame( 500, $error->get_error_data()['status'] ?? null, $route );
		}
	}

	public function test_an_avatar_whose_attachment_fails_leaves_no_file(): void {
		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$tmp    = wp_tempnam( 'avatar' );
		$dir    = wp_upload_dir()['path'];
		$files  = static fn (): array => (array) glob( $dir . '/canola*' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $tmp );
		$before = $files();
		// Core refuses an attachment with this filter (`wp_insert_post()`).
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$result = Avatars::upload(
			(int) $this->user()->user_id,
			array(
				'name'     => 'canola.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $tmp,
				'size'     => (int) filesize( $tmp ),
				'error'    => UPLOAD_ERR_OK,
			)
		);
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $before, $files(), 'The cropped copy is deleted.' );
	}
}
