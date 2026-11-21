<?php
/**
 * Tests for the RSS feeds.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Feeds\Feeds;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\URLs;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;

/**
 * @covers \EasyQ\Feeds\Feeds
 */
final class Feeds_Test extends Test_Case {

	/**
	 * Feeds under test.
	 *
	 * @var Feeds
	 */
	private Feeds $feeds;

	public function set_up(): void {
		parent::set_up();
		$this->feeds = new Feeds( Block_Context::router() );
	}

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		parent::tear_down();
	}

	public function test_lists_recent_questions_with_full_text(): void {
		$question = $this->ask( $this->user(), 'Feed question <b>one</b>', 'Body with <em>markup</em> & more.' );

		$channel = $this->feeds->channel( 'questions', null, new Actor() );

		$this->assertNotNull( $channel );
		$this->assertSame( 'Recent questions', $channel['title'] );
		$this->assertSame( Routes::url( 'questions' ), $channel['link'] );
		$this->assertCount( 1, $channel['items'] );
		$this->assertSame( URLs::question( $question ), $channel['items'][0]['link'] );
		$this->assertStringContainsString( 'more.', (string) $channel['items'][0]['description'] );
		$this->assertSame( $question->created, $channel['items'][0]['date'] );
	}

	public function test_leaves_out_the_content_without_full_text(): void {
		Options::update( 'feeds', array( 'feed_full_text' => false ) );
		$this->ask( $this->user() );

		$channel = $this->feeds->channel( 'questions', null, new Actor() );

		$this->assertNotNull( $channel );
		$this->assertArrayHasKey( 'description', $channel['items'][0] );
		$this->assertNull( $channel['items'][0]['description'] );
	}

	public function test_limits_the_number_of_items(): void {
		Options::update( 'feeds', array( 'feed_number_items' => 2 ) );
		$author = $this->user();
		$this->ask( $author, 'First feed question' );
		$this->ask( $author, 'Second feed question' );
		$this->ask( $author, 'Third feed question' );

		$this->assertCount( 2, $this->feeds->channel( 'questions', null, new Actor() )['items'] ?? array() );
	}

	public function test_labels_activity_but_not_new_questions(): void {
		$question = $this->ask( $this->user(), 'Activity feed question' );
		$answer   = $this->answer( $this->user(), $question, 'An answer for the feed.' );

		$channel = $this->feeds->channel( 'activity', null, new Actor() );

		$this->assertNotNull( $channel );
		$this->assertCount( 1, $channel['items'], 'One item per question.' );
		$this->assertSame( 'Activity feed question (answered)', $channel['items'][0]['title'] );
		$this->assertSame( URLs::post( $answer ), $channel['items'][0]['link'] );
		$this->assertStringContainsString( 'An answer for the feed.', (string) $channel['items'][0]['description'] );

		$this->ask( $this->user(), 'Only asked' );
		$questions = $this->feeds->channel( 'qa', null, new Actor() );
		$this->assertSame( 'Only asked', $questions['items'][0]['title'] ?? '' );
	}

	public function test_respects_the_feed_settings(): void {
		$this->assertNull( $this->feeds->channel( 'hot', null, new Actor() ), 'Hot questions are off by default, as in Q2A.' );

		Options::update( 'feeds', array( 'feed_for_hot' => true ) );
		$this->assertNotNull( $this->feeds->channel( 'hot', null, new Actor() ) );

		Options::update( 'feeds', array( 'feed_for_questions' => false ) );
		$this->assertNull( $this->feeds->channel( 'questions', null, new Actor() ) );
		$this->assertNull( $this->feeds->channel( 'unknown', null, new Actor() ) );
	}

	public function test_lists_questions_of_a_category(): void {
		$cooking = (int) Services::categories()->create( null, 'Cooking' );
		$garden  = (int) Services::categories()->create( null, 'Garden' );
		$this->ask( $this->user(), 'Pasta question', 'x', array(), $cooking );
		$this->ask( $this->user(), 'Roses question', 'x', array(), $garden );

		$channel = $this->feeds->channel( 'questions', 'cooking', new Actor() );

		$this->assertNotNull( $channel );
		$this->assertSame( 'Recent questions in Cooking', $channel['title'] );
		$this->assertSame( array( 'Pasta question' ), array_column( $channel['items'], 'title' ) );
		$this->assertSame( 'Cooking', $channel['items'][0]['category'] );

		$this->assertNull( $this->feeds->channel( 'questions', 'missing', new Actor() ) );

		Options::update( 'feeds', array( 'feed_per_category' => false ) );
		$this->assertNull( $this->feeds->channel( 'questions', 'cooking', new Actor() ) );
	}

	public function test_tag_and_search_feeds_need_their_argument(): void {
		Options::update(
			'feeds',
			array(
				'feed_for_tag_qs' => true,
				'feed_for_search' => true,
			)
		);
		$this->ask( $this->user(), 'Tagged feed question', 'Searchable content', array( 'pasta' ) );

		$this->assertNull( $this->feeds->channel( 'tag', null, new Actor() ) );
		$this->assertSame( array( 'Tagged feed question' ), array_column( $this->feeds->channel( 'tag', 'pasta', new Actor() )['items'] ?? array(), 'title' ) );
		$this->assertSame( 'Questions tagged “pasta”', $this->feeds->channel( 'tag', 'pasta', new Actor() )['title'] ?? '' );

		$this->assertNull( $this->feeds->channel( 'search', '', new Actor() ) );
		$this->assertSame( array( 'Tagged feed question' ), array_column( $this->feeds->channel( 'search', 'searchable', new Actor() )['items'] ?? array(), 'title' ) );
	}

	public function test_leaves_out_hidden_questions(): void {
		$author = $this->user();
		$this->ask( $author, 'Queued feed question', 'x', array(), null, true );

		$this->assertSame( array(), $this->feeds->channel( 'questions', null, new Actor() )['items'] ?? null );
		$this->assertSame( array(), $this->feeds->channel( 'activity', null, new Actor() )['items'] ?? null );
	}

	public function test_the_search_feed_leaves_out_hidden_questions_a_provider_returns(): void {
		$author  = $this->user();
		$visible = $this->ask( $author, 'A visible question about the leak' );
		$hidden  = $this->ask( $author, 'A hidden question about the leak' );
		Services::post_service()->set_question_status( Actor::system(), $hidden, \EasyQ\Posts\Post::STATUS_HIDDEN );

		// A search plugin keeps its own index and may still return what was hidden since.
		$leaky = new class( array( $hidden->id, $visible->id ) ) implements \EasyQ\Search\Search_Provider {
			/**
			 * @param int[] $ids Question IDs it returns.
			 */
			public function __construct( private array $ids ) {
			}
			public function id(): string {
				return 'leaky';
			}
			public function label(): string {
				return 'Leaky';
			}
			public function index_post( \EasyQ\Search\Indexed_Post $post ): void {
			}
			public function unindex_post( int $post_id ): void {
			}
			public function move_post( int $post_id, ?int $category_id ): void {
			}
			public function search( string $query, int $start, int $count, ?int $user_id ): array {
				return array_map( static fn ( int $id ): \EasyQ\Search\Search_Result => new \EasyQ\Search\Search_Result( $id, 1.0, 'Q', $id ), $this->ids );
			}
		};
		add_filter( 'easyq_search_providers', static fn ( array $providers ): array => array_merge( $providers, array( $leaky ) ) );
		Services::reset();
		Options::update( 'lists', array( 'search_provider' => 'leaky' ) );
		Options::update( 'feeds', array( 'feed_for_search' => true ) );

		$items = $this->feeds->channel( 'search', 'leak', new Actor() )['items'] ?? null;
		$this->assertSame( array( 'A visible question about the leak' ), array_column( (array) $items, 'title' ) );
	}

	public function test_is_not_available_when_visitors_may_not_view_the_qa_area(): void {
		Options::update( 'permissions', array( 'permit_view_q_page' => Permits::USERS ) );

		$this->assertNull( $this->feeds->channel( 'questions', null, new Actor() ) );
	}

	public function test_builds_valid_rss(): void {
		$this->ask( $this->user(), 'Fish & chips <script>', 'Content' );

		$xml = simplexml_load_string( $this->feeds->xml( $this->feeds->channel( 'questions', null, new Actor() ) ?? array() ) );

		$this->assertNotFalse( $xml );
		$this->assertSame( '2.0', (string) $xml['version'] );
		$this->assertSame( 'Fish & chips <script>', (string) $xml->channel->item[0]->title );
		$this->assertNotSame( '', (string) $xml->channel->item[0]->pubDate );
	}

	public function test_control_characters_keep_the_feed_valid(): void {
		global $wpdb;
		$question = $this->ask( $this->user(), 'A title with a control character', 'Content' );
		$wpdb->update( \EasyQ\Database\Schema::table( 'posts' ), array( 'title' => "Bell \x07 and \x01 here" ), array( 'id' => $question->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();

		$xml = simplexml_load_string( $this->feeds->xml( $this->feeds->channel( 'questions', null, new Actor() ) ?? array() ) );

		$this->assertNotFalse( $xml );
		$this->assertSame( 'Bell  and  here', (string) $xml->channel->item[0]->title );
	}

	public function test_has_the_elements_and_hooks_of_the_feeds_of_core(): void {
		$author = $this->user();
		wp_update_user(
			array(
				'ID'           => (int) $author->user_id,
				'display_name' => 'Tom & Jerry',
			)
		);
		$this->assertSame( 'Tom &amp; Jerry', get_userdata( (int) $author->user_id )->display_name, 'Core stores the name with entities.' );
		$this->ask( $author, 'A question for the feed', 'Content' );
		add_action( 'rss2_ns', static fn () => print( ' xmlns:test="https://example.org/ns"' ) );
		add_action( 'rss2_head', static fn () => print( '<test:head>1</test:head>' ) );
		add_action( 'rss2_item', static fn () => print( '<test:item>1</test:item>' ) );

		$xml = simplexml_load_string( $this->feeds->xml( $this->feeds->channel( 'questions', null, new Actor() ) ?? array() ) );

		$this->assertNotFalse( $xml );
		$this->assertNotSame( '', (string) $xml->channel->lastBuildDate );
		$this->assertSame( 'hourly', (string) $xml->channel->children( 'http://purl.org/rss/1.0/modules/syndication/' )->updatePeriod );
		$this->assertSame( '1', (string) $xml->channel->children( 'https://example.org/ns' )->head );
		$this->assertSame( '1', (string) $xml->channel->item[0]->children( 'https://example.org/ns' )->item );
		// The author, as `feed-rss2.php` prints it, as written.
		$this->assertSame( 'Tom & Jerry', (string) $xml->channel->item[0]->children( 'http://purl.org/dc/elements/1.1/' )->creator );
	}

	public function test_answers_a_reader_who_has_the_feed_with_not_modified(): void {
		$this->ask( $this->user(), 'A question for the feed', 'Content' );
		$statuses = array();
		add_filter(
			'status_header',
			static function ( string $header, int $code ) use ( &$statuses ): string {
				$statuses[] = $code;
				return $header;
			},
			10,
			2
		);
		$open = function ( string $etag = '', string $since = '' ): string {
			$_SERVER['HTTP_IF_NONE_MATCH']     = $etag;
			$_SERVER['HTTP_IF_MODIFIED_SINCE'] = $since;
			// The request runs `ignore_conditional_get()` on `parse_request`.
			$this->go_to( Feeds::url( 'questions' ) );
			ob_start();
			$this->feeds->render();
			return (string) ob_get_clean();
		};

		$body = $open();
		$this->assertStringContainsString( '<rss', $body );

		$this->assertSame( '', $open( '"' . md5( $body ) . '"' ) );
		$this->assertSame( 304, end( $statuses ) );
		$this->assertSame( '', $open( '', gmdate( 'D, d M Y H:i:s', time() + 60 ) . ' GMT' ) );
		$this->assertSame( 304, end( $statuses ) );
		$this->assertStringContainsString( '<rss', $open( '"other"' ) );
		unset( $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
	}

	public function test_the_discovery_link_builds_no_items(): void {
		global $wpdb;
		Options::update( 'feeds', array( 'feed_full_text' => true ) );
		$measure = function ( int $questions ) use ( $wpdb ): int {
			for ( $i = 0; $i < $questions; $i++ ) {
				$this->ask( $this->user(), "Question number $i for the feed?" );
			}
			Block_Context::router()->set_current( new Route( 'questions' ) );
			$before = $wpdb->num_queries;
			ob_start();
			$this->feeds->discovery_link();
			$link = (string) ob_get_clean();
			$this->assertStringContainsString( 'rel="alternate"', $link );

			return $wpdb->num_queries - $before;
		};

		// A setting may be loaded once; the items are never.
		$this->assertLessThan( 3, $measure( 2 ), 'The title needs no query of the items.' );
		$this->assertLessThan( 3, $measure( 25 ) );
	}

	public function test_has_addresses_below_the_base_slug(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->assertSame( home_url( '/questions/feed/questions/cooking/baking/' ), Feeds::url( 'questions', 'cooking/baking' ) );
		$this->assertArrayHasKey( '^questions/feed/(questions|hot|unanswered|answers|comments|activity|qa|tag|search)(?:/(.+?))?/?$', Routes::rewrite_rules() );

		$this->set_permalink_structure( '' );
		$this->assertSame( home_url( '/?feed=easyq&easyq_route=feed&easyq_arg=tag&easyq_section=pasta' ), Feeds::url( 'tag', 'pasta' ) );
	}

	public function test_resolves_feed_requests(): void {
		$route = Block_Context::router()->resolve( 'feed', 'questions', 'cooking/baking' );
		$this->assertSame( 'feed', $route->name );
		$this->assertSame( 'questions', $route->subject );
		$this->assertSame( 'cooking/baking', $route->section );

		$this->assertSame( 'not_found', Block_Context::router()->resolve( 'feed', 'nope', null )->name );
	}

	public function test_links_q_and_a_pages_to_their_feed(): void {
		$category = Services::categories()->find( (int) Services::categories()->create( null, 'Cooking' ) );
		$this->assertNotNull( $category );

		// As in Question2Answer: the start page has the feed of questions and answers, the questions page those of its recent and hot questions.
		$this->assertSame( array( 'qa', null ), Feeds::for_route( new Route( 'home' ) ) );
		$this->assertSame( array( 'questions', null ), Feeds::for_route( new Route( 'questions' ) ) );
		$this->assertSame( array( 'questions', null ), Feeds::for_route( new Route( 'questions' ), '', 'recent' ) );
		$this->assertNull( Feeds::for_route( new Route( 'questions' ), '', 'hot' ), 'The hot feed is off by default.' );
		$this->assertNull( Feeds::for_route( new Route( 'questions' ), '', 'votes' ) );
		Options::update( 'feeds', array( 'feed_for_hot' => true ) );
		$this->assertSame( array( 'hot', null ), Feeds::for_route( new Route( 'questions' ), '', 'hot' ) );
		Options::update( 'feeds', array( 'feed_for_hot' => false ) );
		$this->assertSame( array( 'questions', 'cooking' ), Feeds::for_route( new Route( 'category', $category ) ) );
		$this->assertSame( array( 'activity', null ), Feeds::for_route( new Route( 'activity' ) ) );
		$this->assertNull( Feeds::for_route( new Route( 'hot' ) ), 'Disabled by default.' );
		$this->assertNull( Feeds::for_route( new Route( 'ask' ) ) );

		Block_Context::router()->set_current( new Route( 'home' ) );
		ob_start();
		$this->feeds->discovery_link();
		$html = (string) ob_get_clean();

		// As `feed_links_extra()` titles the feeds of core: the site, a separator, the feed.
		$this->assertStringContainsString( 'rel="alternate" type="application/rss+xml" title="' . esc_attr( get_bloginfo( 'name' ) ) . ' &raquo; Recent questions and answers Feed"', $html );
	}

	public function test_does_not_send_cache_headers_of_wordpress_posts(): void {
		Block_Context::router()->set_current( new Route( 'feed', 'questions' ) );

		$headers = $this->feeds->headers(
			array(
				'ETag'          => '"x"',
				'Last-Modified' => 'Mon, 01 Jan 2024 00:00:00 GMT',
			)
		);

		$this->assertArrayNotHasKey( 'ETag', $headers );
		$this->assertFalse( $headers['Last-Modified'] );
	}

	public function test_answers_unknown_feeds_with_not_found(): void {
		Block_Context::router()->set_current( new Route( 'feed', 'hot' ) );

		ob_start();
		$this->feeds->render();
		$output = (string) ob_get_clean();

		$this->assertSame( 'This feed does not exist.', $output );
	}

	public function test_a_feed_asks_for_at_most_100_items(): void {
		remove_all_filters( 'sanitize_option_' . Options::option_name( 'feeds' ) );
		Options::update(
			'feeds',
			array(
				'feed_number_items' => 500,
				'feed_for_search'   => true,
			)
		);
		$spy = new class() implements \EasyQ\Search\Search_Provider {
			/**
			 * Number of results asked for.
			 *
			 * @var int
			 */
			public int $count = 0;
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
				$this->count = $count;
				return array();
			}
		};
		add_filter( 'easyq_search_providers', static fn ( array $providers ): array => array_merge( $providers, array( $spy ) ) );
		Options::update( 'lists', array( 'search_provider' => 'spy' ) );
		Services::reset();

		$this->assertNotNull( $this->feeds->channel( 'search', 'widget', new Actor() ) );
		$this->assertSame( 100, $spy->count );
	}

	public function test_the_pages_without_answers_and_of_answers_have_their_feeds(): void {
		$this->assertSame( array( 'unanswered', null ), Feeds::for_route( new Route( 'unanswered' ) ) );
		Options::update( 'feeds', array( 'feed_for_qa' => true ) );
		$this->assertSame( array( 'answers', null ), Feeds::for_route( new Route( 'answers' ) ) );

		$this->assertSame( 'Questions without answers', $this->feeds->channel( 'unanswered', null, new Actor() )['title'] ?? null );
	}

	public function test_no_feed_is_linked_or_sent_where_visitors_may_not_read_or_a_page_has_none(): void {
		Options::update( 'permissions', array( 'permit_view_q_page' => \EasyQ\Users\Permits::USERS ) );
		Block_Context::router()->set_current( new Route( 'home' ) );
		ob_start();
		$this->feeds->discovery_link();
		$this->assertSame( '', (string) ob_get_clean() );

		Options::update( 'permissions', array( 'permit_view_q_page' => \EasyQ\Users\Permits::ALL ) );
		Block_Context::router()->set_current( new Route( 'questions' ) );
		ob_start();
		$this->feeds->render();
		$this->assertSame( 'This feed does not exist.', (string) ob_get_clean() );
	}

	public function test_items_name_the_category_of_their_question(): void {
		Options::update( 'general', array( 'tags_or_categories' => 'c' ) );
		$category = Services::categories()->create( null, 'Gardening' );
		$this->assertIsInt( $category );
		$this->ask( $this->user(), 'A question about roses', 'Text.', array(), $category );

		$channel = $this->feeds->channel( 'questions', null, new Actor() );
		$this->assertNotNull( $channel );
		$this->assertStringContainsString( '<category>Gardening</category>', $this->feeds->xml( $channel ) );
	}
}
