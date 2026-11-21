<?php
/**
 * Tests the status, the address and the counting of Q&A pages, as core handles its pages.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Page_Meta;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\Templates;
use EasyQ\Frontend\URLs;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Blocks\Lists
 * @covers \EasyQ\Frontend\Page_Meta
 * @covers \EasyQ\Frontend\URLs
 */
final class Page_State_Test extends Test_Case {

	/**
	 * Status codes sent during the test.
	 *
	 * @var int[]
	 */
	private array $statuses = array();

	public function set_up(): void {
		parent::set_up();
		switch_theme( 'twentytwentyone' );
		add_filter(
			'status_header',
			function ( string $header, int $code ): string {
				$this->statuses[] = $code;
				return $header;
			},
			10,
			2
		);
	}

	/**
	 * Opens an address as WordPress does and renders the page; returns the last status and the head.
	 *
	 * @param string $url Address.
	 * @return array{status: int, head: string, route: string}
	 */
	private function open( string $url ): array {
		Lists::reset();
		$this->statuses = array();
		$this->go_to( $url );
		$router = Block_Context::router();
		( new Templates( $router ) )->template_include( 'index.php' );
		ob_start();
		( new Page_Meta( $router ) )->head();
		$head = (string) ob_get_clean();
		remove_all_filters( 'render_block_easyq/page-title' );
		remove_all_filters( 'render_block_easyq/post-title' );

		return array(
			'status' => ( array() === $this->statuses ? 200 : (int) end( $this->statuses ) ),
			'head'   => $head,
			'route'  => (string) $router->current()?->name,
		);
	}

	public function test_pages_behind_the_last_one_are_not_found(): void {
		Options::update( 'viewing', array( 'page_size_q_as' => 2 ) );
		Options::update( 'lists', array( 'page_size_qs' => 2 ) );
		$author   = $this->user();
		$question = $this->ask( $author );
		foreach ( range( 1, 3 ) as $i ) {
			$this->answer( $this->user(), $question, "Answer number $i of the question." );
			$this->ask( $author, "Another question number $i?" );
		}

		$this->assertSame( 200, $this->open( add_query_arg( Lists::ANSWERS_PAGE_VAR, 2, URLs::question( $question ) ) )['status'] );
		$this->assertSame( 404, $this->open( add_query_arg( Lists::ANSWERS_PAGE_VAR, 3, URLs::question( $question ) ) )['status'], 'The answers have two pages.' );
		$this->assertSame( 200, $this->open( add_query_arg( Lists::MAIN_PAGE_VAR, 2, Routes::url( 'questions' ) ) )['status'] );
		$this->assertSame( 404, $this->open( add_query_arg( Lists::MAIN_PAGE_VAR, 99, Routes::url( 'questions' ) ) )['status'] );
		$this->assertSame( 404, $this->open( add_query_arg( Lists::MAIN_PAGE_VAR, 99, Routes::url( 'search', null, null, array( 'q' => 'question' ) ) ) )['status'] );

		// An empty first page is a page, as an empty archive of an existing term in core.
		$this->assertSame( 200, $this->open( Routes::url( 'unanswered' ) . '&by=unselected' )['status'] );
		$this->assertSame( 200, $this->open( Routes::url( 'search', null, null, array( 'q' => 'nothingmatchesthis' ) ) )['status'] );
	}

	public function test_tags_no_question_uses_are_not_found(): void {
		$this->ask( $this->user(), 'A question about widgets', 'Text.', array( 'widgets', '50%41' ) );

		$this->assertSame( 'tag', $this->open( Routes::url( 'tag', 'widgets' ) )['route'] );
		$this->assertSame( 'not_found', $this->open( Routes::url( 'tag', 'gadgets' ) )['route'] );
		$this->assertSame( 404, $this->open( Routes::url( 'tag', 'gadgets' ) )['status'] );

		// Percent signs in a tag and a search stay, as core keeps the search query.
		$this->open( Routes::url( 'tag', '50%41' ) );
		$this->assertSame( '50%41', Block_Context::router()->current()?->tag() );
		$this->go_to( Routes::url( 'search', null, null, array( 'q' => rawurlencode( '50%ab 0x%41' ) ) ) );
		$this->assertStringContainsString( '50%ab 0x%41', \EasyQ\Blocks\Render\Widgets::title_of_page() );
	}

