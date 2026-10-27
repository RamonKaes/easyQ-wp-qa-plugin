<?php
/**
 * Tests for the search index.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Search\Basic_Search;
use EasyQ\Search\Indexed_Post;
use EasyQ\Services;

/**
 * @covers \EasyQ\Search\Basic_Search
 * @covers \EasyQ\Search\Search
 */
final class Search_Test extends Test_Case {

	public function test_title_matches_rank_above_content_matches(): void {
		$in_content = $this->ask( $this->user(), 'Something unrelated here', 'This mentions pelican somewhere.' );
		$in_title   = $this->ask( $this->user(), 'All about the pelican', 'Text.' );

		$this->assertSame( array( $in_title->id, $in_content->id ), $this->search_ids( 'pelican' ) );
	}

	public function test_finds_tags_and_links_to_matching_comments(): void {
		$question = $this->ask( $this->user(), 'Generic question title', 'Text.', array( 'raspberry-pi' ) );
		$comment  = $this->comment( $this->user(), $question, $question, 'Try the flamingo approach.' );

		$this->assertSame( array( $question->id ), $this->search_ids( 'raspberry' ) );

		$results = Services::search()->search( 'flamingo', 0, 10 );
		$this->assertSame( 'C', $results[0]->match_type );
		$this->assertSame( $comment->id, $results[0]->match_post_id );
	}

	public function test_finds_questions_by_author_name(): void {
		$author = $this->user();
		wp_update_user(
			array(
				'ID'           => $author->user_id,
				'display_name' => 'Ada Lovelace',
			)
		);
		$question = $this->ask( $author, 'Analytical engine question', 'Text.' );

		$this->assertSame( array( $question->id ), $this->search_ids( 'Ada Lovelace' ) );
	}

	public function test_keeps_word_counts_up_to_date(): void {
		global $wpdb;

		$question = $this->ask( $this->user(), 'Counting the capybara', 'Capybara capybara.', array( 'capybara' ) );
		$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE word = %s', $wpdb->prefix . 'easyq_words', 'capybara' ) );

		$this->assertSame( '1', $row->title_count );
		$this->assertSame( '1', $row->content_count );
		$this->assertSame( '1', $row->tagword_count );
		$this->assertSame( '1', $row->tag_count );

		Services::search()->unindex( $question->id );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE word = %s', $wpdb->prefix . 'easyq_words', 'capybara' ) );

		$this->assertSame( '0', $row->title_count );
		$this->assertSame( '0', $row->content_count );
		$this->assertSame( '0', $row->tag_count );
	}

	public function test_stores_each_word_once_even_with_accents(): void {
		$basic = new Basic_Search();

		$first  = $basic->word_ids( array( 'müller', 'muller' ), create: true );
		$second = $basic->word_ids( array( 'müller' ), create: true );

		$this->assertCount( 2, $first );
		$this->assertNotSame( $first['müller'], $first['muller'] );
		$this->assertSame( $first['müller'], $second['müller'] );
	}

	public function test_handles_special_characters_in_queries(): void {
		$question = $this->ask( $this->user(), 'Discount of 100% guaranteed', 'Text.' );

		$this->assertSame( array( $question->id ), $this->search_ids( '100%' ) );
		$this->assertSame( array(), $this->search_ids( "' OR 1=1 --" ) );
	}

	public function test_empty_queries_find_nothing(): void {
		$this->ask( $this->user() );

		$this->assertSame( array(), $this->search_ids( '' ) );
		$this->assertSame( array(), $this->search_ids( '   ' ) );
	}

	public function test_suspension_skips_indexing(): void {
		$search = Services::search();
		$search->suspend();
		$question = $this->ask( $this->user(), 'Suspended ocelot question', 'Text.' );
		$search->resume();

		$this->assertSame( array(), $this->search_ids( 'ocelot' ) );

		$search->index( $question, $question->id, null );
		$this->assertSame( array( $question->id ), $this->search_ids( 'ocelot' ) );
	}

	public function test_providers_can_be_added(): void {
		$provider = new class() implements \EasyQ\Search\Search_Provider {
			/**
			 * Indexed post IDs.
			 *
			 * @var int[]
			 */
			public array $indexed = array();

			public function id(): string {
				return 'test';
			}

			public function label(): string {
				return 'Test';
			}

			public function index_post( Indexed_Post $post ): void {
				$this->indexed[] = $post->post_id;
			}

			public function unindex_post( int $post_id ): void {
			}

			public function move_post( int $post_id, ?int $category_id ): void {
			}

			public function search( string $query, int $start, int $count, ?int $user_id ): array {
				return array();
			}
		};

		add_filter(
			'easyq_search_providers',
			static function ( array $providers ) use ( $provider ): array {
				$providers[] = $provider;
				return $providers;
			}
		);
		\EasyQ\Settings\Options::update( 'lists', array( 'search_provider' => 'test' ) );

		$question = $this->ask( $this->user(), 'Question for both providers', 'Text.' );

		$this->assertSame( array( $question->id ), $provider->indexed );
		$this->assertSame( $provider, Services::search()->provider() );
		$this->assertArrayHasKey( 'basic', Services::search()->providers() );
	}
}
