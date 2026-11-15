<?php
/**
 * Tests that the lists of the REST API cost no more queries with more items.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_REST_Response;

/**
 * @coversNothing
 */
final class REST_Query_Growth_Test extends REST_Test_Case {

	/**
	 * Lists whose length does not depend on the data of the site: the email templates are
	 * the fixed set of the plugin.
	 *
	 * @var string[]
	 */
	private const FIXED = array( '/easyq/v1/email-templates' );

	/**
	 * Queries of the last request.
	 *
	 * @var string[]
	 */
	private array $log = array();

	/**
	 * Remembers a query.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public function remember( string $query ): string {
		$this->log[] = $query;

		return $query;
	}

	/**
	 * Runs a request with empty caches, as a new request starts, and counts its statements.
	 *
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Parameters.
	 * @return array{0: array<string, int>, 1: int} Statements with their counts, and the number of items.
	 */
	private function measure( string $route, array $params ): array {
		Services::reset();
		wp_cache_flush();

		$this->log = array();
		add_filter( 'query', array( $this, 'remember' ) );
		$response = $this->request( 'GET', $route, $params );
		remove_filter( 'query', array( $this, 'remember' ) );

		$this->assertSame( 200, $response->get_status(), $route );
		$statements = array();
		foreach ( $this->log as $query ) {
			$statement                = (string) preg_replace( array( "/'[^']*'/", '/\b\d+\b/', '/\s+/', '/(\?,\s*)+\?/' ), array( '?', '?', ' ', '?' ), $query );
			$statements[ $statement ] = ( $statements[ $statement ] ?? 0 ) + 1;
		}
		$data = $response->get_data();

		// Similar questions come with their suggested tags.
		$items = is_array( $data ) && isset( $data['questions'] ) && is_array( $data['questions'] ) ? $data['questions'] : $data;

		return array( $statements, is_array( $items ) ? count( $items ) : 0 );
	}

	/**
	 * Adds items to every list.
	 *
	 * @param int                  $count How many of each.
	 * @param array<string, mixed> $site  The users and posts the lists belong to.
	 */
	private function fill( int $count, array $site ): void {
		static $round = 0;
		++$round;
		$viewer = $site['viewer'];
		$owner  = get_userdata( $site['owner'] );
		$this->assertNotFalse( $owner );

		for ( $i = 0; $i < $count; $i++ ) {
			$author = $this->user();
			$this->take_part( (int) $author->user_id );
			$question = $this->ask( $author, "Question $round $i about block themes", 'Text.', array( "tag$round$i" ) );
			Services::votes()->vote( $viewer, $question, 1 );
			Services::favorites()->set( $viewer, 'question', $question->id, true );
			Services::favorites()->set( $viewer, 'user', (int) $author->user_id, true );
			Services::favorites()->set( $viewer, 'tag', "tag$round$i", true );
			$answer = $this->answer( $author, $site['question'], "Answer $round $i of the list" );
			$this->comment( $author, $site['question'], $site['answer'], "Comment $round $i of the list" );
			Services::votes()->vote( $viewer, $answer, 1 );
			Services::messages()->post_on_wall( $author, $owner, "Wall post $round $i" );
			Services::messages()->send( $author, get_userdata( (int) $viewer->user_id ), "Message $round $i" );
			Services::notices()->add( (int) $viewer->user_id, "Notice $round $i" );
			Services::categories()->create( null, "Category $round $i" );
			Services::user_fields()->create( "field_$round$i", "Field $round $i", 0, null );
		}
	}

