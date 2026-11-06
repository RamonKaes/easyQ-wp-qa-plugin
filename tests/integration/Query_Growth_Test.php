<?php
/**
 * Tests that the number of database queries does not grow with the length of a list.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Templates;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;

/**
 * @coversNothing
 */
final class Query_Growth_Test extends Test_Case {

	/**
	 * Queries of the last render, for the failure messages.
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
	 * Renders a page with empty caches and returns its queries, with their values replaced.
	 *
	 * @param string $slug  Template.
	 * @param Route  $route Route.
	 * @return array<string, int> Number of queries by statement.
	 */
	private function queries( string $slug, Route $route ): array {
		Lists::reset();
		Block_Context::router()->set_current( $route );
		$definitions = ( new Templates( Block_Context::router() ) )->definitions();
		// As in a new request: what services loaded so far must not hide a query.
		Services::reset();
		wp_cache_flush();

		$this->log = array();
		add_filter( 'query', array( $this, 'remember' ) );
		do_blocks( $definitions[ $slug ]['content'] );
		remove_filter( 'query', array( $this, 'remember' ) );

		$statements = array();
		foreach ( $this->log as $query ) {
			$statement                = (string) preg_replace( array( "/'[^']*'/", '/\b\d+\b/', '/\s+/', '/(\?,\s*)+\?/' ), array( '?', '?', ' ', '?' ), $query );
			$statements[ $statement ] = ( $statements[ $statement ] ?? 0 ) + 1;
		}

		return $statements;
	}

	/**
	 * Checks that no statement runs more often with a longer list.
	 *
	 * @param array<string, int> $short Statements of the short list.
	 * @param array<string, int> $long  Statements of the long list.
	 * @param string             $page  Page, for the message.
	 */
	private function assert_no_growth( array $short, array $long, string $page ): void {
		$grown = array();
		foreach ( $long as $statement => $count ) {
			if ( $count > ( $short[ $statement ] ?? 0 ) ) {
				$grown[] = ( $short[ $statement ] ?? 0 ) . ' -> ' . $count . ': ' . $statement;
			}
		}

		$this->assertSame( array(), $grown, $page );
	}

	public function test_lists_query_the_same_with_more_items(): void {
		Options::update(
			'lists',
			array(
				'page_size_qs'       => 50,
				'page_size_home'     => 50,
				'page_size_activity' => 50,
				'page_size_users'    => 50,
			)
		);
		$pages = array(
			'easyq-questions'  => new Route( 'questions' ),
			'easyq-home'       => new Route( 'home' ),
			'easyq-activity'   => new Route( 'activity' ),
			'easyq-users'      => new Route( 'users' ),
			'easyq-tags'       => new Route( 'tags' ),
			'easyq-hot'        => new Route( 'hot' ),
			'easyq-unanswered' => new Route( 'unanswered' ),
			'easyq-answers'    => new Route( 'answers' ),
			'easyq-comments'   => new Route( 'comments' ),
		);

		$fill = function ( int $count, string $prefix ): void {
			for ( $i = 0; $i < $count; $i++ ) {
				$this->answer( $this->user(), $this->ask( $this->user(), "$prefix question number $i", 'Text.', array( "$prefix$i" ) ) );
			}
		};

		$fill( 3, 'few' );
		$short = array_map( fn ( string $slug, Route $route ): array => $this->queries( $slug, $route ), array_keys( $pages ), $pages );

		$fill( 12, 'many' );
		foreach ( array_keys( $pages ) as $index => $slug ) {
			$this->assert_no_growth( $short[ $index ], $this->queries( $slug, $pages[ $slug ] ), $slug );
		}
	}

	public function test_the_question_page_queries_the_same_with_more_answers(): void {
		$question = $this->ask( $this->user(), 'Question with answers' );
		$fill     = function ( int $count ) use ( $question ): void {
			for ( $i = 0; $i < $count; $i++ ) {
				$answer = $this->answer( $this->user(), $this->post( $question->id ), "Answer $i" );
				$this->comment( $this->user(), $this->post( $question->id ), $answer, "Comment $i" );
			}
		};

		$route = fn (): Route => new Route( 'question', $this->post( $question->id ) );

		$fill( 3 );
		wp_set_current_user( 0 );
		$guest = $this->queries( 'easyq-question', $route() );
		wp_set_current_user( $this->administrator() );
		$member = $this->queries( 'easyq-question', $route() );

		$fill( 10 );
		wp_set_current_user( 0 );
		$this->assert_no_growth( $guest, $this->queries( 'easyq-question', $route() ), 'guest' );
		wp_set_current_user( $this->administrator() );
		$this->assert_no_growth( $member, $this->queries( 'easyq-question', $route() ), 'member' );
	}

