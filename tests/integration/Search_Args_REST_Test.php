<?php
/**
 * Tests the search arguments of the lists the editor pickers read.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;

/**
 * @covers \EasyQ\REST\Questions_Controller
 * @covers \EasyQ\REST\Users_Controller
 */
final class Search_Args_REST_Test extends REST_Test_Case {

	public function test_questions_beyond_the_first_hundred_are_found(): void {
		$author = $this->user();
		$needle = $this->ask( $author, 'Where is the needle in the haystack?' );
		$hidden = $this->ask( $author, 'A hidden needle somewhere?' );
		Services::post_service()->set_question_status( Actor::system(), $hidden, Post::STATUS_HIDDEN );
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->ask( $author, "Question number $i about widgets?" );
		}

		$first = array_column( $this->data( $this->request( 'GET', '/questions', array( 'per_page' => 100 ) ) ), 'id' );
		$this->assertNotContains( $needle->id, $first, 'The oldest question is not on the first page.' );

		$found = $this->data( $this->request( 'GET', '/questions', array( 'search' => 'needle' ) ) );
		$this->assertSame( array( $needle->id ), array_column( $found, 'id' ) );
	}

	public function test_users_are_found_by_name_among_those_shown(): void {
		$shown = self::factory()->user->create( array( 'display_name' => 'Zebrina Shown' ) );
		$this->take_part( $shown );
		self::factory()->user->create( array( 'display_name' => 'Zebrina Hidden' ) );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->take_part( self::factory()->user->create( array( 'display_name' => "Other user $i" ) ) );
		}

		$found = $this->data( $this->request( 'GET', '/users', array( 'search' => 'Zebrina' ) ) );
		$this->assertSame( array( $shown ), array_column( $found, 'id' ) );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/users', array( 'search' => 'nobody-has-this' ) ) ) );

		// Not by login or email address, as core searches for those who may not list users: a search would tell them.
		$secret = self::factory()->user->create(
			array(
				'user_login'    => 'secretlogin',
				'user_nicename' => 'plain-name',
				'user_email'    => 'secret.mailbox@example.org',
				'display_name'  => 'Plain Name',
			)
		);
		$this->take_part( $secret );
		$this->assertSame( array( $secret ), array_column( $this->data( $this->request( 'GET', '/users', array( 'search' => 'Plain Name' ) ) ), 'id' ) );
		foreach ( array( 'secretlogin', 'secret.mailbox' ) as $term ) {
			$this->assertSame( array(), $this->data( $this->request( 'GET', '/users', array( 'search' => $term ) ) ), $term );
		}
	}

	public function test_a_search_starts_at_most_at_its_limit_as_question2answer_stops(): void {
		$spy = new class() implements \EasyQ\Search\Search_Provider {
			/**
			 * Starts asked for.
			 *
			 * @var int[]
			 */
			public array $starts = array();
			public function id(): string {
				return 'spy';
			}
			public function label(): string {
				return 'Spy';
			}
			public function index_post( \EasyQ\Search\Indexed_Post $post ): void {
			}
			public function unindex_post( int $post_id ): void {
			}
			public function move_post( int $post_id, ?int $category_id ): void {
			}
			public function search( string $query, int $start, int $count, ?int $user_id ): array {
				$this->starts[] = $start;
				return array();
			}
		};
		add_filter( 'easyq_search_providers', static fn ( array $providers ): array => array_merge( $providers, array( $spy ) ) );
		\EasyQ\Settings\Options::update( 'lists', array( 'search_provider' => 'spy' ) );
		\EasyQ\Services::reset();

		// The REST API and the search page, each asked for a page far beyond the limit (QA_MAX_LIMIT_START).
		$this->request(
			'GET',
			'/questions',
			array(
				'search'   => 'widget',
				'page'     => 99999,
				'per_page' => 10,
			)
		);
		\EasyQ\Blocks\Block_Context::router()->set_current( new \EasyQ\Frontend\Route( 'search' ) );
		$_GET['q']                                  = 'widget';
		$_GET[ \EasyQ\Blocks\Lists::MAIN_PAGE_VAR ] = '99999';
		\EasyQ\Blocks\Render\Query_Resolver::questions(
			array(
				'inherit' => true,
				'perPage' => 10,
			),
			'list'
		);
		unset( $_GET['q'], $_GET[ \EasyQ\Blocks\Lists::MAIN_PAGE_VAR ] );
		\EasyQ\Blocks\Block_Context::router()->set_current( null );
		$this->assertCount( 2, $spy->starts, 'The REST API and the search page.' );

		$this->assertNotEmpty( $spy->starts );
		$this->assertLessThanOrEqual( \EasyQ\Blocks\Render\Query_Resolver::MAX_SEARCH_START, max( $spy->starts ) );
	}
}