	public function test_every_list_queries_the_same_with_more_items(): void {
		Options::update(
			'users',
			array(
				'allow_user_walls'       => true,
				'allow_private_messages' => true,
				'show_message_history'   => true,
			)
		);
		Options::update( 'general', array( 'tags_or_categories' => 'tc' ) );
		Options::update(
			'posting',
			array(
				'comment_on_as'          => true,
				'do_ask_check_qs'        => true,
				'page_size_ask_check_qs' => 50,
				'match_ask_check_qs'     => 1,
			)
		);
		$viewer = $this->user();
		$this->take_part( (int) $viewer->user_id );
		$owner = (int) $this->user()->user_id;
		$this->take_part( $owner );
		$question = $this->ask( $this->user(), 'The question with many answers' );
		$answer   = $this->answer( $this->user(), $question, 'The answer with many comments' );
		$site     = array(
			'viewer'   => $viewer,
			'owner'    => $owner,
			'question' => $question,
			'answer'   => $answer,
		);
		$admin    = $this->administrator();

		// Each list with the user who sees it; every list route of the server must be here.
		$lists = array(
			array( '/questions', array( 'per_page' => 50 ), (int) $viewer->user_id ),
			array( '/questions/similar', array( 'title' => 'Question about block themes' ), (int) $viewer->user_id ),
			array( '/questions/' . $question->id . '/answers', array( 'per_page' => 50 ), (int) $viewer->user_id ),
			array( '/posts/' . $answer->id . '/comments', array( 'per_page' => 50 ), (int) $viewer->user_id ),
			array( '/posts', array( 'per_page' => 50 ), $admin ),
			array( '/user-fields', array(), $admin ),
			array( '/categories', array(), (int) $viewer->user_id ),
			array( '/tags', array( 'per_page' => 50 ), (int) $viewer->user_id ),
			array( '/users', array( 'per_page' => 50 ), (int) $viewer->user_id ),
			array( '/users', array( 'sort' => 'favorites' ), (int) $viewer->user_id ),
			array( '/favorites', array( 'type' => 'question' ), (int) $viewer->user_id ),
			array( '/favorites', array( 'type' => 'user' ), (int) $viewer->user_id ),
			array( '/favorites', array( 'type' => 'tag' ), (int) $viewer->user_id ),
			array( '/notices', array(), (int) $viewer->user_id ),
			array( '/users/' . $owner . '/wall', array(), (int) $viewer->user_id ),
			array( '/messages', array(), (int) $viewer->user_id ),
		);

		$covered = array_map( static fn ( array $entry ): string => '/easyq/v1' . preg_replace( '/\/\d+/', '/{id}', $entry[0] ), $lists );
		$routes  = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$method = is_array( $endpoint['callback'] ?? null ) ? $endpoint['callback'][1] : '';
				if ( isset( $endpoint['methods']['GET'] ) && in_array( $method, array( 'get_items', 'get_wall', 'get_similar' ), true ) && ! in_array( $route, self::FIXED, true ) ) {
					$routes[] = (string) preg_replace( '/\(\?P<\w+>[^)]+\)/', '{id}', $route );
				}
			}
		}
		$this->assertSame( array(), array_values( array_diff( $routes, $covered ) ), 'A list of the REST API is not measured here.' );

		$this->fill( 2, $site );
		$short = array();
		foreach ( $lists as $index => [ $route, $params, $user_id ] ) {
			wp_set_current_user( $user_id );
			$short[ $index ] = $this->measure( $route, $params );
		}

		$this->fill( 5, $site );
		foreach ( $lists as $index => [ $route, $params, $user_id ] ) {
			wp_set_current_user( $user_id );
			[ $statements, $items ] = $this->measure( $route, $params );
			$label                  = $route . ' ' . wp_json_encode( $params );
			$this->assertGreaterThan( $short[ $index ][1], $items, "$label lists more items." );

			$grown = array();
			foreach ( $statements as $statement => $count ) {
				if ( $count > ( $short[ $index ][0][ $statement ] ?? 0 ) ) {
					$grown[] = ( $short[ $index ][0][ $statement ] ?? 0 ) . ' -> ' . $count . ': ' . $statement;
				}
			}
			$this->assertSame( array(), $grown, $label );
		}
	}
}