	public function test_the_canonical_address_names_the_page_of_the_answers(): void {
		Options::update( 'viewing', array( 'page_size_q_as' => 1 ) );
		$question = $this->ask( $this->user() );
		$this->answer( $this->user(), $question, 'The first answer here.' );
		$this->answer( $this->user(), $question, 'The second answer here.' );

		$this->assertStringContainsString( 'rel="canonical" href="' . esc_url( URLs::question( $question ) ) . '"', $this->open( URLs::question( $question ) )['head'] );
		$this->assertStringContainsString( 'rel="canonical" href="' . esc_url( add_query_arg( Lists::ANSWERS_PAGE_VAR, 2, URLs::question( $question ) ) ) . '"', $this->open( add_query_arg( Lists::ANSWERS_PAGE_VAR, 2, URLs::question( $question ) ) )['head'] );
	}

	public function test_slugs_are_censored_and_cut_between_characters(): void {
		Options::update( 'posting', array( 'block_bad_words' => 'darn' ) );
		$this->assertStringNotContainsString( 'darn', URLs::slug( 'Darn widgets do not work' ) );

		Options::update( 'viewing', array( 'q_urls_title_length' => 10 ) );
		$slug = URLs::slug( str_repeat( '日本語のテスト ', 6 ) );
		$text = rawurldecode( $slug );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ), $slug );
		$this->assertSame( '日本語のテスト', $text, 'Ten characters, cut at the last hyphen.' );
		$this->assertDoesNotMatchRegularExpression( '/%(?![0-9a-f]{2})/', $slug );
		$this->assertSame( strtolower( $slug ), $slug );
		$this->assertSame( 'how-do-i', URLs::slug( 'How do I configure the widget?' ) );
	}

	public function test_a_view_without_a_user_agent_counts_as_from_a_person(): void {
		$question = $this->ask( $this->user() );
		$meta     = new Page_Meta( Block_Context::router() );
		Block_Context::router()->set_current( new \EasyQ\Frontend\Route( 'question', $question ) );
		unset( $_SERVER['HTTP_USER_AGENT'] );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.77';

		$meta->count_view();

		$this->assertSame( 1, $this->post( $question->id )->views, 'As qa_is_human_probably(): no agent, no reason to doubt.' );
		Block_Context::router()->set_current( null );
	}

	public function test_the_author_viewing_the_own_question_is_not_counted_and_other_pages_keep_their_robots(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$meta     = new Page_Meta( Block_Context::router() );
		Block_Context::router()->set_current( new \EasyQ\Frontend\Route( 'question', $question ) );
		wp_set_current_user( (int) $author->user_id );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Firefox/140.0';
		$_SERVER['REMOTE_ADDR']     = '192.0.2.78';

		$meta->count_view();
		$this->assertSame( 0, $this->post( $question->id )->views );

		Block_Context::router()->set_current( null );
		$this->assertSame( array( 'max-image-preview' => 'large' ), $meta->robots( array( 'max-image-preview' => 'large' ) ) );
	}

	public function test_views_count_people_once_in_a_row_and_store_one_value_per_question(): void {
		global $wpdb;
		$question = $this->ask( $this->user() );
		$meta     = new Page_Meta( Block_Context::router() );
		Block_Context::router()->set_current( new \EasyQ\Frontend\Route( 'question', $question ) );
		$view    = static function ( string $agent, string $ip, array $headers = array() ) use ( $meta ): void {
			$_SERVER['HTTP_USER_AGENT'] = $agent;
			$_SERVER['REMOTE_ADDR']     = $ip;
			foreach ( $headers as $name => $value ) {
				$_SERVER[ $name ] = $value;
			}
			$meta->count_view();
			foreach ( array_keys( $headers ) as $name ) {
				unset( $_SERVER[ $name ] );
			}
		};
		$options = static fn (): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$browser = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';

		$view( $browser, '192.0.2.1' );
		$view( $browser, '192.0.2.1' );
		$this->assertSame( 1, $this->post( $question->id )->views, 'The same address twice in a row counts once.' );

		$view( 'Googlebot/2.1 (+http://www.google.com/bot.html)', '192.0.2.2' );
		$view( $browser, '192.0.2.3', array( 'HTTP_SEC_PURPOSE' => 'prefetch' ) );
		$this->assertSame( 1, $this->post( $question->id )->views, 'Crawlers and prefetches do not count.' );

		$before = $options();
		foreach ( range( 10, 59 ) as $i ) {
			$view( $browser, "192.0.2.$i" );
		}
		$this->assertSame( 51, $this->post( $question->id )->views );
		$this->assertSame( $before, $options(), 'Fifty visitors add no rows to the options table.' );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}
}
