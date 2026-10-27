<?php
/**
 * Base class for integration tests.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use WP_UnitTestCase;

/**
 * Shared helpers for integration tests.
 */
abstract class Test_Case extends WP_UnitTestCase {

	/**
	 * Events reported during the test, in order.
	 *
	 * @var array<int, array{event: string, params: array<string, mixed>, actor: Actor}>
	 */
	protected array $events = array();

	/**
	 * Resets caches and services and starts recording events.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_cache_flush();
		Services::reset();

		$this->events = array();
		add_action(
			'easyq_event',
			function ( string $event, array $params, Actor $actor ): void {
				$this->events[] = array(
					'event'  => $event,
					'params' => $params,
					'actor'  => $actor,
				);
			},
			10,
			3
		);
	}

	/**
	 * Checks whether a table exists.
	 *
	 * Works for temporary tables too, which the test suite creates instead of
	 * regular tables during a test and which SHOW TABLES does not list.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$result   = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$wpdb->suppress_errors( $suppress );

		return null !== $result;
	}

	/**
	 * Marks the plugin as network-activated for the current test.
	 */
	protected function activate_network_wide(): void {
		update_site_option(
			'active_sitewide_plugins',
			array( plugin_basename( \EasyQ\PLUGIN_FILE ) => time() )
		);
	}

	/**
	 * Creates a user and returns an actor for them.
	 *
	 * @param string $role Role of the user.
	 * @return Actor
	 */
	protected function user( string $role = 'subscriber' ): Actor {
		return new Actor( self::factory()->user->create( array( 'role' => $role ) ), null, '192.0.2.1' );
	}

	/**
	 * Creates an administrator who may edit other users, also on multisite.
	 *
	 * @return int User ID.
	 */
	protected function administrator(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		return $user_id;
	}

	/**
	 * Creates a question.
	 *
	 * @param Actor    $author      Author.
	 * @param string   $title       Title.
	 * @param string   $content     Content.
	 * @param string[] $tags        Tags.
	 * @param int|null $category_id Category ID.
	 * @param bool     $queued      Whether the question waits for moderation.
	 * @return Post
	 */
	protected function ask( Actor $author, string $title = 'How do I configure the widget?', string $content = 'I tried everything.', array $tags = array( 'widgets' ), ?int $category_id = null, bool $queued = false ): Post {
		$id = Services::post_service()->create_question( $author, $title, $content, '', $tags, $category_id, null, null, null, $queued );

		return $this->post( $id );
	}

	/**
	 * Creates an answer.
	 *
	 * @param Actor  $author   Author.
	 * @param Post   $question Question.
	 * @param string $content  Content.
	 * @param bool   $queued   Whether the answer waits for moderation.
	 * @return Post
	 */
	protected function answer( Actor $author, Post $question, string $content = 'Open the settings screen.', bool $queued = false ): Post {
		$id = Services::post_service()->create_answer( $author, $question, $content, '', null, null, $queued );

		return $this->post( $id );
	}

	/**
	 * Creates a comment.
	 *
	 * @param Actor  $author      Author.
	 * @param Post   $question    Question.
	 * @param Post   $parent_post Question or answer.
	 * @param string $content     Content.
	 * @return Post
	 */
	protected function comment( Actor $author, Post $question, Post $parent_post, string $content = 'Thanks, that helped.' ): Post {
		$id = Services::post_service()->create_comment( $author, $question, $parent_post, $content );

		return $this->post( $id );
	}

	/**
	 * Loads a post fresh from the database.
	 *
	 * @param int $id Post ID.
	 * @return Post
	 */
	protected function post( int $id ): Post {
		Services::posts()->forget( $id );
		$post = Services::posts()->find( $id );
		$this->assertNotNull( $post, "Post $id does not exist." );

		return $post;
	}

	/**
	 * Returns the names of the events reported so far.
	 *
	 * @return string[]
	 */
	protected function event_names(): array {
		return array_column( $this->events, 'event' );
	}

	/**
	 * Returns the parameters of the last event with a name.
	 *
	 * @param string $name Event name.
	 * @return array<string, mixed>
	 */
	protected function event_params( string $name ): array {
		foreach ( array_reverse( $this->events ) as $event ) {
			if ( $event['event'] === $name ) {
				return $event['params'];
			}
		}

		$this->fail( "Event $name was not reported." );
	}

	/**
	 * Returns the IDs of the questions a search finds.
	 *
	 * @param string $query Search query.
	 * @return int[]
	 */
	protected function search_ids( string $query ): array {
		return array_map(
			static fn ( $result ): int => $result->question_id,
			Services::search()->search( $query, 0, 20 )
		);
	}
}