	public function test_the_updates_page_queries_the_same_with_more_updates(): void {
		$viewer = $this->user();
		wp_set_current_user( (int) $viewer->user_id );
		$fill = function ( int $count, string $prefix ) use ( $viewer ): void {
			for ( $i = 0; $i < $count; $i++ ) {
				$question = $this->ask( $viewer, "$prefix question number $i" );
				$this->answer( $this->user(), $question );
			}
		};

		$fill( 3, 'few' );
		$short = $this->queries( 'easyq-updates', new Route( 'updates' ) );
		$this->assertCount( 3, Services::updates()->items( (int) $viewer->user_id ) );

		$fill( 10, 'many' );
		$this->assert_no_growth( $short, $this->queries( 'easyq-updates', new Route( 'updates' ) ), 'updates' );
	}

	public function test_walls_and_message_boxes_query_the_same_with_more_messages(): void {
		$owner = $this->user();
		$user  = get_userdata( (int) $owner->user_id );
		wp_set_current_user( (int) $owner->user_id );
		Options::update(
			'users',
			array(
				'page_size_wall' => 50,
				'page_size_pms'  => 50,
			)
		);
		$fill = function ( int $count ) use ( $owner ): void {
			for ( $i = 0; $i < $count; $i++ ) {
				$author = $this->user();
				Services::messages()->post_on_wall( $author, get_userdata( (int) $owner->user_id ), "Wall post $i" );
				Services::messages()->send( $author, get_userdata( (int) $owner->user_id ), "Message $i" );
			}
		};

		$fill( 3 );
		$wall  = $this->queries( 'easyq-user-wall', new Route( 'user', $user, 'wall' ) );
		$inbox = $this->queries( 'easyq-messages', new Route( 'messages' ) );

		$fill( 10 );
		$this->assert_no_growth( $wall, $this->queries( 'easyq-user-wall', new Route( 'user', $user, 'wall' ) ), 'wall' );
		$this->assert_no_growth( $inbox, $this->queries( 'easyq-messages', new Route( 'messages' ) ), 'inbox' );
	}

	public function test_primed_values_are_those_of_single_lookups(): void {
		$author   = $this->user();
		$stranger = (int) $this->user()->user_id;
		$this->ask( $author, 'A question for points' );
		$category = Services::categories()->create( null, 'Primed' );
		$this->assertIsInt( $category );
		Services::user_levels()->set_category_level( Actor::system(), (int) $author->user_id, $category, Levels::EXPERT );

		$points = (int) Services::points()->get( (int) $author->user_id );
		$levels = Services::user_levels()->category_levels( (int) $author->user_id );
		$this->assertNotSame( array(), $levels );

		wp_cache_flush();
		Services::user_levels()->prime_category_levels( array( (int) $author->user_id, $stranger ) );
		$this->assertSame( $levels, Services::user_levels()->category_levels( (int) $author->user_id ) );
		$this->assertSame( array(), Services::user_levels()->category_levels( $stranger ) );

		$service = new \EasyQ\Points\Points();
		$service->prime( array( (int) $author->user_id, $stranger ) );
		$this->assertSame( $points, $service->get( (int) $author->user_id ) );
		$this->assertSame( Options::get_int( 'points', 'points_base' ), $service->get( $stranger ) );
	}

	public function test_the_check_finds_a_query_per_item(): void {
		$short = array( 'SELECT a' => 1 );

		$this->assert_no_growth( $short, array( 'SELECT a' => 1 ), 'same' );
		$this->assert_no_growth( array( 'SELECT a' => 2 ), array( 'SELECT a' => 1 ), 'fewer' );

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->assert_no_growth( $short, array( 'SELECT a' => 2 ), 'more' );
	}
}
