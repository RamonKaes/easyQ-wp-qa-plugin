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

	public function test_has_addresses_below_the_base_slug(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->assertSame( home_url( '/questions/feed/questions/cooking/baking/' ), Feeds::url( 'questions', 'cooking/baking' ) );
		$this->assertArrayHasKey( '^questions/feed/(questions|hot|unanswered|answers|comments|activity|qa|tag|search)(?:/(.+?))?/?$', Routes::rewrite_rules() );

		$this->set_permalink_structure( '' );
		$this->assertSame( home_url( '/?feed=easyq&easyq_route=feed&easyq_arg=tag&easyq_section=pasta' ), Feeds::url( 'tag', 'pasta' ) );
	}

	public function test_resolves_feed_requests(): void {
		$route = Block_Context::router()->resolve( 'feed', 'questions', 'cooking%2Fbaking' );
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

		$this->assertStringContainsString( 'rel="alternate" type="application/rss+xml" title="Recent questions and answers"', $html );
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
}
